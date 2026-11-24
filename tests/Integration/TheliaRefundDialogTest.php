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

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;
use Thelia\Domain\Payment\Enum\RefundReason;
use Thelia\Domain\Payment\Exception\InvalidPaymentAmountException;
use Thelia\Domain\Payment\Exception\PaymentProviderUnreachableException;
use Thelia\Domain\Payment\Exception\PaymentRefusedException;
use Thelia\Domain\Payment\Service\PaymentRefundService;
use Thelia\Domain\Payment\Service\PaymentTransactionTotalsReader;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransaction;

/**
 * The refund dialog of Thelia, through the refund contract of the module: PayZen gives the money back
 * with the call of the module's own form, and the journal holds one line per refund, under the uuid of
 * the transaction PayZen made it with. The platform is scripted, see RecordingRefundClient.
 */
final class TheliaRefundDialogTest extends RefundIntegrationTestCase
{
    public function testAnOrderPayZenTookHasWhatToBeRefundedWithoutTheModuleWritingAnythingToTheJournal(): void
    {
        [$order, $debit] = $this->paidOrder();

        self::assertSame([], $this->journal($order, PaymentTransactionType::AUTHORIZATION), 'The module writes no authorization.');
        $captures = $this->journal($order, PaymentTransactionType::CAPTURE);
        self::assertCount(1, $captures, 'The core wrote the capture of the order paid: nothing else did.');
        self::assertSame('100.000000', $captures[0]->getAmount());
        self::assertSame($debit, $captures[0]->getPspReference());
        self::assertSame('100.000000', $this->getService(PaymentTransactionTotalsReader::class)->forOrder((int) $order->getId())->refundable());
        self::assertTrue($this->getService(PaymentRefundService::class)->supportsRefund($order), 'PayZen refunds through Thelia.');
    }

    public function testAPartialRefundGivesTheMoneyBackThroughPayZenAndIsFiledUnderTheRefundTransaction(): void
    {
        [$order, $debit] = $this->paidOrder();
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $this->credit($order, $debit, 'aaaa0000000000000000000000000001', 3000)];

        $line = $this->getService(PaymentRefundService::class)->refund($order, 30.0, RefundReason::Returned, 'Parcel came back');

