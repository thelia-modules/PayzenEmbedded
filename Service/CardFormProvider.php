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
    /** @var array<int, CardFormPayload> one entry per order, for the length of the request */
    private array $payloads = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function forOrder(Order $order): CardFormPayload
    {
        $orderId = (int) $order->getId();

        if (isset($this->payloads[$orderId])) {
            return $this->payloads[$orderId];
        }

        $result = (new LyraJavascriptClientManagementWrapper($this->dispatcher))->payOrder($order);

        if (true === ($result['success'] ?? false) && !empty($result['form_token'])) {
            return $this->payloads[$orderId] = CardFormPayload::ready(
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
