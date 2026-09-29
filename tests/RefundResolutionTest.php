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
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\RefundResolution;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thelia\Exception\TheliaProcessException;

/**
 * What the platform's answer to Transaction/CancelOrRefund means for the order. The shape of that
 * answer was taken from the documentation: a refund is a credit transaction of its own, a
 * cancellation brings the debit back as UNPAID / CANCELLED. Anything else is refused, never read
 * as a cancellation.
 */
final class RefundResolutionTest extends TestCase
{
    private const DEBIT_UUID = 'debit-1';

    public function testACreditThatLeavesNothingToRefundMeansRefunded(): void
    {
        $resolution = RefundResolution::fromAnswer(
            ['uuid' => 'credit-1', 'status' => 'PAID', 'operationType' => 'CREDIT', 'amount' => 1000],
            self::DEBIT_UUID,
        );

        self::assertTrue($resolution->isCredit());
        self::assertSame(RefundOutcome::Refunded, $resolution->outcome($this->ledger(paid: 1000, refunded: 1000)));
    }

    public function testACreditThatLeavesSomethingMeansPartiallyRefunded(): void
    {
        $resolution = RefundResolution::fromAnswer(
            ['uuid' => 'credit-1', 'status' => 'PAID', 'operationType' => 'CREDIT', 'amount' => 300],
            self::DEBIT_UUID,
        );

        self::assertSame(RefundOutcome::PartiallyRefunded, $resolution->outcome($this->ledger(paid: 1000, refunded: 300)));
    }

    public function testATransactionOfItsOwnWithoutAnOperationTypeIsACredit(): void
    {
        $resolution = RefundResolution::fromAnswer(
            ['uuid' => 'credit-1', 'status' => 'PAID', 'amount' => 300],
            self::DEBIT_UUID,
        );

        self::assertTrue($resolution->isCredit());
        self::assertSame(TransactionOutcome::OPERATION_CREDIT, $resolution->answer['operationType']);
    }

    public function testTheDebitBackAsCancelledMeansCancelled(): void
    {
        $resolution = RefundResolution::fromAnswer(
            ['uuid' => self::DEBIT_UUID, 'status' => 'UNPAID', 'detailedStatus' => 'CANCELLED', 'amount' => 1000],
            self::DEBIT_UUID,
        );

        self::assertFalse($resolution->isCredit());
        self::assertSame(RefundOutcome::Cancelled, $resolution->outcome($this->ledger(paid: 0, refunded: 0)));
    }

    public function testACreditStillRunningIsPendingAndLeavesTheOrderAlone(): void
    {
        $resolution = RefundResolution::fromAnswer(
            ['uuid' => 'credit-1', 'status' => 'RUNNING', 'operationType' => 'CREDIT', 'amount' => 300],
            self::DEBIT_UUID,
        );

        self::assertTrue($resolution->isCredit());
        self::assertSame(RefundOutcome::Pending, $resolution->outcome($this->ledger(paid: 1000, refunded: 300)));
    }

    public function testARefusedCreditIsRefused(): void
    {
        $this->expectException(TheliaProcessException::class);

        RefundResolution::fromAnswer(
            ['uuid' => 'credit-1', 'status' => 'UNPAID', 'operationType' => 'CREDIT', 'errorCode' => 'PSP_100', 'errorMessage' => 'refused'],
            self::DEBIT_UUID,
        );
    }

    public function testACreditInAnUnknownStatusIsRefused(): void
    {
        $this->expectException(TheliaProcessException::class);

        RefundResolution::fromAnswer(
            ['uuid' => 'credit-1', 'status' => 'PARTIALLY_PAID', 'operationType' => 'CREDIT', 'amount' => 300],
            self::DEBIT_UUID,
        );
    }

    public function testTheDebitStillRunningIsNotReadAsACancellation(): void
    {
        $this->expectException(TheliaProcessException::class);

        RefundResolution::fromAnswer(
            ['uuid' => self::DEBIT_UUID, 'status' => 'RUNNING', 'detailedStatus' => 'AUTHORISED_TO_VALIDATE', 'amount' => 1000],
            self::DEBIT_UUID,
        );
    }

    public function testTheDebitStillPaidIsNotReadAsACancellation(): void
    {
        $this->expectException(TheliaProcessException::class);

        RefundResolution::fromAnswer(
            ['uuid' => self::DEBIT_UUID, 'status' => 'PAID', 'detailedStatus' => 'CAPTURED', 'amount' => 1000],
            self::DEBIT_UUID,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpaidDebitStatuses(): iterable
    {
        yield 'expired authorisation' => ['EXPIRED'];
        yield 'refused by the bank' => ['REFUSED'];
        yield 'abandoned by the shopper' => ['ABANDONED'];
        yield 'no detailed status at all' => [''];
    }

    /**
     * An order is never cancelled on a guess: the debit back as UNPAID for any other reason than
     * a cancellation (an authorisation that expired between the ledger check and the call, for
     * instance) is refused, not read as "cancelled".
     */
    #[DataProvider('unpaidDebitStatuses')]
    public function testTheDebitUnpaidForAnotherReasonThanACancellationIsNotReadAsACancellation(string $detailedStatus): void
    {
        $this->expectException(TheliaProcessException::class);

        RefundResolution::fromAnswer(
            ['uuid' => self::DEBIT_UUID, 'status' => 'UNPAID', 'detailedStatus' => $detailedStatus, 'amount' => 1000],
            self::DEBIT_UUID,
        );
    }

    public function testAnAnswerWithoutTransactionIsRefused(): void
    {
        $this->expectException(TheliaProcessException::class);

        RefundResolution::fromAnswer(['errorCode' => 'INT_001', 'errorMessage' => 'bad request'], self::DEBIT_UUID);
    }

    private function ledger(int $paid, int $refunded): RefundLedger
    {
        $transactions = [new TransactionOutcome('d', 'PAID', null, TransactionOutcome::OPERATION_DEBIT, $paid, 'CAPTURED')];

        if ($refunded > 0) {
            $transactions[] = new TransactionOutcome('c', 'PAID', null, TransactionOutcome::OPERATION_CREDIT, $refunded);
        }

        return RefundLedger::fromTransactions($transactions, 'd');
    }
}
