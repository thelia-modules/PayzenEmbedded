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

use PayzenEmbedded\LyraClient\PendingRefund;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refund whose answer never came holds the order until the platform lists a credit the shop did
 * not know of, or the wait is over: the platform's list may not show it yet.
 */
final class PendingRefundTest extends TestCase
{
    private const SENT = 1_000_000;

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function checks(): iterable
    {
        yield 'right after, nothing listed' => [0, self::SENT + 5, true];
        yield 'nine minutes after, nothing listed' => [0, self::SENT + 599, true];
        yield 'the wait is over' => [0, self::SENT + 600, false];
        yield 'the platform lists the refund' => [500, self::SENT + 5, false];
    }

    #[DataProvider('checks')]
    public function testTheOrderIsHeldWhileTheRefundMayBeOnItsWay(int $refundedNow, int $now, bool $held): void
    {
        self::assertSame($held, PendingRefund::startedAt(self::SENT, 0)->stillUnknown($refundedNow, $now));
    }

    public function testAPartialRefundAlreadyMadeIsTheBaseline(): void
    {
        $pending = PendingRefund::startedAt(self::SENT, 300);

        self::assertTrue($pending->stillUnknown(300, self::SENT + 5), 'the refund made before is not the one awaited');
        self::assertTrue($pending->stillUnknown(200, self::SENT + 5), 'the platform lists less than before');
        self::assertFalse($pending->stillUnknown(800, self::SENT + 5));
    }

    public function testTheMarkerRoundTrips(): void
    {
        $pending = PendingRefund::fromMarker(PendingRefund::startedAt(self::SENT, 300)->marker());

        self::assertNotNull($pending);
        self::assertSame(self::SENT, $pending->since);
        self::assertSame(300, $pending->refundedBefore);
    }

    public function testNoMarkerHoldsNothing(): void
    {
        self::assertNull(PendingRefund::fromMarker(null));
        self::assertNull(PendingRefund::fromMarker(''));
        self::assertNull(PendingRefund::fromMarker('not a marker'));
    }

    public function testTheMinutesLeftAreRoundedUp(): void
    {
        self::assertSame(10, PendingRefund::startedAt(self::SENT, 0)->minutesLeft(self::SENT + 1));
        self::assertSame(1, PendingRefund::startedAt(self::SENT, 0)->minutesLeft(self::SENT + 599));
    }

    public function testOnlyACreditAsLargeAsTheLostRequestSettlesTheDoubt(): void
    {
        $pending = PendingRefund::startedAt(self::SENT, 0, 1000);

        self::assertTrue($pending->stillUnknown(500, self::SENT + 5), 'a smaller refund made from the PayZen back-office is not the lost one');
        self::assertFalse($pending->stillUnknown(1000, self::SENT + 5));
        self::assertFalse($pending->stillUnknown(0, self::SENT + 5, 0), 'nothing left to give back: a cancellation, or everything refunded');
    }

    public function testTheRequestedAmountRoundTripsAndAnOlderMarkerStillReads(): void
    {
        self::assertSame(1000, PendingRefund::fromMarker(PendingRefund::startedAt(self::SENT, 300, 1000)->marker())?->requested);
        self::assertSame(0, PendingRefund::fromMarker(self::SENT . '|300')?->requested);
    }
}
