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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\OrderQuery;

final readonly class TransactionUpdateListener implements EventSubscriberInterface
{
    /**
     * The wrapper comes from the container, with the framework's lock factory: built by hand,
     * it would lock on this server's file system while the refund locks on the framework's store,
     * and the two would never exclude each other.
     */
    public function __construct(private LyraTransactionUpdateWrapper $updateWrapper)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PayzenEmbedded::TRANSACTION_UPDATE_EVENT => ["transactionUpdate", 128],
        ];
    }

    /**
     * Perform transaction update
     *
     * @throws \Lyra\Exceptions\LyraException
     */
    public function transactionUpdate(TransactionUpdateEvent $event): void
    {
        if (null !== $order = OrderQuery::create()->findPk($event->getOrderId())) {
            // Call the update service
            $paymentStatus = $this->updateWrapper->updateTransaction(
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
