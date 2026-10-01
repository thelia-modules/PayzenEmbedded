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
use PayzenEmbedded\LyraClient\NotificationArbiter;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A notification is weighed against what the order was moved on. Every move sets the order's
 * transaction reference, and the refresh records every transaction the platform lists without
 * moving the order: an order without a reference takes any payment, and a refusal is weighed
 * against a payment the platform lists, never against another listed refusal.
 */
final class NotificationAfterRefreshTest extends TestCase
{
    /**
     * @return iterable<string, array{list<TransactionOutcome>, string, TransactionOutcome, bool}>
     */
    public static function notifications(): iterable
    {
        $paid = self::debit('x', 'PAID', '2026-09-30 10:00:00');
        $refusedLater = self::debit('y', 'UNPAID', '2026-09-30 10:05:00');
        $refusedEarlier = self::debit('w', 'UNPAID', '2026-09-30 09:55:00');
        $running = self::debit('x', 'RUNNING', '2026-09-30 10:00:00');
        $secondPayment = self::debit('z', 'PAID', '2026-09-30 10:10:00');
        $runningOther = self::debit('r', 'RUNNING', '2026-09-30 09:50:00');
        $runningLater = self::debit('q', 'RUNNING', '2026-09-30 10:20:00');
        $paidRefusedNow = self::debit('x', 'UNPAID', '2026-09-30 10:00:00');
        $credit = new TransactionOutcome('c', 'PAID', new \DateTimeImmutable('2026-09-30 11:00:00'), TransactionOutcome::OPERATION_CREDIT, 400, '', 'x');

        yield 'the refreshed payment, notified again on an order never moved' => [[$paid], '', $paid, true];
        yield 'the refreshed payment, replayed after the order was moved on it' => [[$paid], 'x', $paid, false];
        yield 'a refusal notified after a listed payment, order never moved' => [[$paid, $refusedLater], '', $refusedLater, false];
        yield 'the listed payment notified after a listed earlier refusal' => [[$refusedEarlier, $paid], '', $paid, true];
        yield 'a payment listed while running, notified paid' => [[$running], '', $paid, true];
        yield 'a listed credit does not stand in for the payment' => [[$credit, $paid], '', $paid, true];
        yield 'an empty history accepts the first notification' => [[], '', $paid, true];
        yield 'an order moved on a payment refuses a later refusal' => [[$paid, $refusedLater], 'x', $refusedLater, false];
        yield 'the listed payment notified after a later listed refusal, order never moved' => [[$paid, $refusedLater], '', $paid, true];
        yield 'a second listed payment does not stop the first, order never moved' => [[$paid, $secondPayment], '', $paid, true];
        yield 'a refusal meets the latest listed payment' => [[$paid, $secondPayment, $refusedLater], '', $refusedLater, false];
        yield 'a refusal after a listed refusal only, order never moved' => [[$refusedEarlier, $refusedLater], '', $refusedLater, true];
        yield 'an earlier refusal notified after a later listed refusal, order never moved' => [[$refusedEarlier, $refusedLater], '', $refusedEarlier, true];
        yield 'an earlier refusal notified after a later listed attempt still running, order never moved' => [[$refusedEarlier, $runningLater], '', $refusedEarlier, true];
        yield 'a refusal after a payment listed while running, order never moved' => [[$runningOther, $refusedLater], '', $refusedLater, true];
        yield 'the refusal of the listed payment itself, order never moved' => [[$paid], '', $paidRefusedNow, true];
    }

    /**
     * @param list<TransactionOutcome> $history
     */
    #[DataProvider('notifications')]
    public function testTheNotificationIsWeighedAgainstWhatMovedTheOrder(array $history, string $orderTransactionRef, TransactionOutcome $incoming, bool $accepted): void
    {
        $applied = GoverningTransaction::appliedBefore($history, $orderTransactionRef, $incoming);

        self::assertSame($accepted, (new NotificationArbiter())->accepts($incoming, $applied));
    }

    private static function debit(string $uuid, string $status, string $createdAt): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, new \DateTimeImmutable($createdAt), TransactionOutcome::OPERATION_DEBIT, 1000, 'PAID' === $status ? 'CAPTURED' : '');
    }
}
