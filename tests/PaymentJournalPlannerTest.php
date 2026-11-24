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

use PayzenEmbedded\LyraClient\JournalMovement;
use PayzenEmbedded\LyraClient\PaymentJournalPlanner;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\TestCase;

/**
 * What a transaction the platform reports writes to the payment journal of Thelia, when the module
 * authorizes first and captures later: the authorization once, the capture of that authorization,
 * its cancellation or its expiry, and the refusal of an attempt that never got one.
 */
final class PaymentJournalPlannerTest extends TestCase
{
    public function testAnAuthorizationWaitingForItsValidationIsWrittenOnce(): void
    {
        $authorized = $this->debit('RUNNING', 'AUTHORISED_TO_VALIDATE', 12000);

        $steps = PaymentJournalPlanner::for($authorized, null);

        self::assertCount(1, $steps);
        self::assertSame(JournalMovement::Authorization, $steps[0]->movement);
        self::assertSame('t1', $steps[0]->uuid);
        self::assertSame(12000, $steps[0]->amount);

        self::assertSame([], PaymentJournalPlanner::for($authorized, 't1'), 'The journal already holds it.');
    }

    public function testAValidatedAuthorizationNotCapturedYetWritesNothingMore(): void
    {
        // Validated by the shop, waiting for the remittance: the capture line waits for the money.
        self::assertSame([], PaymentJournalPlanner::for($this->debit('PAID', 'AUTHORISED', 4000), 't1'));
    }

    public function testACaptureOfTheAuthorizationIsWritten(): void
    {
        $steps = PaymentJournalPlanner::for($this->debit('PAID', 'CAPTURED', 4000), 't1');

        self::assertCount(1, $steps);
        self::assertSame(JournalMovement::Capture, $steps[0]->movement);
        self::assertSame(4000, $steps[0]->amount);
    }

    public function testACaptureWhoseAuthorizationWasNeverWrittenWritesBoth(): void
    {
        // The notification of the authorization was lost: the capture still has to stand on one.
        $steps = PaymentJournalPlanner::for($this->debit('PAID', 'CAPTURED', 4000), null);

        self::assertSame([JournalMovement::Authorization, JournalMovement::Capture], array_map(static fn ($step) => $step->movement, $steps));
    }

    public function testACancelledAuthorizationIsAVoid(): void
    {
        $steps = PaymentJournalPlanner::for($this->debit('UNPAID', 'CANCELLED', 12000), 't1');

        self::assertSame(JournalMovement::Void, $steps[0]->movement);
    }

    public function testAnExpiredAuthorizationIsAnExpiry(): void
    {
        $steps = PaymentJournalPlanner::for($this->debit('UNPAID', 'EXPIRED', 12000), 't1');

        self::assertSame(JournalMovement::Expiry, $steps[0]->movement);
    }

    public function testTheEndOfAnotherAttemptNeverReleasesTheAuthorizationTheOrderStandsOn(): void
    {
        // The shopper gave up on a first attempt: its cancellation is not the one of the
        // authorization the journal holds.
        self::assertSame([], PaymentJournalPlanner::for($this->debit('UNPAID', 'CANCELLED', 12000, 'other'), 't1'));
        self::assertSame([], PaymentJournalPlanner::for($this->debit('PAID', 'CAPTURED', 12000, 'other'), 't1'));
    }

    public function testARefusedAttemptIsWrittenAsARefusedAuthorization(): void
    {
        $steps = PaymentJournalPlanner::for($this->debit('UNPAID', 'REFUSED', 12000), null, '05', 'Do not honor');

        self::assertSame(JournalMovement::AuthorizationRefused, $steps[0]->movement);
        self::assertSame('05', $steps[0]->errorCode);
        self::assertSame('Do not honor', $steps[0]->errorMessage);
    }

    public function testARefundIsLeftToTheRefundFeature(): void
    {
        $credit = TransactionOutcome::fromAnswer(['uuid' => 'r1', 'status' => 'PAID', 'amount' => 300, 'operationType' => 'CREDIT']);

        self::assertSame([], PaymentJournalPlanner::for($credit, 't1'));
    }

    public function testAPaymentNotAuthorizedYetWritesNothing(): void
    {
        self::assertSame([], PaymentJournalPlanner::for($this->debit('RUNNING', 'WAITING_AUTHORISATION_TO_VALIDATE', 12000), null));
    }

    private function debit(string $status, string $detailedStatus, int $amount, string $uuid = 't1'): TransactionOutcome
    {
        return TransactionOutcome::fromAnswer(['uuid' => $uuid, 'status' => $status, 'detailedStatus' => $detailedStatus, 'amount' => $amount]);
    }
}
