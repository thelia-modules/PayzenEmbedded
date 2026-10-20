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

use PayzenEmbedded\Event\TransactionReportedEvent;
use PayzenEmbedded\LyraClient\JournalMovement;
use PayzenEmbedded\LyraClient\JournalStep;
use PayzenEmbedded\LyraClient\PaymentJournalPlanner;
use PayzenEmbedded\LyraClient\RefundAmount;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;
use Thelia\Domain\Payment\Exception\PaymentException;
use Thelia\Domain\Payment\Exception\PaymentJournalBusyException;
use Thelia\Domain\Payment\Service\PaymentAmount;
use Thelia\Domain\Payment\Service\PaymentTransactionRecorder;
use Thelia\Domain\Payment\Service\PaymentTransactionTotalsReader;
use Thelia\Log\Tlog;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransactionQuery;

/**
 * Writes to Thelia's payment journal what the platform reported, when the module authorizes first
 * and captures later, or the journal holds an authorization of the order from such a time; see
 * PaymentJournalPlanner for what is written.
 *
 * Once the platform confirms a capture of less than the authorization, the rest is written as
 * released: the platform takes a single capture per authorization and lets the rest go.
 *
 * A journal another worker holds past the wait fails the notification, which the platform replays.
 * Any other refusal of the journal is logged and leaves the notification answered: replaying it
 * would meet the same refusal for ever.
 */
final readonly class PaymentJournalListener implements EventSubscriberInterface
{
    /** The code a capture carries when the platform ended its transaction before the remittance. */
    public const ERROR_CODE_ENDED_AT_PLATFORM = 'ended_at_platform';

    public function __construct(
        private PaymentTransactionRecorder $recorder,
        private PaymentTransactionTotalsReader $totalsReader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PayzenEmbedded::TRANSACTION_REPORTED_EVENT => ['onTransactionReported', 128],
        ];
    }

    public function onTransactionReported(TransactionReportedEvent $event): void
    {
        $order = $event->getOrder();
        $answer = $event->getAnswer();
        $authorization = OrderPaymentTransactionQuery::create()->findLatestSucceededAuthorization((int) $order->getId());

        // An order authorized while the module authorized first keeps writing its journal once the
        // mode changed: its capture, its release or its lapse are still to come.
        if (!PayzenEmbedded::authorizesFirst() && null === $authorization) {
            return;
        }

        $steps = PaymentJournalPlanner::for(
            TransactionOutcome::fromAnswer($answer),
            $authorization?->getPspReference(),
            self::stringOrNull($answer['detailedErrorCode'] ?? $answer['errorCode'] ?? null),
            self::stringOrNull($answer['detailedErrorMessage'] ?? $answer['errorMessage'] ?? null),
        );

        try {
            foreach ($steps as $step) {
                $this->write($order, $step);
            }
        } catch (PaymentJournalBusyException $busy) {
            throw $busy;
        } catch (PaymentException $refusal) {
            Tlog::getInstance()->error(\sprintf('Order %s: the payment journal refused transaction %s: %s', (string) $order->getRef(), (string) ($answer['uuid'] ?? ''), $refusal->getMessage()));
        }
    }

    private function write(Order $order, JournalStep $step): void
    {
        $amount = RefundAmount::format($step->amount, (string) $order->getCurrency()->getCode());

        match ($step->movement) {
            JournalMovement::Authorization => $this->recorder->recordAuthorization($order, $amount, $step->uuid, moduleCode: PayzenEmbedded::getModuleCode()),
            JournalMovement::AuthorizationRefused => $this->recorder->recordAuthorization($order, $amount, $step->uuid, PaymentTransactionState::FAILED, PayzenEmbedded::getModuleCode(), $step->errorCode, $step->errorMessage),
            JournalMovement::Capture => $this->capture($order, $amount, $step->uuid),
            JournalMovement::Void => $this->release($order, $step->uuid, fn () => $this->recorder->recordVoid($order, $step->uuid, moduleCode: PayzenEmbedded::getModuleCode())),
            JournalMovement::Expiry => $this->release($order, $step->uuid, fn () => $this->recorder->recordExpiry($order, $step->uuid, moduleCode: PayzenEmbedded::getModuleCode())),
        };
    }

    /**
     * The platform ended the transaction: a capture Thelia asked under it and still waiting for its
     * remittance was cancelled with it, and is settled as failed before the release is written, so
     * that the release covers the whole authorization and no line waits for ever.
     */
    private function release(Order $order, string $uuid, callable $record): void
    {
        $pendingCaptures = OrderPaymentTransactionQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByType(PaymentTransactionType::CAPTURE->value)
            ->filterByState(PaymentTransactionState::PENDING->value)
            ->filterByPspReference($uuid)
            ->find();

        foreach ($pendingCaptures as $capture) {
            $this->recorder->settle($capture, PaymentTransactionState::FAILED, null, self::ERROR_CODE_ENDED_AT_PLATFORM, 'PayZen ended the transaction before its remittance: the capture was not taken.', PayzenEmbedded::getModuleCode());
        }

        $record();
    }

    private function capture(Order $order, string $amount, string $uuid): void
    {
        $this->recorder->recordCapture($order, $amount, $uuid, moduleCode: PayzenEmbedded::getModuleCode());

        // The platform let the rest of the authorization go with this capture.
        if (PaymentAmount::isPositive($this->totalsReader->forOrder((int) $order->getId())->remainingToCapture)) {
            $this->recorder->recordVoid($order, $uuid, moduleCode: PayzenEmbedded::getModuleCode());
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
