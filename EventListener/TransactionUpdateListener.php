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
 * Manage confirmation email sent to customer after payment.
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 23/05/2019 17:12
 */
namespace PayzenEmbedded\EventListener;

use PayzenEmbedded\Event\TransactionUpdateEvent;
use PayzenEmbedded\LyraClient\LyraTransactionUpdateWrapper;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\OrderQuery;

class TransactionUpdateListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            PayzenEmbedded::TRANSACTION_UPDATE_EVENT => ["transactionUpdate", 128],
        ];
    }

    /**
     * Perform transaction update
     *
     * @param TransactionUpdateEvent $event
     * @param $eventName
     * @param EventDispatcherInterface $dispatcher
     *
     * @throws \Lyra\Exceptions\LyraException
     */
    public function transactionUpdate(TransactionUpdateEvent $event, $eventName, EventDispatcherInterface $dispatcher)
    {
        if (null !== $order = OrderQuery::create()->findPk($event->getOrderId())) {
            // Call the update service
            $lyraClient = new LyraTransactionUpdateWrapper($dispatcher);

            $paymentStatus = $lyraClient->updateTransaction(
                $order,
                $event->getAmount(),
                $event->getExpectedCaptureDate(),
                $event->isManualValidation()
            );

            $event->setPaymentStatus($paymentStatus);
        } else {
            throw new TheliaProcessException("Undefined order ID " . $event->getOrderId());
        }
    }
}
