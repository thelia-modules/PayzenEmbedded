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

namespace PayzenEmbedded\Service;

use PayzenEmbedded\LyraClient\LyraJavascriptClientManagementWrapper;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Payment\StoredPaymentMethodPayment;
use Thelia\Log\Tlog;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Order;

/**
 * Hands a template the card form of an order, without rendering a page.
 *
 * The module's pay() answers with a page of its own, built by the front template engine, which is
 * what a theme that redirects to a payment page needs. A theme that shows the card fields inside its
 * own checkout step needs the payload instead, and nothing else: it mounts the fields itself.
 *
 * Asking PayZen for a token is a server call, so the answer is held for the length of the request.
 * It is NOT held across requests: the lifetime PayZen gives a form token is not documented in this
 * module, and serving an expired token would show the shopper a form that refuses every card.
 */
#[Autoconfigure(public: true)]
class CardFormProvider
{
    /** @var array<string, CardFormPayload> one entry per order and per stored method, for the length of the request */
    private array $payloads = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly StoredPaymentMethodPayment $storedPayment,
    ) {
    }

    /**
     * @param CustomerPaymentMethod|null $storedMethod the card to pay with: the form then opens on it. Without it,
     *                                                 the card the core resolves for the order (the one chosen on
     *                                                 the cart the order was placed from), a new card when none.
     *
     * @throws StoredMethodOfAnotherContractException when the given card was registered on another sale unit
     */
    public function forOrder(Order $order, ?CustomerPaymentMethod $storedMethod = null): CardFormPayload
    {
        // On the contract of the sale unit the order was placed on: the token, the public key and the
        // platform the client resources come from are all its.
        return PayzenEmbedded::runForOrder($order, fn (): CardFormPayload => $this->payloadFor($order, $storedMethod));
    }

    protected function createWrapper(): LyraJavascriptClientManagementWrapper
    {
        return new LyraJavascriptClientManagementWrapper($this->dispatcher);
    }

    private function payloadFor(Order $order, ?CustomerPaymentMethod $given): CardFormPayload
    {
        if (null !== $given) {
            // The core, or a theme, asked for this very card: it is never silently replaced by a new one.
            StoredMethodContract::refuseUnlessUsableForOrder($given, $order);
        }

        $method = $given ?? $this->cardChosenFor($order);

        // The same order asked once for a new card and once for a stored one is two forms; asked twice for the same
        // card it is one, whether the card was given or resolved from the cart: the platform is called once per
        // order and card, whichever of the module's pay() and a theme asks first.
        $key = (int) $order->getId() . '#' . (null === $method ? 'new' : (int) $method->getId());

        if (isset($this->payloads[$key])) {
            return $this->payloads[$key];
        }

        // Opening the form on a card that was resolved, not given, is not a payment the core counted: the hourly
        // allowance of the customer is read here, never spent (the core spends it in StoredPaymentMethodPayment::pay(),
        // once per payment, and a theme asks for this form each time the page is displayed). A customer who spent
        // it is offered a new card rather than a refusal: the payment that spent the last slot is the one this
        // form belongs to.
        if (null === $given && null !== $method && $this->storedPayment->customerHasExhaustedTheirAllowance((int) $order->getCustomerId())) {
            Tlog::getInstance()->addInfo(sprintf('Order %s: the form opens on a new card, the customer spent their allowance of payments with a stored card.', $order->getRef()));

            $method = null;
            $key = (int) $order->getId() . '#new';

            if (isset($this->payloads[$key])) {
                return $this->payloads[$key];
            }
        }

        return $this->open($order, $method, $key);
    }

    /**
     * The card the customer chose for the order, as the core resolves it for the payment (the choice written on the
     * cart, checked again against the customer, the module and the expiry), when it can pay this order: a card of
     * another sale unit's contract is left out, the core knows nothing of the contracts.
     */
    private function cardChosenFor(Order $order): ?CustomerPaymentMethod
    {
        $method = $this->storedPayment->methodChosenFor($order, new PayzenEmbedded());

        if (null === $method || StoredMethodContract::isUsableForOrder($method, $order)) {
            return $method;
        }

        Tlog::getInstance()->addInfo(sprintf('Order %s: the form opens on a new card, the stored payment method #%d was registered on another sale unit.', $order->getRef(), (int) $method->getId()));

        return null;
    }

    private function open(Order $order, ?CustomerPaymentMethod $storedMethod, string $key): CardFormPayload
    {
        $orderId = (int) $order->getId();

        $result = $this->createWrapper()->payOrder($order, $storedMethod);

        if (true === ($result['success'] ?? false) && !empty($result['form_token'])) {
            return $this->payloads[$key] = CardFormPayload::ready(
                orderId: $orderId,
                formToken: (string) $result['form_token'],
                publicKey: (string) ($result['public_key'] ?? ''),
                clientResourcesBaseUrl: PayzenEmbedded::getStaticResourcesBaseUrl(),
                smartForm: PayzenEmbedded::isSmartFormEnabled(),
                cardFormExpanded: (bool) PayzenEmbedded::getConfigValue('smart_form_card_form_expanded', true),
            );
        }

        // Not cached: an order whose token could not be obtained deserves another try on the next
        // request, the platform may simply have been unreachable.
        return CardFormPayload::unavailable(
            $orderId,
            (string) ($result['errorCode'] ?? ''),
            (string) ($result['errorMessage'] ?? ''),
            (string) ($result['detailedErrorCode'] ?? ''),
            (string) ($result['detailedErrorMessage'] ?? '')
        );
    }
}
