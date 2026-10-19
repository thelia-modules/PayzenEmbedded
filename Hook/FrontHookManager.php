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
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 23/05/2019 17:02
 */
namespace PayzenEmbedded\Hook;

use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\StoredMethodContract;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope;
use Thelia\Tools\TokenProvider;

class FrontHookManager extends BaseHook
{
    public function __construct(
        private readonly TokenProvider $tokenProvider,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public function onOrderInvoiceAfterJavascriptInclude(HookRenderEvent $event)
    {
        if (PayzenEmbedded::getConfigValue('popup_mode', false)) {
            $event->add(
                $this->render(
                    "payzen-embedded/order-invoice.js.html",
                    ['moduleId' => PayzenEmbedded::getModuleId()]
                )
            );
        }
    }

    /**
     * Load all javascript form stuff in header.
     *
     * @param HookRenderEvent $event
     */
    public function onOrderPaymentGatewayStylesheet(HookRenderEvent $event)
    {
        $event->add(
            $this->addCSS('payzen-embedded/assets/css/style.css')
        );
    }

    public function onOrderInvoicePayementExtra(HookRenderEvent $event)
    {
        if (boolval(PayzenEmbedded::getConfigValue('allow_one_click_payments'))) {
            $moduleId = intval($event->getArgument('module'));

            if ($moduleId === PayzenEmbedded::getModuleId()) {
                // Check if the customer has a saved card that can pay here: a card is good on the PayZen contract of the
                // sale unit it was registered on, the one of the request here.
                $customerId = $this->getSession()->getCustomerUser()->getId();
                $saleUnitId = PayzenEmbedded::supportsSaleUnits() ? SaleUnitConfigScope::current() : null;

                if (StoredMethodContract::customerHasACardFor((int) $customerId, $moduleId, $saleUnitId)) {
                    $event->add(
                        $this->render(
                            'payzen-embedded/one_click-token-info.html'
                        )
                    );
                }
            }
        }
    }

    public function onAccountAdditional(HookRenderBlockEvent $event)
    {
        $content = trim(
            $this->render(
            "payzen-embedded/customer-account.html",
                [
                    'customer_id' => $this->getSession()->getCustomerUser()->getId(),
                    'csrf_token' => $this->tokenProvider->assignToken(),
                ]
            )
        );

        if (! empty($content)){
            $event->add(array(
                "id" => "payzen-one-click",
                "title" => $this->trans("One click payment with PayZen", [], PayzenEmbedded::DOMAIN_NAME),
                "content" => $content
            ));
        }
    }

    public static function getSubscribedHooks(): array
    {
        return [
            "order-payment-gateway.stylesheet" => [
                [
                    "type" => "front",
                    "method" => "onOrderPaymentGatewayStylesheet"
                ]
            ],
            "order-invoice.payment-extra" => [
                [
                    "type" => "front",
                    "method" => "onOrderInvoicePayementExtra"
                ]
            ],
            "account.additional" => [
                [
                    "type" => "front",
                    "method" => "onAccountAdditional"
                ]
            ],
            "order-invoice.after-javascript-include" => [
                [
                    "type" => "front",
                    "method" => "onOrderInvoiceAfterJavascriptInclude"
                ]
            ]
        ];
    }
}
