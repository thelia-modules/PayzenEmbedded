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
 * An order can carry one transaction per payment attempt, and the platform notifies each of them on
 * its own schedule. These are the sequences a shop actually sees.
 *
 * Run from a Thelia checkout that has this module installed:
 *   vendor/bin/phpunit --bootstrap vendor/autoload.php vendor/thelia/modules/PayzenEmbedded/tests
 */
final class NotificationArbiterTest extends TestCase
{
    private NotificationArbiter $arbiter;

    protected function setUp(): void
    {
        $this->arbiter = new NotificationArbiter();
    }

    public function testTheFirstNotificationOfAnOrderIsAlwaysApplied(): void
    {
        self::assertTrue($this->arbiter->accepts($this->transaction('t1', 'PAID', '2026-09-21 10:00:00'), null));
    }

    public function testTheOutcomeOfAnAttemptStillRunningIsApplied(): void
    {
        $running = $this->transaction('t1', 'RUNNING', '2026-09-21 10:00:00');
        $paid = $this->transaction('t1', 'PAID', '2026-09-21 10:00:00');

        self::assertTrue($this->arbiter->accepts($paid, $running));
    }

    public function testTheSameAttemptIsNotAppliedTwiceOnceItIsFinished(): void
    {
        $paid = $this->transaction('t1', 'PAID', '2026-09-21 10:00:00');

        self::assertFalse($this->arbiter->accepts($paid, $paid));
    }

    public function testAnAttemptThatCannotBeUndoneIsNotReopenedByAReplay(): void
    {
        $paid = $this->transaction('t1', 'PAID', '2026-09-21 10:00:00');
        $running = $this->transaction('t1', 'RUNNING', '2026-09-21 10:00:00');

        self::assertFalse($this->arbiter->accepts($running, $paid));
    }

    public function testASecondAttemptSucceedsAfterAFirstOneWasRefused(): void
    {
        $refused = $this->transaction('t1', 'UNPAID', '2026-09-21 10:00:00');
        $paid = $this->transaction('t2', 'PAID', '2026-09-21 10:05:00');

        self::assertTrue($this->arbiter->accepts($paid, $refused));
    }

    public function testTheLateRefusalOfAnAbandonedAttemptLeavesAPaidOrderAlone(): void
    {
        $paid = $this->transaction('t2', 'PAID', '2026-09-21 10:05:00');
        $lateRefusal = $this->transaction('t1', 'UNPAID', '2026-09-21 10:00:00');

        self::assertFalse($this->arbiter->accepts($lateRefusal, $paid));
    }

    public function testAnAttemptStartedAfterAPaymentWentThroughDoesNotCancelIt(): void
    {
        $paid = $this->transaction('t1', 'PAID', '2026-09-21 10:00:00');
        $later = $this->transaction('t2', 'UNPAID', '2026-09-21 10:05:00');

        self::assertFalse($this->arbiter->accepts($later, $paid));
    }

    public function testAPaymentOutranksALaterRefusalAppliedFirst(): void
    {
        $laterRefusal = $this->transaction('t2', 'UNPAID', '2026-09-21 10:05:00');
        $earlierPayment = $this->transaction('t1', 'PAID', '2026-09-21 10:00:00');

        self::assertTrue($this->arbiter->accepts($earlierPayment, $laterRefusal));
    }

    public function testAPaymentOutranksALaterAttemptStillRunning(): void
    {
        $laterRunning = $this->transaction('t2', 'RUNNING', '2026-09-21 10:05:00');
        $earlierPayment = $this->transaction('t1', 'PAID', '2026-09-21 10:00:00');

        self::assertTrue($this->arbiter->accepts($earlierPayment, $laterRunning));
    }

    public function testAnUndatedPaymentOutranksADatedRefusal(): void
    {
        self::assertTrue($this->arbiter->accepts(new TransactionOutcome('t1', 'PAID', null), $this->transaction('t2', 'UNPAID', '2026-09-21 10:05:00')));
    }

    public function testAnOlderAttemptNeverOverridesANewerOne(): void
    {
        $newer = $this->transaction('t2', 'RUNNING', '2026-09-21 10:05:00');
        $older = $this->transaction('t1', 'UNPAID', '2026-09-21 10:00:00');

        self::assertFalse($this->arbiter->accepts($older, $newer));
    }

    public function testAnUndatedTransactionNeverOutranksADatedOne(): void
    {
        $dated = $this->transaction('t1', 'UNPAID', '2026-09-21 10:00:00');
        $undated = new TransactionOutcome('t2', 'UNPAID', null);

        self::assertFalse($this->arbiter->accepts($undated, $dated));
        self::assertTrue($this->arbiter->accepts($dated, $undated));
    }

    /**
     * The shopper paid twice: the second payment, dated after the first, moves the order onto
     * its own transaction. The shop then owes one of the two back, and refunds it from the page.
     */
    public function testASecondPaymentAfterAFirstOneMovesTheOrder(): void
    {
        self::assertTrue((new NotificationArbiter())->accepts(
            $this->transaction('t2', 'PAID', '2026-09-21 10:05:00'),
            $this->transaction('t1', 'PAID', '2026-09-21 10:00:00')
        ));
    }

    public function testTwoAttemptsDatedTheSameSecondKeepTheAppliedOne(): void
    {
        self::assertFalse((new NotificationArbiter())->accepts(
            $this->transaction('t2', 'PAID', '2026-09-21 10:00:00'),
            $this->transaction('t1', 'PAID', '2026-09-21 10:00:00')
        ));
    }

    public function testARunningReplayAfterARefusalIsIgnored(): void
    {
        self::assertFalse((new NotificationArbiter())->accepts(
            $this->transaction('t1', 'RUNNING', '2026-09-21 10:00:00'),
            $this->transaction('t1', 'UNPAID', '2026-09-21 10:00:00')
        ));
    }

    private function transaction(string $uuid, string $status, string $createdAt): TransactionOutcome
    {
        return new TransactionOutcome($uuid, $status, new \DateTimeImmutable($createdAt));
    }
}
