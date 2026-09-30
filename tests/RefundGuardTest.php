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

use PayzenEmbedded\LyraClient\RefundGuard;
use PayzenEmbedded\LyraClient\RefundLedger;
use PayzenEmbedded\LyraClient\RefundVerdict;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decision taken before a refund is asked for: a page that lies is refused before the amount
 * is even looked at, then the amount has to be one the ledger allows.
 */
final class RefundGuardTest extends TestCase
{
    private const ORDER_DEBIT = 't1';

    /**
     * @return iterable<string, array{list<TransactionOutcome>, int, int|null, RefundVerdict}>
     */
    public static function verdicts(): iterable
    {
        $captured = [self::debit('PAID', 1000, 'CAPTURED')];
        $partlyRefunded = [self::debit('PAID', 1000, 'CAPTURED'), self::credit('r1', 'PAID', 300)];
        $refundRunning = [self::debit('PAID', 1000, 'CAPTURED'), self::credit('r1', 'RUNNING', 300)];
        $authorised = [self::debit('PAID', 1000, 'AUTHORISED')];
        $refused = [self::debit('UNPAID', 1000, 'REFUSED')];

        yield 'part of a captured payment, nothing seen' => [$captured, 400, null, RefundVerdict::Allowed];
        yield 'the whole captured payment' => [$captured, 1000, null, RefundVerdict::Allowed];
        yield 'more than the captured payment' => [$captured, 1001, null, RefundVerdict::OutOfBounds];
        yield 'nothing' => [$captured, 0, null, RefundVerdict::OutOfBounds];
        yield 'a page that saw no refund on an unrefunded payment' => [$captured, 400, 0, RefundVerdict::Allowed];

        yield 'what is left after a refund, seen' => [$partlyRefunded, 700, 300, RefundVerdict::Allowed];
        yield 'one unit more than what is left' => [$partlyRefunded, 701, 300, RefundVerdict::OutOfBounds];
        yield 'a page that did not see the refund' => [$partlyRefunded, 700, 0, RefundVerdict::StaleView];
        yield 'a page that did not see the refund, whatever the amount' => [$partlyRefunded, 5000, 0, RefundVerdict::StaleView];
        yield 'a page that saw more than was refunded' => [$partlyRefunded, 100, 500, RefundVerdict::StaleView];
        yield 'nothing seen, the balance alone' => [$partlyRefunded, 700, null, RefundVerdict::Allowed];
        yield 'nothing seen, above the balance' => [$partlyRefunded, 701, null, RefundVerdict::OutOfBounds];

        yield 'a refund still running is promised, seen as such' => [$refundRunning, 700, 300, RefundVerdict::Allowed];
        yield 'a refund still running is promised, not seen' => [$refundRunning, 700, 0, RefundVerdict::StaleView];
        yield 'a refund still running is promised, above the balance' => [$refundRunning, 701, 300, RefundVerdict::OutOfBounds];
        yield 'a page that lies is refused before the amount is looked at' => [$refundRunning, 9999, 0, RefundVerdict::StaleView];
        yield 'a negative amount' => [$captured, -1, null, RefundVerdict::OutOfBounds];

        yield 'the whole authorisation to cancel' => [$authorised, 1000, 0, RefundVerdict::Allowed];
        yield 'part of an authorisation' => [$authorised, 999, 0, RefundVerdict::OutOfBounds];
        yield 'more than the authorisation' => [$authorised, 1001, null, RefundVerdict::OutOfBounds];

        yield 'a refused payment has nothing to give back' => [$refused, 1, null, RefundVerdict::OutOfBounds];
        yield 'a refused payment, whatever the page saw' => [$refused, 1, 0, RefundVerdict::OutOfBounds];
    }

    /**
     * @param list<TransactionOutcome> $transactions
     */
    #[DataProvider('verdicts')]
    public function testTheVerdict(array $transactions, int $amount, ?int $expectedRefundedAmount, RefundVerdict $verdict): void
    {
        $ledger = RefundLedger::fromTransactions($transactions, self::ORDER_DEBIT);

        self::assertSame($verdict, RefundGuard::verdict($ledger, $amount, $expectedRefundedAmount));
    }

    public function testEveryVerdictIsReached(): void
    {
        $reached = [];

        foreach (self::verdicts() as [, , , $verdict]) {
            $reached[$verdict->value] = true;
        }

        self::assertEqualsCanonicalizing(array_map(static fn (RefundVerdict $verdict): string => $verdict->value, RefundVerdict::cases()), array_keys($reached));
    }

    private static function debit(string $status, int $amount, string $detailedStatus): TransactionOutcome
    {
        return new TransactionOutcome(self::ORDER_DEBIT, $status, null, TransactionOutcome::OPERATION_DEBIT, $amount, $detailedStatus);
    }

    private static function credit(string $uuid, string $status, int $amount): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, null, TransactionOutcome::OPERATION_CREDIT, $amount, '', self::ORDER_DEBIT);
    }
}
