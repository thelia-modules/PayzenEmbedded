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
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Model\OrderQuery;

class BackHookManager extends BaseHook
{
    /**
     * Render configuration template
     *
     * @param HookRenderEvent $event
     */
    public function onModuleConfigure(HookRenderEvent $event)
    {
        $event->add(
            $this->render('payzen-embedded/module-configuration.html')
        );
    }

    public function onOrderEditBottom(HookRenderEvent $event)
    {
        $orderId = \intval($event->getArgument('order_id'));

        // Check if this order was paid using PayZen, and siplay the update form if it's the case.
        if (null !== $order = OrderQuery::create()->findPk($orderId)) {
            if (PayzenEmbedded::getModuleId() === $order->getPaymentModuleId()) {
                // We can change transaction for not paid orders only.
                $event->add(
                    $this->render(
                        '/payzen-embedded/order-edit.html',
                        ['order_id' => $orderId]
                    )
                );
            }
        }
    }

    public function onCustomerEditBottom(HookRenderEvent $event)
    {
        $event->add($this->render(
            'payzen-embedded/customer-edit.html',
            $event->getArguments()
        ));
    }
}
