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
use PayzenEmbedded\LyraClient\PlatformNotification;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Module\BasePaymentModuleController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
#[Route('/payzen-embedded', name: 'payzen_embedded_front_')]
class FrontController extends BasePaymentModuleController
{
    protected function getModuleCode(): string
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

        $lyraClient = new LyraPaymentManagementWrapper($dispatcher);
        $signed = false;

        try {
            $notification = PlatformNotification::fromPost($this->getRequest()->request->all());

            // The key that signs a notification is the one of the contract of the order's sale unit,
            // and the order is only known from the notification itself. The reference it names is
            // read without believing it, to find the order and so the contract: whether the
            // notification is genuine is decided afterwards, with that contract's key and no other.
            $order = null !== ($orderReference = $notification->claimedOrderReference())
                ? OrderQuery::create()->filterByRef($orderReference)->findOne()
                : null;

            if (null === $order) {
                // Nothing to move, whoever signed it, and no key to check it with.
                $this->getLog()->addError(sprintf('PayZen notification names no known order (%s), ignored.', null !== $orderReference ? substr((string) preg_replace('/[^\w-]/', '_', $orderReference), 0, 40) : 'no reference'));
            } else {
                $paymentStatus = $lyraClient->forOrder($order, function () use ($lyraClient, $notification, $translator, &$signed): int {
                    // The key is the server's own, never chosen again from the request.
                    if (!($signed = $notification->isSignedBy((string) $lyraClient->getPassword()))) {
                        $this->getLog()->addError($translator->trans("Invalid signature received, aborting.", [], PayzenEmbedded::DOMAIN_NAME));
                        throw new \Exception($translator->trans("Invalid signature received, aborting.", [], PayzenEmbedded::DOMAIN_NAME));
                    }

                    // Process platform response, and update the order accordingly.
                    return $lyraClient->processPaymentResponse($notification->answer);
                });

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
            }
        } catch (\Throwable $ex) {
            $this->getLog()->addError(sprintf('PayZen notification failed: %s: %s at %s:%d', $ex::class, $ex->getMessage(), $ex->getFile(), $ex->getLine()));

            // A signed notification the module failed on (a database or lock failure, a bug) is
            // answered with a server error: the platform replays a failed call and warns the
            // merchant, where a KO would close it. Anything before the signature is checked stays
            // a KO: an unsigned request never gets to make the shop answer an error.
            if ($signed) {
                return new Response('ERROR', Response::HTTP_INTERNAL_SERVER_ERROR);
            }
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
        // A visitor who is not signed in is sent to the login page, and back here afterwards.
        $this->checkAuth();

        // Cancel the order and redirect to failure page.
        if (null !== $order = $this->getOrder($orderId)) {
            /** @var Customer $customer */
            $customer = $securityContext->getCustomerUser();

            if ($order->getCustomerId() === $customer->getId()) {
                // The link stays in the browser history: once the payment went through, or was
                // authorized, following it again leaves the order as it is.
                if ($order->isPaymentSecured()) {
                    $this->getLog()->addWarning(
                        "Payment cancelation of order ref. " . $order->getRef() . " ignored: its payment went through."
                    );

                    $this->redirectToSuccessPage($orderId);
                }

                $this->getLog()->addInfo(
                    $translator->trans(
                        "Processing cancelation of payment for order ref. %ref",
                        ['%ref' => $order->getRef()]
                    )
                );

                if (!$order->isCancelled(false)) {
                    $event = (new OrderEvent($order))
                        ->setStatus(OrderStatusQuery::getCancelledStatus()->getId());

                    $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
                }

            } else {
                $this->getLog()->addError($translator->trans($customer->getRef() . " is not allowed to cancel order " . $order->getRef(), [], PayzenEmbedded::DOMAIN_NAME));

                throw new AccessDeniedHttpException("Forbidden");
            }
        }

        $message = $translator->trans("You canceled the payment", [], PayzenEmbedded::DOMAIN_NAME);

        $this->redirectToFailurePage($orderId, $message);
    }
}
