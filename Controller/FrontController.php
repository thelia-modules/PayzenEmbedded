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
use PayzenEmbedded\LyraClient\TokenCancellationFailedException;
use PayzenEmbedded\Service\StoredMethodRecorder;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Exception\StoredMethodRefusedException;
use Thelia\Model\Customer;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Module\BasePaymentModuleController;
use Thelia\Tools\TokenProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Model\Map\OrderTableMap;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
#[Route('/payzen-embedded', name: 'payzen_embedded_front_')]
class FrontController extends BasePaymentModuleController
{
    /** Id of the token the forms that cancel a payment carry. */
    public const ABORT_PAYMENT_TOKEN_ID = 'payzen_embedded_abort_payment';

    protected function getModuleCode(): string
    {
        return PayzenEmbedded::getModuleCode();
    }

    /**
     * Process a Payzen platform request
     */
    #[Route('/ipn-callback', name: 'process_ipn')]
    public function processIpn(EventDispatcherInterface $dispatcher, Translator $translator, StoredMethodRecorder $storedMethodRecorder)
    {
        $this->getLog()->info($translator->trans("Starting processing PayZen IPN request", [], PayzenEmbedded::DOMAIN_NAME));

        // The response code to the server
        $gatewayResponseCode = 'KO';

        $lyraClient = new LyraPaymentManagementWrapper($dispatcher, null, $storedMethodRecorder);
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

    /**
     * The customer asks to forget their cards: PayZen is asked first, and a card it did not cancel
     * stays among their payment methods, where they can ask again, and the customer is told so.
     *
     * A POST that carries the token of the session: a link in a page, a mail or an image must not be
     * able to have a customer's cards cancelled.
     */
    #[Route('/alias-clear', name: 'alias_clear', methods: ['POST'])]
    public function clearCustomerToken(Session $session, CustomerPaymentMethodService $storedMethods, TokenProvider $tokenProvider, Translator $translator)
    {
        $customer = $session->getCustomerUser();

        if (!$customer instanceof Customer) {
            throw new AccessDeniedHttpException('Only a customer can forget their cards.');
        }

        try {
            $tokenProvider->checkRequestToken($this->getRequest());
        } catch (TokenAuthenticationException) {
            $session->getFlashBag()->add('error', $translator->trans('Your request could not be checked, please try again.', [], PayzenEmbedded::DOMAIN_NAME));

            return $this->generateRedirect($session->getReturnToUrl());
        }

        $module = ModuleQuery::create()->findPk(PayzenEmbedded::getModuleId());
        $methods = $storedMethods->listFor($customer, $module);
        $kept = 0;

        foreach ($methods as $position => $method) {
            try {
                $storedMethods->forget($customer, $method);
            } catch (StoredMethodRefusedException $refusal) {
                $this->getLog()->addError(sprintf('PayZen did not cancel the stored payment method %d of customer %d.', (int) $method->getId(), (int) $customer->getId()));

                if (TokenCancellationFailedException::UNREACHABLE === $refusal->providerReason) {
                    // PayZen cannot be reached: the next card would wait for the same timeout, and the page answers
                    // once. The cards not tried are kept too.
                    $kept += \count($methods) - $position;

                    break;
                }

                // PayZen refused this card: the next one is a card of its own.
                ++$kept;
            }
        }

        if ($kept > 0) {
            $this->getLog()->addError(sprintf('%d stored payment method(s) of customer %d kept: PayZen refused them or could not be reached.', $kept, (int) $customer->getId()));
        }

        if ($kept > 0) {
            $session->getFlashBag()->add('error', $translator->trans('PayZen could not forget %count% of your cards, they are kept: please try again later.', ['%count%' => $kept], PayzenEmbedded::DOMAIN_NAME));
        }

        return $this->generateRedirect($session->getReturnToUrl());
    }

    /**
     * The customer gives up paying an order with PayZen: the order is cancelled and the customer sent to the
     * failure page.
     *
     * A POST that carries a token of the session, on an order of the customer that PayZen is to pay and that is
     * not paid yet: a link in a page, a mail or an image must not be able to cancel an order, and an order paid,
     * being prepared or paid with another module keeps its status.
     */
    #[Route('/cancel-payment/{orderId}', name: 'abort_payment', requirements: ['orderId' => '\d+'], methods: ['POST'])]
    public function abortPayment(
        EventDispatcherInterface $dispatcher,
        SecurityContext $securityContext,
        CsrfTokenManagerInterface $csrfTokenManager,
        Translator $translator,
        int $orderId
    ) {
        $customer = $securityContext->getCustomerUser();

        if (!$customer instanceof Customer) {
            throw new AccessDeniedHttpException('Only a customer can cancel the payment of their order.');
        }

        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::ABORT_PAYMENT_TOKEN_ID, (string) $this->getRequest()->request->get('_token')))) {
            throw new AccessDeniedHttpException('The request does not carry the token of the session.');
        }

        // Read from the database, not from what this request may already hold in memory.
        OrderTableMap::removeInstanceFromPool($orderId);
        $order = OrderQuery::create()->findPk($orderId);

        if (null === $order || (int) $order->getCustomerId() !== (int) $customer->getId()) {
            $this->getLog()->addError(sprintf('Customer %d is not allowed to cancel order %d.', (int) $customer->getId(), $orderId));

            throw new AccessDeniedHttpException('This order is not one of the customer.');
        }

        if (PayzenEmbedded::getModuleId() !== (int) $order->getPaymentModuleId() || !$order->isNotPaid()) {
            $this->getLog()->addInfo(sprintf('Order %s is not a PayZen order waiting for its payment, its status is kept.', $order->getRef()));

            if ($order->isPaid(false)) {
                $this->redirectToSuccessPage($orderId);
            }

            $this->redirectToFailurePage($orderId, $translator->trans('This order can no longer be cancelled.', [], PayzenEmbedded::DOMAIN_NAME));
        }

        $this->getLog()->addInfo(
            $translator->trans(
                "Processing cancelation of payment for order ref. %ref",
                ['%ref' => $order->getRef()]
            )
        );

        $event = (new OrderEvent($order))
            ->setStatus(OrderStatusQuery::getCancelledStatus()->getId());

        $dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);

        $this->redirectToFailurePage($orderId, $translator->trans("You canceled the payment", [], PayzenEmbedded::DOMAIN_NAME));
    }
}
