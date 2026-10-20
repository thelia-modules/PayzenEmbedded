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
use PayzenEmbedded\LyraClient\LyraClientWrapper;
use PayzenEmbedded\LyraClient\LyraTransactionUpdateWrapper;
use PayzenEmbedded\LyraClient\RefundAmount;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Domain\Payment\Exception\PaymentException;
use Thelia\Domain\Payment\Exception\PaymentProviderUnreachableException;
use Thelia\Domain\Payment\Service\PaymentCaptureService;
use Thelia\Exception\TheliaProcessException;
use Thelia\Log\Tlog;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;

final readonly class TransactionUpdateListener implements EventSubscriberInterface
{
    /**
     * The wrapper comes from the container, with the framework's lock factory: built by hand,
     * it would lock on this server's file system while the refund locks on the framework's store,
     * and the two would never exclude each other.
     */
    public function __construct(
        private LyraTransactionUpdateWrapper $updateWrapper,
        private PaymentCaptureService $captureService,
    ) {
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
        if (null === $order = OrderQuery::create()->findPk($event->getOrderId())) {
            throw new TheliaProcessException("Undefined order ID " . $event->getOrderId());
        }

        if (PayzenEmbedded::authorizesFirstFor($order)) {
            $event->setPaymentStatus($this->authorizedFirst($order, $event));

            return;
        }

        // Call the update service
        $paymentStatus = $this->updateWrapper->updateTransaction(
            $order,
            $event->getAmount(),
            $event->getExpectedCaptureDate(),
            $event->isManualValidation()
        );

        $event->setPaymentStatus($paymentStatus);
    }

    /**
     * Authorized first, the capture is Thelia's: a host module asking to validate the transaction
     * (the capture after shipping) captures through Thelia, with its journal and its single-capture
     * rule, and a request that does not validate only moves the capture date, the amount the
     * platform holds kept as the order page does.
     */
    private function authorizedFirst(Order $order, TransactionUpdateEvent $event): int
    {
        if ($event->isManualValidation()) {
            $currencyCode = strtoupper((string) $order->getCurrency()->getCode());
            $held = $this->updateWrapper->heldAmountOf($order) ?? RefundAmount::fromMajor((string) $order->getTotalAmount(), $currencyCode);

            return $this->updateWrapper->updateTransaction($order, RefundAmount::format($held, $currencyCode), $event->getExpectedCaptureDate(), true);
        }

        $amount = $event->getAmount();

        try {
            $capture = $this->captureService->capture($order, null === $amount || '' === $amount ? null : (float) $amount);
        } catch (PaymentProviderUnreachableException) {
            // The platform may have validated: the notification settles the line.
            return LyraClientWrapper::PAYMENT_STATUS_IN_PROGRESS;
        } catch (PaymentException $refusal) {
            Tlog::getInstance()->error(sprintf('Order %s: the capture asked through the transaction update event was refused: %s', $order->getRef(), $refusal->getMessage()));

            return LyraClientWrapper::PAYMENT_STATUS_ERROR;
        }

        return $capture->isSucceeded() ? LyraClientWrapper::PAYMENT_STATUS_PAID : LyraClientWrapper::PAYMENT_STATUS_IN_PROGRESS;
    }
}
