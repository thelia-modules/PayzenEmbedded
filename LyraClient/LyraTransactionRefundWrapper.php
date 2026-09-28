<?php

declare(strict_types=1);

/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded\LyraClient;

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;

/**
 * A wrapper around the Transaction/CancelOrRefund service: gives the shopper their money back.
 */
class LyraTransactionRefundWrapper extends LyraPaymentManagementWrapper
{
    /** The platform answers at most this many transactions for an order (PSP_015 beyond). */
    private const ORDER_GET_LIMIT = 30;

    /** Two platform calls of up to 45 seconds each fit in this time. */
    private const LOCK_TTL = 120.0;

    private LockFactory $lockFactory;

    /**
     * @param LockFactory|null $lockFactory the framework's lock factory, shared by every node of the
     *                                      shop; without it, a lock on this node's file system
     */
    public function __construct(EventDispatcherInterface $dispatcher, ?LockFactory $lockFactory = null)
    {
        parent::__construct($dispatcher);

        $this->lockFactory = $lockFactory ?? new LockFactory(new FlockStore());
    }

    /**
     * Give money back to the shopper of an order, in full or in part.
     *
     * The platform decides what the request means: a transaction waiting for its capture is
     * cancelled, a captured one gets a refund transaction of its own. A cancellation is always
     * total; lowering a transaction before its capture is the job of the update service.
     *
     * One refund at a time per order: two requests reading the same ledger would both pass its
     * check and both reach the platform.
     *
     * @param int $amount in the smallest unit of the order currency
     *
     * @throws LyraException
     * @throws TheliaProcessException when the amount cannot be refunded, or the platform refused
     * @throws OrderStatusNotUpdatedException when the money moved but the order could not follow
     */
    public function refundTransaction(Order $order, int $amount, ?string $comment = null, ?Admin $admin = null): RefundOutcome
    {
        $lock = $this->lockFactory->createLock($this->lockName($order), self::LOCK_TTL);

        if (!$lock->acquire()) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans('A refund of this order is already running.', [], PayzenEmbedded::DOMAIN_NAME)
            );
        }

        try {
            // The history may be behind the platform: a refund whose answer was lost to a timeout,
            // or one made from the PayZen back-office. The platform's own list of the order's
            // transactions is recorded first, so the ledger counts what was really given back.
            $this->syncTransactions($order);
            $lock->refresh();

            $ledger = (new TransactionHistoryReader())->ledgerOf($order);
            $currencyCode = strtoupper($order->getCurrency()->getCode());

            if (!$ledger->allows($amount)) {
                throw new TheliaProcessException(
                    Translator::getInstance()->trans(
                        $ledger->isCancellable()
                            ? 'This payment is waiting for its capture: it can only be cancelled in full, for %amount %currency.'
                            : 'The amount to refund should be greater than 0 and at most %amount %currency.',
                        ['%amount' => RefundAmount::format($ledger->maximumAmount(), $currencyCode), '%currency' => $currencyCode],
                        PayzenEmbedded::DOMAIN_NAME
                    )
                );
            }

            $response = $this->sendCancelOrRefundRequest($order, $amount, $comment);

            return $this->processCancelOrRefundResponse($order, $response, $admin);
        } finally {
            $lock->release();
        }
    }

    /**
     * Record every transaction the platform holds for the order, credits included, through the
     * Order/Get service. Nothing here moves the order: the history only catches up. A transaction
     * the platform lists under this reference for another order, currency or shop is left out.
     *
     * @throws LyraException
     * @throws TheliaProcessException when the platform cannot list the order's transactions
     */
    public function syncTransactions(Order $order): void
    {
        $response = $this->post('V4/Order/Get', ['orderId' => $order->getRef()]);

        $transactions = $response['answer']['transactions'] ?? null;

        if (($response['status'] ?? null) !== 'SUCCESS' || !\is_array($transactions)) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans(
                    'Cannot check the order with PayZen before refunding it. Error is : %message (code %code)',
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

        $currencyCode = strtoupper($order->getCurrency()->getCode());
        $debitUuid = (string) $order->getTransactionRef();

        foreach ($transactions as $answer) {
            if (!\is_array($answer) || !isset($answer['uuid'])) {
                continue;
            }

            $answerOrderRef = $answer['orderDetails']['orderId'] ?? $order->getRef();
            $answerCurrency = strtoupper((string) ($answer['currency'] ?? $currencyCode));

            if ($answerOrderRef !== $order->getRef() || $answerCurrency !== $currencyCode) {
                $this->log->addWarning(sprintf(
                    'PayZen transaction %s listed for order %s belongs to %s in %s: ignored.',
                    $answer['uuid'],
                    $order->getRef(),
                    (string) $answerOrderRef,
                    $answerCurrency
                ));

                continue;
            }

            $this->updateTransactionHistory($answer, $order, null, $debitUuid);
        }
    }

    /**
     * Build the Transaction/CancelOrRefund parameters, and call the service.
     *
     * @param int $amount in the smallest unit of the order currency
     *
     * @return array the web service result (see https://payzen.io/fr-FR/rest/V4.0/api/playground/Transaction/CancelOrRefund/)
     *
     * @throws LyraException
     */
    public function sendCancelOrRefundRequest(Order $order, int $amount, ?string $comment = null): array
    {
        $parameters = [
            'uuid' => $order->getTransactionRef(),
            'amount' => $amount,
            'currency' => strtoupper($order->getCurrency()->getCode()),
            // The platform cancels a transaction waiting for its capture, and refunds a captured one.
            'resolutionMode' => 'AUTO',
        ];

        if (null !== $comment && '' !== trim($comment)) {
            $parameters['comment'] = trim($comment);
        }

        return $this->post('V4/Transaction/CancelOrRefund', $parameters);
    }

    /**
     * Read what the platform did, record it, and move the order accordingly: cancelled when the
     * transaction was cancelled, refunded when nothing is left to refund, untouched after a
     * partial or a pending refund. An answer of another shape is refused before anything is written.
     *
     * @throws TheliaProcessException when the platform refused, or answered something unexpected
     * @throws OrderStatusNotUpdatedException when the refund is recorded but the order could not follow
     */
    public function processCancelOrRefundResponse(Order $order, array $response, ?Admin $admin = null): RefundOutcome
    {
        $answer = \is_array($response['answer'] ?? null) ? $response['answer'] : [];

        // The shape of this answer was taken from the documentation: keep what the platform really sends.
        $this->log->addInfo(sprintf(
            'PayZen CancelOrRefund answer for order %s: %s',
            $order->getRef(),
            json_encode(array_intersect_key($answer, array_flip(['uuid', 'status', 'detailedStatus', 'operationType', 'amount', 'currency', 'errorCode', 'errorMessage', 'detailedErrorCode'])))
        ));

        $debitUuid = (string) $order->getTransactionRef();

        $resolution = RefundResolution::fromAnswer(
            $answer,
            $debitUuid,
            static fn (string $message, array $parameters): string => Translator::getInstance()->trans($message, $parameters, PayzenEmbedded::DOMAIN_NAME)
        );

        $this->updateTransactionHistory($resolution->answer, $order, $admin, $debitUuid);

        $ledgerAfter = (new TransactionHistoryReader())->ledgerOf($order);
        $outcome = $resolution->outcome($ledgerAfter);
        $currencyCode = strtoupper($order->getCurrency()->getCode());

        $this->log->addInfo(match ($outcome) {
            RefundOutcome::Cancelled => Translator::getInstance()->trans('Order %ref: transaction cancelled before its capture.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::Refunded => Translator::getInstance()->trans('Order %ref refunded in full.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::PartiallyRefunded => Translator::getInstance()->trans(
                'Order %ref: %amount refunded, %left left to refund.',
                [
                    '%ref' => $order->getRef(),
                    '%amount' => RefundAmount::format($resolution->transaction->amount, $currencyCode),
                    '%left' => RefundAmount::format($ledgerAfter->refundableAmount(), $currencyCode),
                ],
                PayzenEmbedded::DOMAIN_NAME
            ),
            RefundOutcome::Pending => Translator::getInstance()->trans('Order %ref: refund of %amount accepted by the platform, still running.', ['%ref' => $order->getRef(), '%amount' => RefundAmount::format($resolution->transaction->amount, $currencyCode)], PayzenEmbedded::DOMAIN_NAME),
        });

        $newStatus = match ($outcome) {
            RefundOutcome::Cancelled => OrderStatusQuery::getCancelledStatus(),
            RefundOutcome::Refunded => OrderStatusQuery::getRefundedStatus(),
            RefundOutcome::PartiallyRefunded, RefundOutcome::Pending => null,
        };

        if (null === $newStatus) {
            return $outcome;
        }

        try {
            $this->setOrderStatus($order, $newStatus);
        } catch (\Throwable $exception) {
            // The money moved and the history says so: this is not a failed refund.
            $this->log->addError(sprintf('Order %s: refund recorded, but its status could not be updated: %s', $order->getRef(), $exception->getMessage()));

            throw new OrderStatusNotUpdatedException(
                $outcome,
                Translator::getInstance()->trans('The refund was made, but the order status could not be updated: check the order.', [], PayzenEmbedded::DOMAIN_NAME),
                $exception
            );
        }

        return $outcome;
    }

    /** One lock per order and per shop, since the nodes of one host may serve several shops. */
    private function lockName(Order $order): string
    {
        return 'payzen-embedded-refund-' . md5((string) ConfigQuery::read('url_site', '') . '#' . PayzenEmbedded::getConfigValue('site_id', '')) . '-' . $order->getId();
    }
}
