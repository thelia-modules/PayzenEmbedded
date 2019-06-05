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

use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

class FrontHookManager extends BaseHook
{
    /**
     * Render configuration template
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
                // Check if the customer has a registered one click payment
                $customerId = $this->getSession()->getCustomerUser()->getId();

                if (null !== PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customerId)) {
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
                [ 'customer_id' => $this->getSession()->getCustomerUser()->getId() ]
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
}
