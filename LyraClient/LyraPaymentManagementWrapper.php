<?php
/*************************************************************************************/
/*      Copyright (c) Franck Allimant, CQFDev                                        */
/*      email : thelia@cqfdev.fr                                                     */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE      */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded\LyraClient;

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\TheliaProcessException;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Tools\URL;

/**
 * A wrapper around CreatePayment service to manage bith Javascript Client and PCI-DSS calls
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 27/05/2019 17:33
 */

class LyraPaymentManagementWrapper extends LyraClientWrapper
{
    /** The platform answers at most this many transactions for an order (PSP_015 beyond). */
    protected const ORDER_GET_LIMIT = 30;

    /** Two platform calls of up to 45 seconds each fit in this time. */
    protected const ORDER_LOCK_TTL = 120.0;

    /** How long an operation the shop runs on its own waits for a locked order before giving up. */
    protected const ORDER_LOCK_WAIT = 15.0;

    protected ?LockFactory $lockFactory;

    /**
     * @var boolean
     */
    protected $oneClickEnabled;
    /**
     * @var Tlog
     */
    protected $log;
    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @param LockFactory|null $lockFactory the framework's lock factory. It spans every node of the
     *                                      shop only when LOCK_DSN names a network store (redis,
     *                                      pdo); the default semaphore or flock store holds one
     *                                      host. Without it, a lock on this node's file system,
     *                                      built the first time an operation asks for it: the
     *                                      notification and the payment never do.
     */
    public function __construct(EventDispatcherInterface $dispatcher, ?LockFactory $lockFactory = null)
    {
        parent::__construct();

        $this->oneClickEnabled = (bool)(PayzenEmbedded::getConfigValue('allow_one_click_payments'));

        $this->log = Tlog::getInstance();
        $this->dispatcher = $dispatcher;
        $this->lockFactory = $lockFactory;
    }

