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
 * The payment controller, to process PayZen backend IPN request.
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 23/05/2019 17:12
 */
namespace PayzenEmbedded\Controller;

use PayzenEmbedded\Events\ProcessPaymentResponseEvent;
use PayzenEmbedded\LyraClient\LyraPaymentManagementWrapper;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\Exception\AuthorizationException;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Module\BasePaymentModuleController;
use Thelia\Tools\URL;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class FrontController extends BasePaymentModuleController
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
        $this->getLog()->info($this->getTranslator()->trans("Starting processing PayZen IPN request", [], PayzenEmbedded::DOMAIN_NAME));

        // The response code to the server
        $gatewayResponseCode = 'KO';

        $lyraClient = new LyraPaymentManagementWrapper($this->getDispatcher(), $this->getLog());

        try {
            /* Retrieve the IPN content */
            $rawAnswer = $lyraClient->getParsedFormAnswer();

            if (!$lyraClient->checkHash()) {
                $this->getLog()->addError($this->getTranslator()->trans("Invalid signature received, aborting.", [], PayzenEmbedded::DOMAIN_NAME));
                throw new \Exception($this->getTranslator()->trans("Invalid signature received, aborting.", [], PayzenEmbedded::DOMAIN_NAME));
            }

            $formAnswer = $rawAnswer['kr-answer'];

            $processPaymentEvent = new ProcessPaymentResponseEvent($formAnswer);
            $this->dispatch('PAYZEN_EMBEDDED_PROCESS_PAYMENT_RESPONSE', $processPaymentEvent);

            $paymentStatus = $processPaymentEvent->getStatus();

            switch ($paymentStatus) {
                case LyraPaymentManagementWrapper::PAYMENT_STATUS_PAID:
                    $gatewayResponseCode = 'OK';
                    break;
                case LyraPaymentManagementWrapper::PAYMENT_STATUS_NOT_PAID:
                    $gatewayResponseCode = 'KO';
                    break;
                case LyraPaymentManagementWrapper::PAYMENT_STATUS_IN_PROGRESS:
                    $gatewayResponseCode = 'WAIT';
                    break;
                default:
                    $gatewayResponseCode = 'UNKNOWN';
            }
        } catch (\Exception $ex) {
            $this->getLog()->addError($this->getTranslator()->trans("Failed to process request, aborting. Error is " .$ex->getMessage(), [], PayzenEmbedded::DOMAIN_NAME));
        }

        return Response::create($gatewayResponseCode);
    }

    /**
     * This is a simple wrapper around teh redirection to failure page
     *
     * @param $orderId
     * @param $message
     */
    public function notifyOneClickPaymentFailure($orderId, $message)
    {
        return $this->redirectToFailurePage($orderId, $message);
    }

    /**
     * When a one click payment is validated in PayzenEmbedded\PayzenEmbedded::processJavascriptClientPayment,
     * redirect the user to the success page.
     *
     * @param $orderId
     */
    public function notifyOneClickPaymentSuccess($orderId)
    {
        return $this->redirectToSuccessPage($orderId);
    }

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function clearCustomerToken()
    {
        $customerId = $this->getSession()->getCustomerUser()->getId();

        if (null !== $token = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customerId)) {
           $token->delete();
        }

        return $this->generateRedirect($this->getSession()->getReturnToUrl());
    }

    /**
     * Cancel an order on user request
     *
     * @param $orderId
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws AuthorizationException
     */
    public function abortPayment($orderId)
    {
        // Cancel the order and redirect to failure page.
        if (null !== $order = $this->getOrder($orderId)) {
            /** @var Customer $customer */
            $customer = $this->getSecurityContext()->getCustomerUser();

            if ($order->getCustomerId() === $customer->getId()) {
                $this->cancelOrder($order);
            } else {
                $this->getLog()->addError($this->getTranslator()->trans($customer->getRef() . " is not allowed to cancel order " . $order->getRef(), [], PayzenEmbedded::DOMAIN_NAME));

                throw new AuthorizationException("Forbidden");
            }
        }

        $message = $this->getTranslator()->trans("You canceled the payment", [], PayzenEmbedded::DOMAIN_NAME);

        return $this->redirectToFailurePage($orderId, $message);
    }

    /**
     * Get an order and issue a log message if not found.
     * @param string $orderReference
     * @return null|\Thelia\Model\Order
     */
    protected function getOrderByRef($orderReference)
    {
        if (null == $order = OrderQuery::create()->filterByRef($orderReference)->findOne()) {
            $this->getLog()->addError(
                $this->getTranslator()->trans("Unknown order reference:  %ref", array('%ref' => $orderReference))
            );
        }

        return $order;
    }

    /**
     * Set an order to the canceled status
     *
     * @param Order $order
     */
    protected function cancelOrder(Order $order)
    {
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
