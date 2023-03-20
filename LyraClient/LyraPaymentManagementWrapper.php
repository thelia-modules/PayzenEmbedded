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
use PayzenEmbedded\Events\ProcessPaymentResponseEvent;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
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

class LyraPaymentManagementWrapper extends LyraClientWrapper implements EventSubscriberInterface
{
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

    public function __construct(EventDispatcherInterface $dispatcher, TLog $log = null)
    {
        parent::__construct();

        $this->oneClickEnabled = (bool)(PayzenEmbedded::getConfigValue('allow_one_click_payments'));

        $this->log = null === $log ? Tlog::getInstance() : $log;
        $this->dispatcher = $dispatcher;
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

        if ($this->oneClickEnabled) {
            $formAction = 'ASK_REGISTER_PAY';
        } else {
            $formAction = 'PAYMENT';
        }

        // Request parameters (see https://payzen.io/en-EN/rest/V4.0/api/playground.html?ws=Charge/CreatePayment)
        $store = [
            "amount" => (int)((string)($order->getTotalAmount() * 100)),
            'contrib' => 'Thelia version ' . ConfigQuery::read('thelia_version'),
            'currency' => strtoupper($currency->getCode()),
            'orderId' => $order->getRef(),
            'formAction' => $formAction,

            'customer' => [
                'email' => $customer->getEmail(),
                'reference' => $customer->getRef()
            ],

            'strongAuthentication' => PayzenEmbedded::getConfigValue('strong_authentication', 'AUTO'),
            'ipnTargetUrl' => URL::getInstance()->absoluteUrl('/payzen-embedded/ipn-callback'),

            'transactionOptions' => [
                'cardOptions' => [
                    'captureDelay' => PayzenEmbedded::getConfigValue('capture_delay', 0),
                    'manualValidation' => PayzenEmbedded::getConfigValue('validation_mode', null) ?: null,
                    'paymentSource' => PayzenEmbedded::getConfigValue('payment_source', null) ?: null
                ]
            ],
        ];

        // Add 1-click payment token if we have one, and if it is allowed
        if ($this->oneClickEnabled && (null !== $tokenData = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()))) {
            $store['paymentMethodToken'] = $tokenData->getPaymentToken();
        }

        return $this->post("V4/Charge/CreatePayment", $store);
    }

    /**
     * Process a CreatePayment response and update the order accordingly.
     *
     * @param ProcessPaymentResponseEvent $event a CreatePayment response
     * @throws \Exception
     */
    public function processPaymentResponse(ProcessPaymentResponseEvent $event)
    {
        $status = self::PAYMENT_STATUS_NOT_PAID;

        $response = $event->getResponse();

        // Be sure to have transaction data.
        if (isset($response['transactions'])) {
            $orderRef = $response['orderDetails']['orderId'];

            $this->log->addInfo(Translator::getInstance()->trans("PayZen response received for order %ref.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));

            if (null !== $order = $this->getOrderByRef($orderRef)) {
                $status = $this->processOrderStatus($order, $response['transactions'][0]);
            }

            $this->log->info(Translator::getInstance()->trans("PayZen payment response for order %ref processing teminated.", ['%ref' => $orderRef], PayzenEmbedded::DOMAIN_NAME));
        }

        $event->setStatus($status);
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

    public static function getSubscribedEvents()
    {
        return array(
            'PAYZEN_EMBEDDED_PROCESS_PAYMENT_RESPONSE' => array('processPaymentResponse', 128),
        );
    }
}
