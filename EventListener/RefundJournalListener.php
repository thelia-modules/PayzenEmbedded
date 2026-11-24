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

use PayzenEmbedded\Event\RefundReportedEvent;
use PayzenEmbedded\LyraClient\RefundAmount;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Exception\PaymentException;
use Thelia\Domain\Payment\Exception\PaymentJournalBusyException;
use Thelia\Domain\Payment\Service\PaymentTransactionRecorder;
use Thelia\Log\Tlog;
use Thelia\Model\OrderPaymentTransactionQuery;

/**
 * Writes to Thelia's payment journal the refunds the platform made or refused, under the uuid of
 * the refund transaction: a refund Thelia asked and wrote pending is settled by it, one made from
 * the PayZen back-office is written, and the same one reported again writes nothing twice. A refund
 * the platform is still processing writes nothing yet: its notification settles it.
 *
 * A journal another worker holds past the wait fails the notification, which the platform replays.
 * Any other refusal of the journal is logged and leaves the notification answered: replaying it
 * would meet the same refusal for ever.
 */
final readonly class RefundJournalListener implements EventSubscriberInterface
{
    public function __construct(private PaymentTransactionRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PayzenEmbedded::REFUND_REPORTED_EVENT => ['onRefundReported', 128],
        ];
    }

    public function onRefundReported(RefundReportedEvent $event): void
    {
        $order = $event->getOrder();
        $answer = $event->getAnswer();
        $refund = TransactionOutcome::fromAnswer($answer);

        if (!$refund->isCredit() || '' === $refund->uuid) {
            return;
        }

        $amount = RefundAmount::format($refund->amount, (string) $order->getCurrency()->getCode());

        try {
            match ($refund->status) {
                TransactionOutcome::STATUS_PAID => $this->recorder->recordRefund(
                    $order,
                    $amount,
                    $refund->uuid,
                    PaymentTransactionState::SUCCEEDED,
                    OrderPaymentTransactionQuery::create()->findLatestSucceededCapture((int) $order->getId()),
                    PayzenEmbedded::getModuleCode(),
                ),
                TransactionOutcome::STATUS_UNPAID => $this->recorder->recordRefund(
                    $order,
                    $amount,
                    $refund->uuid,
                    PaymentTransactionState::FAILED,
                    null,
                    PayzenEmbedded::getModuleCode(),
                    self::stringOrNull($answer['detailedErrorCode'] ?? $answer['errorCode'] ?? null),
                    self::stringOrNull($answer['detailedErrorMessage'] ?? $answer['errorMessage'] ?? null),
                ),
                default => null,
            };
        } catch (PaymentJournalBusyException $busy) {
            throw $busy;
        } catch (PaymentException $refusal) {
            Tlog::getInstance()->error(\sprintf('Order %s: the payment journal refused refund %s: %s', (string) $order->getRef(), $refund->uuid, $refusal->getMessage()));
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
