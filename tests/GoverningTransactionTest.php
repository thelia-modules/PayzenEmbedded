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
use PHPUnit\Framework\TestCase;

/**
 * The transaction the order stands on: the one its transaction reference names when the history
 * knows it, and only failing that the paid one, or the latest attempt the platform dated. A
 * credit never governs an order.
 */
final class GoverningTransactionTest extends TestCase
{
    public function testTheOrderReferenceWinsOverEveryHeuristic(): void
    {
        $governing = GoverningTransaction::among([
            $this->debit('t0', 'PAID', '2026-09-21 10:00:00'),
            $this->debit('t1', 'RUNNING', '2026-09-21 11:00:00'),
        ], 't1');

        self::assertSame('t1', $governing?->uuid);
    }

    public function testWithoutAReferenceThePaidAttemptGoverns(): void
    {
        $governing = GoverningTransaction::among([
            $this->debit('t0', 'UNPAID', '2026-09-21 10:00:00'),
            $this->debit('t1', 'PAID', '2026-09-21 09:00:00'),
        ], '');

        self::assertSame('t1', $governing?->uuid);
    }

    public function testWithoutAReferenceNorAPaymentTheLatestDatedAttemptGoverns(): void
    {
        $governing = GoverningTransaction::among([
            $this->debit('t0', 'UNPAID', '2026-09-21 10:00:00'),
            $this->debit('t1', 'RUNNING', '2026-09-21 11:00:00'),
        ], '');

        self::assertSame('t1', $governing?->uuid);
    }

    /**
     * Two paid attempts and no reference: the first one recorded keeps governing. The arbiter
     * decides the double payment on the notification; the history does not reshuffle it.
     */
    public function testWithoutAReferenceTheFirstPaidAttemptKeepsGoverning(): void
    {
        $governing = GoverningTransaction::among([
            $this->debit('t1', 'PAID', '2026-09-21 10:00:00'),
            $this->debit('t2', 'PAID', '2026-09-21 11:00:00'),
        ], '');

        self::assertSame('t1', $governing?->uuid);
    }

    public function testAReferenceUnknownToTheHistoryFallsBackOnTheHeuristic(): void
    {
        self::assertSame('t0', GoverningTransaction::among([$this->debit('t0', 'PAID', null)], 'unknown')?->uuid);
    }

    public function testACreditNeverGoverns(): void
    {
        $credit = new TransactionOutcome('c1', 'PAID', new \DateTimeImmutable('2026-09-22 10:00:00'), TransactionOutcome::OPERATION_CREDIT, 500);

        self::assertSame('t0', GoverningTransaction::among([$this->debit('t0', 'PAID', '2026-09-21 10:00:00'), $credit], '')?->uuid);
        self::assertNull(GoverningTransaction::among([$credit], 'c1'));
    }

    private function debit(string $uuid, string $status, ?string $createdAt): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, null !== $createdAt ? new \DateTimeImmutable($createdAt) : null, TransactionOutcome::OPERATION_DEBIT, 1000);
    }
}
