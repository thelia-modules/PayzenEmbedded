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
    ) {
    }

    /**
     * @param CustomerPaymentMethod|null $storedMethod the card the customer chose to pay with: the form
     *                                                 then opens on it. Without it, a new card.
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

    private function payloadFor(Order $order, ?CustomerPaymentMethod $storedMethod): CardFormPayload
    {
        $orderId = (int) $order->getId();
        // The same order asked once for a new card and once for a stored one is two forms.
        $key = $orderId . '#' . (null === $storedMethod ? 'new' : (int) $storedMethod->getId());

        if (isset($this->payloads[$key])) {
            return $this->payloads[$key];
        }

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
