<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia 2 PayZen Embedded payment module                                      */
/*                                                                                   */
/*      Copyright (c) Lyra Networks                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*                                                                                   */
/*************************************************************************************/

/**
 * The payment controller, to process PayZen requests.
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 23/05/2019 17:12
 */
namespace PayzenEmbedded\Controller;

use Lyra\Client;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;
use Thelia\Module\BasePaymentModuleController;
use Thelia\Tools\URL;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class PaymentController extends BasePaymentModuleController
{
    protected function getModuleCode()
    {
        return PayzenEmbedded::getModuleCode();
    }

    /**
     * Process a Payzen platform request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function processIpn()
    {
        $this->getLog()->info($this->getTranslator()->trans("Starting processing Payzen IPN request", [], PayzenEmbedded::DOMAIN_NAME));

        // The response code to the server
        $gateway_response_code = 'ko';

        $lyraClient = new Client();

        if (! $lyraClient->checkHash()) {
            $this->getLog()->addError($this->getTranslator()->trans("Invalid signature received, aborting.", [], PayzenEmbedded::DOMAIN_NAME));
        } else {
            /* Retrieve the IPN content */
            $rawAnswer = $lyraClient->getParsedFormAnswer();
            $formAnswer = $rawAnswer['kr-answer'];

            /* Retrieve the transaction id from the IPN data */
            $transaction = $formAnswer['transactions'][0];

            /* get some parameters from the answer */
            $orderStatus = $formAnswer['orderStatus'];
            $orderId = $formAnswer['orderDetails']['orderId'];
            $transactionUuid = $transaction['uuid'];

            $this->getLog()->addInfo($this->getTranslator()->trans("Payzen platform request received for order ID %id.", ['%id' => $orderId], PayzenEmbedded::DOMAIN_NAME));

            if (null !== $order = $this->getOrder($orderId)) {
                if ($orderStatus === 'PAID') {

                    if ($order->isPaid()) {
                        $this->getLog()->addInfo($this->getTranslator()->trans("Order ID %id is already paid.", ['%id' => $orderId], PayzenEmbedded::DOMAIN_NAME));

                        $gateway_response_code = 'payment_ok_already_done';
                    } else {
                        $this->getLog()->addInfo($this->getTranslator()->trans("Order ID %id payment was successful.", ['%id' => $orderId], PayzenEmbedded::DOMAIN_NAME));

                        // Payment OK !
                        $this->confirmPayment($orderId);

                        // Store transaction ID
                        $order
                            ->setTransactionRef($transactionUuid)
                            ->save()
                        ;

                        $gateway_response_code = 'payment_ok';

                        // Check if customer has registered its card for 1-click payment
                        if (isset($transaction['paymentMethodToken']) && !empty($transaction['paymentMethodToken'])) {
                            if (null === $tokenData = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($order->getCustomerId())) {
                                $tokenData = (new PayzenEmbeddedCustomerToken())
                                    ->setCustomerId($order->getCustomerId());
                            }

                            // Update customer payment token
                            $tokenData
                                ->setPaymentToken($transaction['paymentMethodToken'])
                                ->save();
                        }
                    }
                } else if ($orderStatus === 'UNPAID') {
                    $this->cancelOrder($order);
                }
            }

            $this->getLog()->info($this->getTranslator()->trans("Payzen IPN request for order ID %id processing teminated.", ['%id' => $orderId], PayzenEmbedded::DOMAIN_NAME));
        }

        return Response::create($gateway_response_code);
    }

    /**
     * Cancel the current order
     *
     * @param $orderId
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function abortPayment($orderId)
    {
        // Cancel the order and redirect to failure page.
        if (null !== $order = $this->getOrder($orderId)) {
            $this->cancelOrder($order);
        }

        return $this->generateRedirect(
            URL::getInstance()->absoluteUrl(
                "/order/failed/$orderId/".$this->getTranslator()->trans("Your payment was refused", [], PayzenEmbedded::DOMAIN_NAME)
            )
        );
    }

    /**
     * Set an order to the canceled status
     *
     * @param Order $order
     */
    protected function cancelOrder(Order $order)
    {
        if ($order->getCustomerId() === $this->getSecurityContext()->getCustomerUser()->getId()) {
            $this->getLog()->addInfo(
                $this->getTranslator()->trans(
                    "Processing cancelation of payment for order ref. %ref",
                    ['%ref' => $order->getRef()]
                )
            );

            $event = (new OrderEvent($order))
                ->setStatus(OrderStatusQuery::getCancelledStatus()->getId());

            $this->dispatch(TheliaEvents::ORDER_UPDATE_STATUS, $event);
        }
    }
}
