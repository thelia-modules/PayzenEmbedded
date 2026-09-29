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

namespace PayzenEmbedded\Tests;

use PayzenEmbedded\LyraClient\RefundLedger;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\TestCase;

/**
 * What a shop may still give back on an order: the money it received on the transaction the
 * order stands on, less the money it already returned or is returning, and the authorisation
 * still waiting for its capture, which can only be cancelled in full. Amounts are in the
 * smallest unit of the currency, as the platform counts them.
 */
final class RefundLedgerTest extends TestCase
{
    private const ORDER_DEBIT = 't1';

    public function testNothingIsRefundableBeforeThePaymentIsMade(): void
    {
        $ledger = $this->ledger([$this->debit(self::ORDER_DEBIT, 'RUNNING', 1000, 'AUTHORISED_TO_VALIDATE')]);

        self::assertSame(0, $ledger->refundableAmount());
        self::assertFalse($ledger->covers(1));
    }

    public function testACapturedPaymentIsRefundableInFull(): void
    {
        $ledger = $this->ledger([$this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED')]);

        self::assertSame(1000, $ledger->paidAmount);
        self::assertSame(1000, $ledger->refundableAmount());
        self::assertFalse($ledger->isCancellable());
        self::assertTrue($ledger->allows(400));
        self::assertTrue($ledger->allows(1000));
        self::assertFalse($ledger->allows(1001));
        self::assertFalse($ledger->allows(0));
    }

    public function testAPaymentWithAnUnknownDetailedStatusCountsAsCaptured(): void
    {
        $ledger = $this->ledger([$this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'Some legacy message')]);

        self::assertSame(1000, $ledger->refundableAmount());
        self::assertFalse($ledger->isCancellable());
    }

    public function testEachRefundLowersWhatIsLeftToRefund(): void
    {
        $ledger = $this->ledger([
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED'),
            $this->credit('r1', 'PAID', 300),
            $this->credit('r2', 'PAID', 200),
        ]);

        self::assertSame(500, $ledger->refundedAmount);
        self::assertSame(500, $ledger->refundableAmount());
    }

    public function testARefundStillRunningIsMoneyAlreadyPromised(): void
    {
        $ledger = $this->ledger([
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED'),
            $this->credit('r1', 'RUNNING', 300),
        ]);

        self::assertSame(300, $ledger->refundedAmount);
        self::assertSame(700, $ledger->refundableAmount());
    }

    public function testARefusedRefundGivesNothingBack(): void
    {
        $ledger = $this->ledger([
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED'),
            $this->credit('r1', 'UNPAID', 300),
        ]);

        self::assertSame(1000, $ledger->refundableAmount());
    }

    public function testACancelledPaymentLeavesNothingToRefund(): void
    {
        $ledger = $this->ledger([$this->debit(self::ORDER_DEBIT, 'UNPAID', 1000, 'CANCELLED')]);

        self::assertSame(0, $ledger->refundableAmount());
        self::assertFalse($ledger->isCancellable());
        self::assertSame(0, $ledger->maximumAmount());
    }

    public function testAnAuthorisationAwaitingItsValidationCanOnlyBeCancelledInFull(): void
    {
        $ledger = $this->ledger([$this->debit(self::ORDER_DEBIT, 'RUNNING', 1000, 'AUTHORISED_TO_VALIDATE')]);

        self::assertTrue($ledger->isCancellable());
        self::assertSame(1000, $ledger->maximumAmount());
        self::assertTrue($ledger->allows(1000));
        self::assertFalse($ledger->allows(999));
    }

    public function testAnAuthorisationAwaitingItsCaptureCanOnlyBeCancelledInFull(): void
    {
        $ledger = $this->ledger([$this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'AUTHORISED')]);

        self::assertTrue($ledger->isCancellable());
        self::assertSame(0, $ledger->refundableAmount());
        self::assertTrue($ledger->allows(1000));
        self::assertFalse($ledger->allows(500));
    }

    public function testAnAuthorisationWithACreditAlreadyRecordedCannotBeCancelled(): void
    {
        $ledger = $this->ledger([
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'AUTHORISED'),
            $this->credit('r1', 'PAID', 300),
        ]);

