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
 * What a shop may still give back on an order: the money it received, less the money it already
 * returned. Amounts are in the smallest unit of the currency, as the platform counts them.
 */
final class RefundLedgerTest extends TestCase
{
    public function testNothingIsRefundableBeforeThePaymentIsMade(): void
    {
        $ledger = RefundLedger::fromTransactions([$this->debit('t1', 'RUNNING', 1000)]);

        self::assertSame(0, $ledger->refundableAmount());
        self::assertFalse($ledger->covers(1));
    }

    public function testTheWholePaymentIsRefundableOnceItIsPaid(): void
    {
        $ledger = RefundLedger::fromTransactions([$this->debit('t1', 'PAID', 1000)]);

        self::assertSame(1000, $ledger->paidAmount);
        self::assertSame(1000, $ledger->refundableAmount());
        self::assertTrue($ledger->covers(1000));
        self::assertFalse($ledger->covers(1001));
        self::assertFalse($ledger->covers(0));
    }

    public function testEachRefundLowersWhatIsLeftToRefund(): void
    {
        $ledger = RefundLedger::fromTransactions([
            $this->debit('t1', 'PAID', 1000),
            $this->credit('r1', 'PAID', 300),
            $this->credit('r2', 'PAID', 200),
        ]);

        self::assertSame(500, $ledger->refundedAmount);
        self::assertSame(500, $ledger->refundableAmount());
        self::assertTrue($ledger->isSettledBy(500));
        self::assertFalse($ledger->isSettledBy(499));
    }

    public function testARefusedRefundGivesNothingBack(): void
    {
        $ledger = RefundLedger::fromTransactions([
            $this->debit('t1', 'PAID', 1000),
            $this->credit('r1', 'UNPAID', 300),
        ]);

        self::assertSame(1000, $ledger->refundableAmount());
    }

    public function testACancelledPaymentLeavesNothingToRefund(): void
    {
        $ledger = RefundLedger::fromTransactions([$this->debit('t1', 'UNPAID', 1000)]);

        self::assertSame(0, $ledger->refundableAmount());
        self::assertTrue($ledger->isSettledBy(0));
    }

    public function testOnlyThePaidAttemptOfARetriedOrderCounts(): void
    {
        $ledger = RefundLedger::fromTransactions([
            $this->debit('t1', 'UNPAID', 1000),
            $this->debit('t2', 'PAID', 1000),
        ]);

        self::assertSame(1000, $ledger->paidAmount);
    }

    public function testAnAuthorisationAwaitingItsCaptureCanBeCancelledInFull(): void
    {
        $ledger = RefundLedger::fromTransactions([$this->debit('t1', 'RUNNING', 1000)]);

        self::assertSame(1000, $ledger->authorisedAmount);
        self::assertSame(0, $ledger->refundableAmount());
        self::assertTrue($ledger->isCancellable());
        self::assertTrue($ledger->allows(1000));
        self::assertFalse($ledger->allows(999));
    }

    public function testAPaidTransactionIsNotCancellableButRefundable(): void
    {
        $ledger = RefundLedger::fromTransactions([$this->debit('t1', 'PAID', 1000)]);

        self::assertFalse($ledger->isCancellable());
        self::assertTrue($ledger->allows(400));
        self::assertTrue($ledger->allows(1000));
        self::assertFalse($ledger->allows(1001));
    }

    private function debit(string $uuid, string $status, int $amount): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, null, TransactionOutcome::OPERATION_DEBIT, $amount);
    }

    private function credit(string $uuid, string $status, int $amount): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, null, TransactionOutcome::OPERATION_CREDIT, $amount);
    }
}