    /**
     * One operation at a time on the money of an order: a refund and a refresh reading the same
     * ledger would both pass its checks, and a capture would cross a cancellation. One lock per
     * order and per shop, since the nodes of one host may serve several shops. The caller releases it.
     *
     * A wrapper built without the framework's factory (the notification, the payment) locks on
     * this node's file system: said in the log, since such a lock holds one server only.
     *
     * @param bool $blocking wait for the order, up to ORDER_LOCK_WAIT seconds, instead of refusing
     *                       it at once: for an operation the shop runs on its own, such as the
     *                       capture after picking, whose refusal would be read as a failed payment.
     *                       Bounded on purpose: the component's own blocking wait has no limit on
     *                       a semaphore or flock store, and a web request cannot wait that long.
     *
     * @throws TheliaProcessException when another operation holds the order, or the lock store fails
     */
    protected function acquireOrderLock(Order $order, bool $blocking = false): LockInterface
    {
        if (null === $this->lockFactory) {
            $this->log->addWarning('PayZen order lock: no lock factory given, locking on this server\'s file system only.');

            $this->lockFactory = new LockFactory(new FlockStore());
        }

        $lock = $this->lockFactory->createLock(
            'payzen-embedded-refund-' . PayzenEmbedded::shopMarker() . '-' . $order->getId(),
            self::ORDER_LOCK_TTL
        );

        $deadline = microtime(true) + ($blocking ? self::ORDER_LOCK_WAIT : 0.0);

        try {
            $acquired = $lock->acquire();

            while (!$acquired && microtime(true) < $deadline) {
                usleep(200_000);
                $acquired = $lock->acquire();
            }
        } catch (LockException $storeFailure) {
            // A store that cannot answer (redis down, no semaphore left) is not an order held by
            // someone: said as such, and read by the caller as any refusal of the module. The cause
            // stays in the log by its class only: a store's own message may quote its DSN.
            $this->log->addError(sprintf('PayZen order lock: store failure on order %d: %s caused by %s', $order->getId(), $storeFailure::class, get_debug_type($storeFailure->getPrevious())));

            throw new TheliaProcessException(
                Translator::getInstance()->trans('The order could not be locked: %message', ['%message' => $storeFailure->getMessage()], PayzenEmbedded::DOMAIN_NAME),
                0,
                $storeFailure
            );
        }

        if (!$acquired) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans('A refund of this order is already running.', [], PayzenEmbedded::DOMAIN_NAME)
            );
        }

        return $lock;
    }

    /**
     * Give the order back once the operation is over, whatever the store says: the operation's own
     * result (money refunded, payment captured) is what the caller has to read, and a lock the store
     * cannot release expires on its own after ORDER_LOCK_TTL.
     */
    protected function releaseOrderLock(LockInterface $lock, Order $order): void
    {
        try {
            $lock->release();
        } catch (LockException $releaseFailure) {
            $this->log->addError(sprintf('PayZen order lock: release failed on order %d, it expires on its own: %s', $order->getId(), $releaseFailure::class));
        }
    }

    /**
     * Build CreatePayement web service input parameters from the givent order, and call the service.
     *
     * @param Order $order
     *
     * @return array CreatePayement response
     *
     * @throws LyraException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function sendCreatePayementRequest(Order $order)
    {
        $currency = $order->getCurrency();
        $customer = $order->getCustomer();

        // A SmartForm asked to register the card would lose its wallets, see PaymentFormAction.
        $formAction = PaymentFormAction::resolve($this->oneClickEnabled, PayzenEmbedded::isSmartFormEnabled());

        // Request parameters (see https://payzen.io/en-EN/rest/V4.0/api/playground.html?ws=Charge/CreatePayment)
        $store = [
            "amount" => RefundAmount::fromMajor((string) $order->getTotalAmount(), $currency->getCode()),
            'contrib' => 'Thelia version ' . ConfigQuery::read('thelia_version'),
            'currency' => strtoupper($currency->getCode()),
            'orderId' => $order->getRef(),
            'formAction' => $formAction,

            'customer' => [
                'email' => $customer->getEmail(),
                'reference' => $customer->getRef()
            ],

            'strongAuthentication' => PayzenEmbedded::getConfigValue('strong_authentication', 'AUTO'),
            // Names this shop on the platform: what it lists or notifies is checked against it.
            'metadata' => [TransactionOutcome::SHOP_MARKER_KEY => PayzenEmbedded::shopMarker()],
            'ipnTargetUrl' => URL::getInstance()->absoluteUrl('/payzen-embedded/ipn-callback'),

            'transactionOptions' => [
                'cardOptions' => [
                    'captureDelay' => PayzenEmbedded::getConfigValue('capture_delay', 0),
                    'manualValidation' => PayzenEmbedded::getConfigValue('validation_mode', null) ?: null,
                    'paymentSource' => PayzenEmbedded::getConfigValue('payment_source', null) ?: null
                ]
            ],
        ];

        // Without this, the platform offers every payment method activated on the shop contract.
        if ([] !== $excludedPaymentMethods = PayzenEmbedded::getExcludedPaymentMethods()) {
            $store['excludedPaymentMethods'] = $excludedPaymentMethods;
        }

        // Add 1-click payment token if we have one, and if it is allowed
        if ($this->oneClickEnabled && (null !== $tokenData = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()))) {
            $store['paymentMethodToken'] = $tokenData->getPaymentToken();
        }

        return $this->post("V4/Charge/CreatePayment", $store);
    }

    /**
     * Process a CreatePayment response and update the order accordingly.
     *
     */
    public function processPaymentResponse($response)
    {
        $status = self::PAYMENT_STATUS_NOT_PAID;

        // Be sure to have transaction data.
        if (isset($response['transactions'])) {
            $orderRef = $response['orderDetails']['orderId'];

            $this->log->addInfo(Translator::getInstance()->trans("PayZen response received for order %ref.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));

            // An order paid with another module is never moved on a PayZen notification, whatever
            // reference it names: a shop switching payment modules keeps its old references.
            if (null !== ($order = $this->getOrderByRef($orderRef)) && PayzenEmbedded::getModuleId() !== (int) $order->getPaymentModuleId()) {
                $this->log->addWarning(sprintf('PayZen notification for order %s ignored: the order was not paid with PayZen.', $orderRef));
                $order = null;
            }

            if (null !== $order) {
                // A notification may carry several transactions: the debit of each attempt, and the
                // credit of a refund. Each one is read. The platform is answered about the transaction
                // the order stands on once they are all recorded, not about the last one in the list.
                $lastStatus = self::PAYMENT_STATUS_NOT_PAID;

                // The space is named once, at the top level of the notification; the marker travels
                // on each transaction. What is not this shop's, for this order, is left out.
                $provenance = new NotificationProvenance(
                    strtoupper(trim((string) ($response['orderDetails']['mode'] ?? ''))),
                    PayzenEmbedded::shopMarker(),
                    (string) $order->getTransactionRef(),
                    PayzenEmbedded::platformMode()
                );

                foreach ($response['transactions'] as $answer) {
                    if (!\is_array($answer)) {
                        continue;
                    }

                    $incoming = TransactionOutcome::fromAnswer($answer);

                    if (!$provenance->accepts($incoming)) {
                        $this->log->addWarning(sprintf('PayZen notification for order %s ignored: transaction %s belongs to another shop or space, or gives money back on another transaction.', $orderRef, $incoming->uuid));
                        continue;
                    }

                    $lastStatus = $this->processOrderStatus($order, $answer);
                }

                $governing = $this->governingTransaction($order);
                $status = null !== $governing ? $this->paymentStatusOf($governing) : $lastStatus;
            }

            $this->log->info(Translator::getInstance()->trans("PayZen payment response for order %ref processing teminated.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));
        }

        return $status;
    }

    /**
     * Process PayZen response and update order status
     *
     * @param Order $order
     * @param $answer
     * @return int
     * @throws \Exception
     */
    protected function processOrderStatus(Order $order, $answer)
    {
        $status = self::PAYMENT_STATUS_NOT_PAID;

        $orderStatus = $answer['status'];
        $transactionUuid = $answer['uuid'];

        // An order can carry one transaction per payment attempt, and the platform notifies each of
        // them on its own schedule: applied in the order they arrive, the refusal of an attempt the
        // shopper gave up on would cancel an order that is paid for.
        $incoming = TransactionOutcome::fromAnswer($answer);

        // A refund is a transaction of its own that gives money back: it is recorded, and it never
        // moves the order. The refund service settles the order when the shop asks for the refund.
        if ($incoming->isCredit()) {
            $this->updateTransactionHistory($answer, $order);

            $this->log->addInfo(
                Translator::getInstance()->trans(
                    "Order %ref: refund transaction %uuid (%status) recorded.",
                    ['%ref' => $order->getRef(), '%uuid' => $transactionUuid, '%status' => $orderStatus],
                    PayzenEmbedded::DOMAIN_NAME
                )
            );

            // A refund that was pending when the shop asked for it settles the order once the
            // platform confirms it and every refund of the order is confirmed too.
            $ledger = (new TransactionHistoryReader())->ledgerOf($order);

            if ($incoming->isPaid() && $ledger->isFullyRefunded()) {
                $this->setOrderStatus($order, OrderStatusQuery::getRefundedStatus());
            }

            return $this->paymentStatusOf($incoming);
        }

        if (!(new NotificationArbiter())->accepts($incoming, $this->governingTransaction($order))) {
            $this->log->addInfo(
                Translator::getInstance()->trans(
                    "Order %ref: transaction %uuid (%status) is not the one the order stands on, the order is left as it is.",
                    ['%ref' => $order->getRef(), '%uuid' => $transactionUuid, '%status' => $orderStatus],
                    PayzenEmbedded::DOMAIN_NAME
                )
            );

            return $this->paymentStatusOf($incoming);
        }

        // Update transaction history
        $this->updateTransactionHistory($answer, $order);

        // Store the transaction ID
        $event = new OrderEvent($order);
        $event->setTransactionRef($transactionUuid);
        $this->dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_TRANSACTION_REF);

        if ($orderStatus === 'PAID') {
            $this->log->addInfo(Translator::getInstance()->trans("Order %ref payment was successful.", ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME));

            // Payment OK !
            $this->setOrderStatus($order, OrderStatusQuery::getPaidStatus());

            $status = self::PAYMENT_STATUS_PAID;
        } else if ($orderStatus === 'UNPAID') {
            $this->log->addInfo(Translator::getInstance()->trans("Order %ref payment was not successful.", ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME));

            // Cancel the order
            $this->setOrderStatus($order, OrderStatusQuery::getCancelledStatus());
        } else if ($orderStatus === 'RUNNING') {
            $this->log->addInfo(Translator::getInstance()->trans("Order %ref payment is in progress (%status).", ['%status' => $orderStatus, '%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME));

            // Consider order as paid.
            $this->setOrderStatus($order, OrderStatusQuery::getPaidStatus());

            $status = self::PAYMENT_STATUS_IN_PROGRESS;
        } else {
            // This payment is not supported.
            $this->log->addInfo(Translator::getInstance()->trans("Order %ref payment is unsupported (%status).", ['%status' => $orderStatus, '%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME));

            $status = self::PAYMENT_STATUS_ERROR;
        }

        // Check if customer has registered its card for 1-click payment
        if (isset($answer['paymentMethodToken']) && !empty($answer['paymentMethodToken'])) {
            if (null === $tokenData = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($order->getCustomerId())) {
                $tokenData = (new PayzenEmbeddedCustomerToken())
                    ->setCustomerId($order->getCustomerId());
            }

            // Update customer payment token
            $tokenData
                ->setPaymentToken($answer['paymentMethodToken'])
                ->save();
        }

        return $status;
    }

    /**
     * Record every transaction the platform holds for the order, credits included, through the
     * Order/Get service. Nothing here moves the order: the history only catches up, whatever the
     * notification arbiter would say, since the platform's list is the truth about its own
     * transactions. A transaction the platform lists under this reference for another shop, another
     * space or another order is left out, see TransactionProvenance.
     *
     * @throws LyraException
     * @throws TheliaProcessException when the platform cannot list the order's transactions
     */
    protected function syncTransactions(Order $order): void
    {
        $response = $this->post('V4/Order/Get', ['orderId' => $order->getRef()]);

        $transactions = $response['answer']['transactions'] ?? null;

        if (($response['status'] ?? null) !== 'SUCCESS' || !\is_array($transactions)) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans(
                    'Cannot list the transactions of the order with PayZen. Error is : %message (code %code)',
                    [
                        '%code' => (string) ($response['answer']['errorCode'] ?? 'undefined error code'),
                        '%message' => (string) ($response['answer']['errorMessage'] ?? 'undefined error message'),
                    ],
                    PayzenEmbedded::DOMAIN_NAME
                )
            );
        }

        if (\count($transactions) >= self::ORDER_GET_LIMIT) {
            $this->log->addWarning(sprintf(
                'PayZen Order/Get answered %d transactions for order %s, its limit: the list may be incomplete.',
                \count($transactions),
                $order->getRef()
            ));
        }

        $provenance = new TransactionProvenance(
            (string) $order->getRef(),
            PayzenEmbedded::platformMode(),
            PayzenEmbedded::shopMarker(),
            (string) $order->getTransactionRef()
        );

        foreach ($transactions as $answer) {
            if (!\is_array($answer) || !\is_string($answer['uuid'] ?? null) || 1 !== preg_match('/^[0-9a-f]{32}$/i', $answer['uuid'])) {
                continue;
            }

            // Order/Get lists every attempt of the order: no debit hint here, a transaction that
            // does not say its operation type is a debit like any attempt.
            $outcome = TransactionOutcome::fromAnswer($answer);

            if (!$provenance->accepts($outcome)) {
                $this->log->addWarning(sprintf(
                    'PayZen transaction %s listed for order %s is not this shop\'s, for this order: ignored (%s).',
                    $outcome->uuid,
                    $order->getRef(),
                    json_encode(['orderId' => $outcome->orderRef, 'mode' => $outcome->mode, 'marked' => null !== $outcome->shopMarker, 'parent' => $outcome->parentUuid])
                ));

                continue;
            }

            $this->updateTransactionHistory($answer, $order);
        }
    }

    /**
     * What the platform says about a transaction, in the terms the caller answers the platform in.
     * Used when the shop declines to move the order on this transaction: the acknowledgement still
     * has to report the transaction the platform asked about.
     */
    protected function paymentStatusOf(TransactionOutcome $outcome): int
    {
        return match ($outcome->status) {
            TransactionOutcome::STATUS_PAID => self::PAYMENT_STATUS_PAID,
            TransactionOutcome::STATUS_UNPAID => self::PAYMENT_STATUS_NOT_PAID,
            TransactionOutcome::STATUS_RUNNING => self::PAYMENT_STATUS_IN_PROGRESS,
            default => self::PAYMENT_STATUS_ERROR,
        };
    }

    /**
     * Get an order and issue a log message if not found.
     * @param string $orderReference
     * @return null|\Thelia\Model\Order
     */
    protected function getOrderByRef($orderReference)
    {
        if (null !== $orderReference) {
            if (null === $order = OrderQuery::create()->filterByRef($orderReference)->findOne()) {
                $this->log->addError(
                    Translator::getInstance()->trans("Unknown order reference:  %ref", array('%ref' => $orderReference))
                );
            }

            return $order;
        }

        return null;
    }

    /**
     * Get an order by transaction ID and issue a log message if not found.
     *
     * @param string $transactionRef
     * @return null|\Thelia\Model\Order
     */
    protected function getOrderByTransaction($transactionRef)
    {
        if (null !== $transactionRef) {
            if (null === $order = OrderQuery::create()->filterByTransactionRef($transactionRef)->findOne()) {
                $this->log->addError(
                    Translator::getInstance()->trans("Unknown order for transaction:  %ref", array('%ref' => $transactionRef))
                );
            }

            return $order;
        }

        return null;
    }

    /**
     * Update an order status
     *
     * @param Order $order
     * @param OrderStatus $orderStatus
     */
    protected function setOrderStatus(Order $order, OrderStatus $orderStatus)
    {
        // Prevent sending several confirmation emails
        if ($order->getStatusId() !== $orderStatus->getId()) {
            $event = (new OrderEvent($order))->setStatus($orderStatus->getId());

            $this->dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
        }
    }
}
