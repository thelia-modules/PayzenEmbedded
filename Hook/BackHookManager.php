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

use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

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
}
