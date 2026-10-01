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

use PayzenEmbedded\LyraClient\GoverningTransaction;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The refresh records every attempt the platform lists without moving the order: the ones a
 * notification of theirs would move the order onto are named, so that the administrator replays
 * that notification.
 */
final class OutrankingAttemptsTest extends TestCase
{
    /**
     * @return iterable<string, array{list<TransactionOutcome>, string, list<string>}>
     */
    public static function histories(): iterable
    {
        $refusedA = self::debit('a', 'UNPAID', '2026-09-30 10:00:00');
        $paidA = self::debit('a', 'PAID', '2026-09-30 10:00:00');
        $runningB = self::debit('b', 'RUNNING', '2026-09-30 10:05:00');
        $runningA = self::debit('a', 'RUNNING', '2026-09-30 10:00:00');
        $paidB = self::debit('b', 'PAID', '2026-09-30 10:05:00');
        $refusedEarlier = self::debit('w', 'UNPAID', '2026-09-30 09:55:00');
        $credit = new TransactionOutcome('c', 'PAID', new \DateTimeImmutable('2026-09-30 11:00:00'), TransactionOutcome::OPERATION_CREDIT, 1000, '', 'a');

        yield 'a later attempt still running over a refused order' => [[$refusedA, $runningB], 'a', ['b']];
        yield 'a payment over a refused order' => [[$refusedA, $paidB], 'a', ['b']];
        yield 'an earlier refusal over a refused order' => [[$refusedA, $refusedEarlier], 'a', []];
        yield 'nothing outranks a paid order but a later payment' => [[$paidA, $runningB, $refusedEarlier], 'a', []];
        yield 'a later payment over a paid order' => [[$paidA, $paidB], 'a', ['b']];
        yield 'a credit never outranks' => [[$refusedA, $credit], 'a', []];
        yield 'an order without a reference is warned about on its own' => [[$refusedA, $paidB], '', []];
        yield 'the attempt the order stands on alone' => [[$paidA], 'a', []];
        yield 'the attempt the order stands on, still running, never outranks itself' => [[$runningA], 'a', []];
        yield 'two listed payments on an order without a reference are warned about on its own' => [[$paidA, $paidB], '', []];
    }

    /**
     * @param list<TransactionOutcome> $history
     * @param list<string>             $outranking
     */
    #[DataProvider('histories')]
    public function testTheAttemptsANotificationWouldMoveTheOrderOnto(array $history, string $orderTransactionRef, array $outranking): void
    {
        self::assertSame($outranking, array_map(static fn (TransactionOutcome $attempt): string => $attempt->uuid, GoverningTransaction::outrankingAttempts($history, $orderTransactionRef)));
    }

    private static function debit(string $uuid, string $status, string $createdAt): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, new \DateTimeImmutable($createdAt), TransactionOutcome::OPERATION_DEBIT, 1000, 'PAID' === $status ? 'CAPTURED' : '');
    }
}
