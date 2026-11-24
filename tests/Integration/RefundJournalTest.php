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

namespace PayzenEmbedded\Tests\Integration;

use PayzenEmbedded\Event\RefundReportedEvent;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;
use Thelia\Domain\Payment\Exception\PaymentJournalBusyException;
use Thelia\Domain\Payment\Service\PaymentTransactionTotalsReader;

/**
 * What PayZen reports of a refund, written to Thelia's payment journal under the uuid of the refund
 * transaction: by the notification for a refund made or refused from the PayZen back-office, and as
 * soon as PayZen answers for a refund asked from the order page. Written once, whoever reports it.
 */
final class RefundJournalTest extends RefundIntegrationTestCase
{
    public function testARefundMadeFromThePayZenBackOfficeIsWrittenByItsNotification(): void
    {
        [$order, $debit] = $this->paidOrder();

        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $this->credit($order, $debit, 'bbbb0000000000000000000000000001', 2000)));

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame(PaymentTransactionState::SUCCEEDED->value, $refunds[0]->getState());
        self::assertSame('bbbb0000000000000000000000000001', $refunds[0]->getPspReference());
        self::assertSame('20.000000', $refunds[0]->getAmount());
        self::assertSame($this->journal($order, PaymentTransactionType::CAPTURE)[0]->getId(), $refunds[0]->getParentId(), 'It gives back what the capture took.');
        self::assertSame(PayzenEmbedded::getModuleCode(), $refunds[0]->getActorLabel());
        self::assertTrue($this->reread($order)->isPaid(false), 'A partial refund leaves the order as it is.');
        self::assertSame('80.000000', $this->getService(PaymentTransactionTotalsReader::class)->forOrder((int) $order->getId())->refundable());
    }

    public function testARefundInFullMadeFromThePayZenBackOfficeLeavesOneLineBeforeTheOrderIsSetRefunded(): void
    {
        [$order, $debit] = $this->paidOrder();

        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $this->credit($order, $debit, 'bbbb0000000000000000000000000002', 10000)));

        self::assertTrue($this->reread($order)->isRefunded());
        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds, 'Thelia deduces no line of its own from the status.');
        self::assertSame('bbbb0000000000000000000000000002', $refunds[0]->getPspReference(), 'The line is the refund PayZen reported.');
        self::assertNull($refunds[0]->getErrorCode());
    }

    public function testARefundPayZenRefusedFromItsBackOfficeIsWrittenAsFailedWithItsCode(): void
    {
        [$order, $debit] = $this->paidOrder();
        $refused = $this->credit($order, $debit, 'bbbb0000000000000000000000000003', 2000, 'UNPAID', ['errorCode' => 'PSP_098', 'errorMessage' => 'Card closed']);

        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $refused));

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame(PaymentTransactionState::FAILED->value, $refunds[0]->getState());
        self::assertSame('PSP_098', $refunds[0]->getErrorCode());
        self::assertStringContainsString('Card closed', (string) $refunds[0]->getErrorMessage());
        self::assertSame('bbbb0000000000000000000000000003', $refunds[0]->getPspReference());
        self::assertSame('100.000000', $this->getService(PaymentTransactionTotalsReader::class)->forOrder((int) $order->getId())->refundable(), 'A refused refund gave nothing back.');
    }

    public function testARefundStillRunningWritesNothingYet(): void
    {
        [$order, $debit] = $this->paidOrder();

        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $this->credit($order, $debit, 'bbbb0000000000000000000000000004', 2000, 'RUNNING')));

        self::assertSame([], $this->journal($order, PaymentTransactionType::REFUND));
    }

    public function testANotificationReplayedWritesNoSecondLine(): void
    {
        [$order, $debit] = $this->paidOrder();
        $notification = $this->notificationOf($order, $this->credit($order, $debit, 'bbbb0000000000000000000000000005', 2000));

        $this->wrapper()->processPaymentResponse($notification);
        $this->wrapper()->processPaymentResponse($notification);

        self::assertCount(1, $this->journal($order, PaymentTransactionType::REFUND));
    }

    public function testTheNotificationOfARefundInFullReplayedWritesNoSecondLine(): void
    {
        [$order, $debit] = $this->paidOrder();
        $notification = $this->notificationOf($order, $this->credit($order, $debit, 'bbbb0000000000000000000000000006', 10000));

        $this->wrapper()->processPaymentResponse($notification);
        $this->wrapper()->processPaymentResponse($notification);

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame('bbbb0000000000000000000000000006', $refunds[0]->getPspReference(), 'The replay finds the line by the uuid of the refund.');
        self::assertNull($refunds[0]->getErrorCode(), 'Not a line Thelia deduced from the status.');
        self::assertTrue($this->reread($order)->isRefunded());
    }

    public function testARefundFromTheOrderPageIsWrittenAsSoonAsPayZenAnswers(): void
    {
        [$order, $debit] = $this->paidOrder();
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $this->credit($order, $debit, 'bbbb0000000000000000000000000007', 3000)];

        $outcome = $this->platform->refundTransaction($order, 3000, 'Parcel came back');

        self::assertSame(RefundOutcome::PartiallyRefunded, $outcome);
        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds, 'No notification came yet.');
        self::assertSame('bbbb0000000000000000000000000007', $refunds[0]->getPspReference());
        self::assertSame(PaymentTransactionState::SUCCEEDED->value, $refunds[0]->getState());
    }

    public function testARefundInFullFromTheOrderPageLeavesOneLineAndItsNotificationWritesNoSecond(): void
    {
        [$order, $debit] = $this->paidOrder();
        $credit = $this->credit($order, $debit, 'bbbb0000000000000000000000000008', 10000);
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $credit];

        self::assertSame(RefundOutcome::Refunded, $this->platform->refundTransaction($order, 10000));
        self::assertCount(1, $this->journal($order, PaymentTransactionType::REFUND));

        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $credit));

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame('bbbb0000000000000000000000000008', $refunds[0]->getPspReference());
        self::assertTrue($this->reread($order)->isRefunded());
    }

    public function testARefundTheJournalCannotWriteYetIsStillARefund(): void
    {
        // The journal learns it from the platform's notification: the money moved all the same.
        [$order, $debit] = $this->paidOrder();
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $this->credit($order, $debit, 'bbbb0000000000000000000000000009', 4000)];
        $busy = static function (): never {
            throw new PaymentJournalBusyException('Another worker holds the journal.');
        };
        $this->dispatcher()->addListener(PayzenEmbedded::REFUND_REPORTED_EVENT, $busy, 255);

        try {
            $outcome = $this->platform->refundTransaction($order, 4000);
        } finally {
            $this->dispatcher()->removeListener(PayzenEmbedded::REFUND_REPORTED_EVENT, $busy);
        }

        self::assertSame(RefundOutcome::PartiallyRefunded, $outcome);
        self::assertSame([], $this->journal($order, PaymentTransactionType::REFUND));

        // Then its notification writes it.
        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $this->credit($order, $debit, 'bbbb0000000000000000000000000009', 4000)));

        self::assertCount(1, $this->journal($order, PaymentTransactionType::REFUND));
    }

    public function testAPaymentCancelledBeforeItsRemittanceIsNotReportedAsARefundTransaction(): void
    {
        // The platform answers such a refund with the debit itself, cancelled: not a refund
        // transaction, whose uuid is the debit's and which the journal would file as a refused refund.
        [$order, $debit] = $this->paidOrder('AUTHORISED');
        $reported = [];
        $this->dispatcher()->addListener(PayzenEmbedded::REFUND_REPORTED_EVENT, static function (RefundReportedEvent $event) use (&$reported): void {
            $reported[] = $event->getAnswer()['uuid'];
        }, 255);
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => [
            'uuid' => $debit,
            'status' => 'UNPAID',
            'detailedStatus' => 'CANCELLED',
            'operationType' => 'DEBIT',
            'amount' => 10000,
            'currency' => 'EUR',
            'creationDate' => (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM),
            'orderDetails' => ['orderId' => $order->getRef(), 'mode' => PayzenEmbedded::platformMode()],
        ]];

        self::assertSame(RefundOutcome::Cancelled, $this->platform->refundTransaction($order, 10000));

        self::assertSame([], $reported, 'A debit is none of the refund journal\'s business.');
        self::assertSame([], $this->journal($order, PaymentTransactionType::REFUND));
        self::assertSame([], $this->journal($order, PaymentTransactionType::AUTHORIZATION), 'The module writes no authorization.');
        self::assertCount(1, $this->journal($order, PaymentTransactionType::CAPTURE));
    }
}
