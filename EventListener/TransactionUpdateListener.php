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
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Order\Service\OrderHistoryActorResolver;
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
        private OrderHistoryActorResolver $actorResolver,
        private SecurityContext $securityContext,
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
     * (the capture after shipping) captures through Thelia, with its journal, its single-capture
     * rule and its right, and a request that does not validate only moves the capture date, the
     * amount the platform holds kept as the order page does. Once a capture was asked, neither is
     * done. A validation captures at once, whatever capture date it carries.
     */
    private function authorizedFirst(Order $order, TransactionUpdateEvent $event): int
    {
        // The capture asked already set the transaction: moving its date could have it validated
        // again, and the journal line would wait for ever.
        if (PayzenEmbedded::journalHoldsACapture($order)) {
            Tlog::getInstance()->error(sprintf('Order %s: the transaction update event was refused, a capture of the payment was already asked from Thelia.', $order->getRef()));

            return LyraClientWrapper::PAYMENT_STATUS_ERROR;
        }

        if ($event->isManualValidation()) {
            $currencyCode = strtoupper((string) $order->getCurrency()->getCode());
            $held = $this->updateWrapper->heldAmountOf($order) ?? RefundAmount::fromMajor((string) $order->getTotalAmount(), $currencyCode);

            return $this->updateWrapper->updateTransaction($order, RefundAmount::format($held, $currencyCode), $event->getExpectedCaptureDate(), true);
        }

        // Taking the money is the capture right's, for an administrator as in Thelia's dialog; a
        // module acting on its own, without an administrator, is not asked.
        $administrator = $this->actorResolver->actingAdministrator(PayzenEmbedded::getModuleCode());

        if (null !== $administrator && !$this->securityContext->isUserGranted(['ADMIN'], [AdminResources::ORDER_PAYMENT_CAPTURE], [], [AccessManager::CREATE], $administrator)) {
            Tlog::getInstance()->error(sprintf('Order %s: the capture asked through the transaction update event was refused, %s has not the right to capture payments.', $order->getRef(), $administrator->getUsername()));

            return LyraClientWrapper::PAYMENT_STATUS_ERROR;
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
