<?php


namespace PayzenEmbedded\EventListener;


use PayzenEmbedded\Events\ProcessPaymentResponseEvent;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Action\BaseAction;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;

class ProcessPaymentResponseListener extends BaseAction implements EventSubscriberInterface
{
    const PAYMENT_STATUS_PAID = 1;
    const PAYMENT_STATUS_NOT_PAID = 2;
    const PAYMENT_STATUS_IN_PROGRESS = 3;
    const PAYMENT_STATUS_ERROR = 4;

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
        $this->dispatcher->dispatch(TheliaEvents::ORDER_UPDATE_TRANSACTION_REF, $event);

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
     * Process a CreatePayment response and update the order accordingly.
     *
     * @param ProcessPaymentResponseEvent $event a CreatePayment response
     * @return int the payment status, one of self::PAYMENT_STATUS_* value
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

        $test = $event->getStatus();

        $test = 0;
    }

    public static function getSubscribedEvents()
    {
        return array(
            'PAYZEN_EMBEDDED_PROCESS_PAYMENT_RESPONSE' => array('processPaymentResponse', 128),
        );
    }
}