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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\Exception\AuthorizationException;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Module\BasePaymentModuleController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
#[Route('/payzen-embedded', name: 'payzen_embedded_front_')]
class FrontController extends BasePaymentModuleController
{
    protected function getModuleCode()
    {
        return PayzenEmbedded::getModuleCode();
    }

    /**
     * Process a Payzen platform request
     */
    #[Route('/ipn-callback', name: 'process_ipn')]
    public function processIpn(EventDispatcherInterface $dispatcher, Translator $translator)
    {
        $this->getLog()->info($translator->trans("Starting processing PayZen IPN request", [], PayzenEmbedded::DOMAIN_NAME));

        // The response code to the server
        $gatewayResponseCode = 'KO';

        $lyraClient = new LyraPaymentManagementWrapper($dispatcher, $this->getLog());

        try {
            /* Retrieve the IPN content */
            $rawAnswer = $lyraClient->getParsedFormAnswer();

            if (!$lyraClient->checkHash()) {
                $this->getLog()->addError($translator->trans("Invalid signature received, aborting.", [], PayzenEmbedded::DOMAIN_NAME));
                throw new \Exception($translator->trans("Invalid signature received, aborting.", [], PayzenEmbedded::DOMAIN_NAME));
            }

            $formAnswer = $rawAnswer['kr-answer'];

            // Process platform response, and update the order accordingly.
            $paymentStatus = $lyraClient->processPaymentResponse($formAnswer);

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
            $this->getLog()->addError($translator->trans("Failed to process request, aborting. Error is " .$ex->getMessage(), [], PayzenEmbedded::DOMAIN_NAME));
        }

        return new Response($gatewayResponseCode);
    }

    /**
     * This is a simple wrapper around teh redirection to failure page
     */
    #[Route('/alias-failure/{orderId}/{message}', name: 'notify_payment_failure')]
    public function notifyOneClickPaymentFailure($orderId, $message)
    {
        $this->redirectToFailurePage($orderId, $message);
    }

    /**
     * When a one click payment is validated in PayzenEmbedded\PayzenEmbedded::processJavascriptClientPayment,
     * redirect the user to the success page.
     */
    #[Route('/alias-success/{orderId}', name: 'notify_payment_success')]
    public function notifyOneClickPaymentSuccess($orderId)
    {
        $this->redirectToSuccessPage($orderId);
    }

    #[Route('/alias-clear', name: 'alias_clear')]
    public function clearCustomerToken(Session $session)
    {
        $customerId = $session->getCustomerUser()->getId();

        if (null !== $token = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customerId)) {
           $token->delete();
        }

        return $this->generateRedirect($session->getReturnToUrl());
    }

    /**
     * Cancel an order on user request
     */
    #[Route('/cancel-payment/{orderId}', name: 'abort_payment')]
    public function abortPayment(
        EventDispatcherInterface $dispatcher,
        SecurityContext $securityContext,
        Translator $translator,
        $orderId
    ){
        // Cancel the order and redirect to failure page.
        if (null !== $order = $this->getOrder($orderId)) {
            /** @var Customer $customer */
            $customer = $securityContext->getCustomerUser();

            if ($order->getCustomerId() === $customer->getId()) {
                $this->getLog()->addInfo(
                    $translator->trans(
                        "Processing cancelation of payment for order ref. %ref",
                        ['%ref' => $order->getRef()]
                    )
                );

                $event = (new OrderEvent($order))
                    ->setStatus(OrderStatusQuery::getCancelledStatus()->getId());

                $dispatcher->dispatch($event,TheliaEvents::ORDER_UPDATE_STATUS);

            } else {
                $this->getLog()->addError($translator->trans($customer->getRef() . " is not allowed to cancel order " . $order->getRef(), [], PayzenEmbedded::DOMAIN_NAME));

                throw new AuthorizationException("Forbidden");
            }
        }

        $message = $translator->trans("You canceled the payment", [], PayzenEmbedded::DOMAIN_NAME);

        $this->redirectToFailurePage($orderId, $message);
    }
}