        self::assertTrue($line->isSucceeded());
        self::assertSame('aaaa0000000000000000000000000001', $line->getPspReference());
        self::assertSame('30.000000', $line->getAmount());
        $request = $this->platform->refundRequests()[0];
        self::assertSame($debit, $request['uuid']);
        self::assertSame(3000, $request['amount'], 'The amount goes to the platform in the minor unit.');
        self::assertSame('EUR', $request['currency']);
        self::assertSame('Goods returned: Parcel came back', $request['comment']);
        self::assertSame(1, \count($this->platform->refundRequests()));
        self::assertTrue($this->reread($order)->isPaid(false), 'A partial refund leaves the order as it is.');
        self::assertCount(1, $this->journal($order, PaymentTransactionType::REFUND));
        self::assertSame('70.000000', $this->getService(PaymentTransactionTotalsReader::class)->forOrder((int) $order->getId())->refundable());
    }

    public function testARefundInFullLeavesExactlyOneRefundLineEvenThoughTheModuleSetsTheOrderRefunded(): void
    {
        [$order, $debit] = $this->paidOrder();
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $this->credit($order, $debit, 'aaaa0000000000000000000000000002', 10000)];

        $line = $this->getService(PaymentRefundService::class)->refund($order, 100.0, RefundReason::Returned);

        self::assertTrue($line->isSucceeded());
        self::assertTrue($this->reread($order)->isRefunded(), 'The module sets the order refunded.');
        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds, 'Thelia deduces no refund of its own from the status the module set.');
        self::assertSame('aaaa0000000000000000000000000002', $refunds[0]->getPspReference());
        self::assertNull($refunds[0]->getErrorCode(), 'The line is the refund PayZen reported, not one deduced from the status.');
    }

    public function testTheNotificationOfTheRefundTheliaAskedWritesNoSecondLine(): void
    {
        [$order, $debit] = $this->paidOrder();
        $credit = $this->credit($order, $debit, 'aaaa0000000000000000000000000003', 4000);
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $credit];
        $this->getService(PaymentRefundService::class)->refund($order, 40.0, RefundReason::Other);

        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $credit));

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame('aaaa0000000000000000000000000003', $refunds[0]->getPspReference());
    }

    public function testARefundPayZenRefusesIsFailedWithTheCodeOfThePlatform(): void
    {
        [$order] = $this->paidOrder();
        $this->platform->refundAnswer = ['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_096', 'errorMessage' => 'Refund too late']];

        try {
            $this->getService(PaymentRefundService::class)->refund($order, 30.0, RefundReason::Other);
            self::fail('The refusal must reach the dialog.');
        } catch (PaymentRefusedException $refusal) {
            self::assertSame('PSP_096', $refusal->getProviderCode());
        }

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame(PaymentTransactionState::FAILED->value, $refunds[0]->getState());
        self::assertSame('PSP_096', $refunds[0]->getErrorCode(), 'The code of the platform stands apart from its message.');
        self::assertStringContainsString('Refund too late', (string) $refunds[0]->getErrorMessage());
        self::assertTrue($this->reread($order)->isPaid(false));
    }

    public function testARefundTransactionPayZenRefusedIsFailedWithItsCode(): void
    {
        [$order, $debit] = $this->paidOrder();
        $refused = $this->credit($order, $debit, 'aaaa0000000000000000000000000004', 3000, 'UNPAID', ['errorCode' => 'PSP_097', 'errorMessage' => 'Refused by the bank']);
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $refused];

        try {
            $this->getService(PaymentRefundService::class)->refund($order, 30.0, RefundReason::Other);
            self::fail('The refusal must reach the dialog.');
        } catch (PaymentRefusedException $refusal) {
            self::assertSame('PSP_097', $refusal->getProviderCode());
        }

        self::assertSame(PaymentTransactionState::FAILED->value, $this->journal($order, PaymentTransactionType::REFUND)[0]->getState());
    }

    public function testAPlatformThatCannotBeAskedBeforeTheSendLeavesAFailedLineAndSendsNothing(): void
    {
        [$order] = $this->paidOrder();
        $this->platform->listFailure = new LyraException('Connection timed out');

        try {
            $this->getService(PaymentRefundService::class)->refund($order, 30.0, RefundReason::Other);
            self::fail('Nothing was refunded: the merchant must know.');
        } catch (PaymentRefusedException) {
        }

        self::assertSame([], $this->platform->refundRequests(), 'The refund was never sent.');
        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame(PaymentTransactionState::FAILED->value, $refunds[0]->getState());
    }

    public function testARefundSentWithoutAnAnswerLeavesThePendingLineForTheNotification(): void
    {
        [$order, $debit] = $this->paidOrder();
        $this->platform->refundAnswer = new LyraException('Connection reset');

        try {
            $this->getService(PaymentRefundService::class)->refund($order, 30.0, RefundReason::Other);
            self::fail('The outcome is unknown: the dialog must say so.');
        } catch (PaymentProviderUnreachableException) {
        }

        self::assertSame(1, \count($this->platform->refundRequests()), 'The refund was sent.');
        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame(PaymentTransactionState::PENDING->value, $refunds[0]->getState(), 'The platform may have given the money back.');

        // The platform did give it back: its notification settles the line, which no second one follows.
        $credit = $this->credit($order, $debit, 'aaaa0000000000000000000000000005', 3000);
        $this->wrapper()->processPaymentResponse($this->notificationOf($order, $credit));

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame(PaymentTransactionState::SUCCEEDED->value, $refunds[0]->getState());
        self::assertSame('aaaa0000000000000000000000000005', $refunds[0]->getPspReference());
    }

    public function testARefundPayZenIsStillProcessingIsSettledByItsNotification(): void
    {
        [$order, $debit] = $this->paidOrder();
        $running = $this->credit($order, $debit, 'aaaa0000000000000000000000000006', 3000, 'RUNNING');
        $this->platform->refundAnswer = ['status' => 'SUCCESS', 'answer' => $running];

        $line = $this->getService(PaymentRefundService::class)->refund($order, 30.0, RefundReason::Other);

        self::assertTrue($line->isPending());
        self::assertSame('aaaa0000000000000000000000000006', $line->getPspReference(), 'The reference is what the notification finds the line by.');
        self::assertTrue($this->reread($order)->isPaid(false));

        $this->wrapper()->processPaymentResponse($this->notificationOf($order, ['status' => 'PAID'] + $running));

        $refunds = $this->journal($order, PaymentTransactionType::REFUND);
        self::assertCount(1, $refunds);
        self::assertSame(PaymentTransactionState::SUCCEEDED->value, $refunds[0]->getState());
        self::assertSame($line->getId(), $refunds[0]->getId(), 'The line Thelia wrote is the one settled.');
    }

    public function testAPaymentNotRemittedYetIsGivenBackInFullOnly(): void
    {
        [$order, $debit] = $this->paidOrder('AUTHORISED');

        self::assertTrue((new PayzenEmbedded())->refundsOnlyInFull($order), 'Not remitted: cancelled whole.');
        self::assertTrue($this->getService(PaymentRefundService::class)->refundsOnlyInFull($order));

        try {
            $this->getService(PaymentRefundService::class)->refund($order, 30.0, RefundReason::Other);
            self::fail('A part of a payment not remitted cannot be given back.');
        } catch (InvalidPaymentAmountException) {
        }

        self::assertSame([], $this->platform->refundRequests(), 'Thelia refused before PayZen was asked.');

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

        $line = $this->getService(PaymentRefundService::class)->refund($order, 100.0, RefundReason::Cancellation);

        self::assertTrue($line->isSucceeded());
        self::assertSame($debit, $line->getPspReference());
        self::assertSame(10000, $this->platform->refundRequests()[0]['amount']);
        self::assertTrue($this->reread($order)->isCancelled(), 'The module cancels the order whose payment was cancelled.');
    }

    public function testAPaymentRemittedIsRefundedInPartAsWell(): void
    {
        [$order] = $this->paidOrder('CAPTURED');

        self::assertFalse((new PayzenEmbedded())->refundsOnlyInFull($order), 'Remitted: refunded in part as well.');
        self::assertFalse($this->getService(PaymentRefundService::class)->refundsOnlyInFull($order));
    }
}
