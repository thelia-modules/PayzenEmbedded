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

use PayzenEmbedded\LyraClient\NotificationArbiter;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The history keeps the platform's UTC wall clock in a column without time zone, which the model
 * hands back in PHP's default time zone. A shop outside UTC reads it as UTC again, or it places
 * its own history hours off the notifications it weighs them against.
 */
final class HistoryDateTimeZoneTest extends TestCase
{
    private string $previousTimeZone;

    protected function setUp(): void
    {
        $this->previousTimeZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimeZone);
    }

    public function testARowIsReadBackAtTheInstantThePlatformGave(): void
    {
        // What the model hands back for the stored value 2026-09-29 08:57:31: a Paris date.
        $stored = new \DateTime('2026-09-29 08:57:31');

        $row = TransactionOutcome::fromHistoryRow('t1', 'PAID', $stored, 'DEBIT', 669, 'CAPTURED', null);

        self::assertSame('2026-09-29T08:57:31+00:00', $row->createdAt?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertEquals(TransactionOutcome::fromAnswer(['uuid' => 't1', 'status' => 'PAID', 'creationDate' => '2026-09-29T08:57:31+00:00'])->createdAt, $row->createdAt);
    }

    public function testALateRefusalOfAnEarlierAttemptDoesNotOutrankTheAttemptTheOrderStandsOn(): void
    {
        // The order stands on B, authorised at 09:00 UTC and read back from the history.
        $applied = TransactionOutcome::fromHistoryRow('b', 'RUNNING', new \DateTime('2026-09-29 09:00:00'), 'DEBIT', 669, 'AUTHORISED_TO_VALIDATE', null);
        // The refusal of A, three minutes earlier, notified late.
        $lateRefusal = TransactionOutcome::fromAnswer(['uuid' => 'a', 'status' => 'UNPAID', 'creationDate' => '2026-09-29T08:57:00+00:00']);

        self::assertFalse((new NotificationArbiter())->accepts($lateRefusal, $applied));
    }

    public function testNoDateStaysNoDate(): void
    {
        self::assertNull(TransactionOutcome::fromHistoryRow('t1', 'PAID', null, 'DEBIT', 669, 'CAPTURED', null)->createdAt);
    }
}
