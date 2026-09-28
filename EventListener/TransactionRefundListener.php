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

namespace PayzenEmbedded\EventListener;

use PayzenEmbedded\Event\TransactionRefundEvent;
use PayzenEmbedded\LyraClient\LyraTransactionRefundWrapper;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\OrderQuery;

class TransactionRefundListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PayzenEmbedded::TRANSACTION_REFUND_EVENT => ['transactionRefund', 128],
        ];
    }

    /**
     * @throws \Lyra\Exceptions\LyraException
     */
    public function transactionRefund(TransactionRefundEvent $event, string $eventName, EventDispatcherInterface $dispatcher): void
    {
        if (null === $order = OrderQuery::create()->findPk($event->getOrderId())) {
            throw new TheliaProcessException('Undefined order ID ' . $event->getOrderId());
        }

        $lyraClient = new LyraTransactionRefundWrapper($dispatcher);

        $event->setOutcome(
            $lyraClient->refundTransaction($order, (int) round($event->getAmount() * 100), $event->getComment())
        );
    }
}