        self::assertFalse($ledger->isCancellable());
        self::assertFalse($ledger->allows(1000));
        self::assertSame(0, $ledger->maximumAmount());
    }

    /**
     * The administrator decides on a page that shows what was refunded so far. The ledger tells
     * whether it still holds exactly that, so that a refund the page did not show (an answer lost
     * to a timeout, a refund made from the PayZen back-office) is seen before more is given back.
     */
    public function testTheLedgerTellsWhetherItHoldsTheRefundsTheAdministratorSaw(): void
    {
        $ledger = $this->ledger([
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED'),
            $this->credit('r1', 'PAID', 300),
        ]);

        self::assertTrue($ledger->hasRefunded(300));
        self::assertFalse($ledger->hasRefunded(0));
        self::assertFalse($ledger->hasRefunded(600));
    }

    public function testOnlyTheTransactionTheOrderStandsOnCounts(): void
    {
        $ledger = $this->ledger([
            $this->debit('abandoned', 'RUNNING', 1000, 'AUTHORISED_TO_VALIDATE'),
            $this->debit('refused', 'UNPAID', 1000, 'REFUSED'),
            $this->debit(self::ORDER_DEBIT, 'RUNNING', 1000, 'AUTHORISED_TO_VALIDATE'),
        ]);

        self::assertSame(1000, $ledger->authorisedAmount);
        self::assertTrue($ledger->allows(1000));
    }

    public function testTwoPaidAttemptsOnlyCountTheOneTheOrderStandsOn(): void
    {
        $ledger = $this->ledger([
            $this->debit('other', 'PAID', 1000, 'CAPTURED'),
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED'),
        ]);

        self::assertSame(1000, $ledger->paidAmount);
    }

    public function testACreditOfAnotherDebitIsNotDeductedFromThisOne(): void
    {
        $ledger = $this->ledger([
            $this->debit('other', 'PAID', 1000, 'CAPTURED'),
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED'),
            new TransactionOutcome('c-other', 'PAID', null, TransactionOutcome::OPERATION_CREDIT, 1000, '', 'other'),
        ]);

        self::assertSame(0, $ledger->refundedAmount);
        self::assertSame(1000, $ledger->refundableAmount());
    }

    public function testACreditWithoutAKnownParentIsDeductedAllTheSame(): void
    {
        $ledger = $this->ledger([
            $this->debit(self::ORDER_DEBIT, 'PAID', 1000, 'CAPTURED'),
            new TransactionOutcome('c-legacy', 'PAID', null, TransactionOutcome::OPERATION_CREDIT, 300),
        ]);

        self::assertSame(300, $ledger->refundedAmount);
    }

    /**
     * The rows written by older versions belong to orders without a transaction reference: every
     * debit counts there, and nothing can be cancelled once one of them is captured.
     */
    public function testWithoutAReferenceEveryCapturedDebitCounts(): void
    {
        $ledger = RefundLedger::fromTransactions([
            $this->debit('legacy-1', 'PAID', 800, 'CAPTURED'),
            $this->debit('legacy-2', 'PAID', 200, 'CAPTURED'),
        ], '');

        self::assertSame(1000, $ledger->paidAmount);
        self::assertSame(1000, $ledger->refundableAmount());
    }

    public function testWithoutAReferenceACapturedDebitAndAnAuthorisationAreNotCancellable(): void
    {
        $ledger = RefundLedger::fromTransactions([
            $this->debit('legacy-1', 'PAID', 1000, 'CAPTURED'),
            $this->debit('legacy-2', 'PAID', 500, 'AUTHORISED'),
        ], '');

        self::assertFalse($ledger->isCancellable());
        self::assertSame(1000, $ledger->maximumAmount());
    }

    public function testWithoutAReferenceACreditOfTheDebitIsDeducted(): void
    {
        $ledger = RefundLedger::fromTransactions([
            $this->debit('legacy-1', 'PAID', 1000, 'CAPTURED'),
            new TransactionOutcome('c1', 'PAID', null, TransactionOutcome::OPERATION_CREDIT, 1000, '', 'legacy-1'),
        ], '');

        self::assertSame(0, $ledger->refundableAmount());
    }

    /**
     * @param list<TransactionOutcome> $transactions
     */
    private function ledger(array $transactions): RefundLedger
    {
        return RefundLedger::fromTransactions($transactions, self::ORDER_DEBIT);
    }

    private function debit(string $uuid, string $status, int $amount, string $detailedStatus): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, null, TransactionOutcome::OPERATION_DEBIT, $amount, $detailedStatus);
    }

    private function credit(string $uuid, string $status, int $amount): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, null, TransactionOutcome::OPERATION_CREDIT, $amount);
    }
}
