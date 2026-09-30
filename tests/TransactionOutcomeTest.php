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

use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The platform describes a transaction by its status and, since a refund creates a transaction of
 * its own, by its operation type: a debit takes money from the shopper, a credit gives it back.
 */
final class TransactionOutcomeTest extends TestCase
{
    public function testAPaymentAnswerIsADebit(): void
    {
        $outcome = TransactionOutcome::fromAnswer(['uuid' => 't1', 'status' => 'PAID', 'amount' => 1250]);

        self::assertFalse($outcome->isCredit());
        self::assertSame(TransactionOutcome::OPERATION_DEBIT, $outcome->operationType);
        self::assertSame(1250, $outcome->amount);
    }

    public function testARefundAnswerIsACredit(): void
    {
        $outcome = TransactionOutcome::fromAnswer(['uuid' => 'r1', 'status' => 'PAID', 'amount' => 300, 'operationType' => 'CREDIT']);

        self::assertTrue($outcome->isCredit());
        self::assertTrue($outcome->isPaid());
    }

    public function testAHistoryRowWrittenBeforeTheOperationTypeExistedIsADebitWithoutAParent(): void
    {
        foreach ([null, '', '  '] as $legacyOperationType) {
            $outcome = TransactionOutcome::fromHistoryRow('t1', 'paid', null, $legacyOperationType, '1250', ' captured ', $legacyOperationType);

            self::assertSame(TransactionOutcome::OPERATION_DEBIT, $outcome->operationType);
            self::assertFalse($outcome->isCredit());
            self::assertNull($outcome->parentUuid);
            self::assertSame('PAID', $outcome->status);
            self::assertSame('CAPTURED', $outcome->detailedStatus);
            self::assertSame(1250, $outcome->amount);
            self::assertTrue($outcome->isCaptured());
        }
    }

    public function testAHistoryRowOfARefundIsACreditOfItsDebit(): void
    {
        $recordedAt = new \DateTime('2026-09-30 10:00:00');
        $outcome = TransactionOutcome::fromHistoryRow('r1', 'RUNNING', $recordedAt, 'credit', 300, '', 't1');

        self::assertTrue($outcome->isCredit());
        self::assertTrue($outcome->isRunning());
        self::assertSame('t1', $outcome->parentUuid);
        self::assertSame(300, $outcome->amount);
        self::assertInstanceOf(\DateTimeImmutable::class, $outcome->createdAt);
        self::assertSame('2026-09-30 10:00:00', $outcome->createdAt->format('Y-m-d H:i:s'));
    }

    public function testAHistoryRowWithNothingRecordedIsAnEmptyDebit(): void
    {
        $outcome = TransactionOutcome::fromHistoryRow(null, null, null, null, null, null, null);

        self::assertSame('', $outcome->uuid);
        self::assertSame('', $outcome->status);
        self::assertNull($outcome->createdAt);
        self::assertSame(0, $outcome->amount);
        self::assertFalse($outcome->isFinished());
    }

    public function testTheOperationTypeIsReadWhateverItsCase(): void
    {
        self::assertTrue(TransactionOutcome::fromAnswer(['uuid' => 'r1', 'status' => 'PAID', 'operationType' => 'credit'])->isCredit());
    }

    public function testATransactionOfItsOwnWithoutAnOperationTypeIsACreditWhenTheDebitIsKnown(): void
    {
        $outcome = TransactionOutcome::fromAnswer(['uuid' => 'other', 'status' => 'PAID', 'amount' => 300], 'debit-1');

        self::assertTrue($outcome->isCredit());
        self::assertFalse(TransactionOutcome::fromAnswer(['uuid' => 'debit-1', 'status' => 'PAID'], 'debit-1')->isCredit());
        self::assertFalse(TransactionOutcome::fromAnswer(['uuid' => 'other', 'status' => 'PAID'])->isCredit());
    }

    public function testTheDetailedStatusTellsWhetherThePaymentWasCaptured(): void
    {
        self::assertTrue(TransactionOutcome::fromAnswer(['uuid' => 't', 'status' => 'PAID', 'detailedStatus' => 'CAPTURED'])->isCaptured());
        self::assertFalse(TransactionOutcome::fromAnswer(['uuid' => 't', 'status' => 'PAID', 'detailedStatus' => 'AUTHORISED'])->isCaptured());
        self::assertFalse(TransactionOutcome::fromAnswer(['uuid' => 't', 'status' => 'RUNNING', 'detailedStatus' => 'AUTHORISED_TO_VALIDATE'])->isCaptured());
        // A paid row whose detailed status is unknown (legacy rows carried error messages there) counts as captured.
        self::assertTrue(TransactionOutcome::fromAnswer(['uuid' => 't', 'status' => 'PAID', 'detailedStatus' => 'legacy text'])->isCaptured());
    }

    public function testATransactionWithoutACreationDateIsReadAsUndated(): void
    {
        $outcome = TransactionOutcome::fromAnswer(['uuid' => 't1', 'status' => 'paid']);

        self::assertSame('t1', $outcome->uuid);
        self::assertSame('PAID', $outcome->status);
        self::assertNull($outcome->createdAt);
        self::assertTrue($outcome->isPaid());
    }

    public function testAnUnparsableCreationDateIsReadAsUndatedRatherThanAsToday(): void
    {
        $outcome = TransactionOutcome::fromAnswer(['uuid' => 't1', 'status' => 'PAID', 'creationDate' => 'not a date']);

        self::assertNull($outcome->createdAt);
    }

    /**
     * A credit whose parent the platform leaves empty is a credit without a parent: read as a
     * credit of "" instead, it would no longer be deducted from the order's debit, and the same
     * amount would be offered again.
     */
    public function testAnEmptyParentIsNoParent(): void
    {
        $outcome = TransactionOutcome::fromAnswer(['uuid' => 'c1', 'status' => 'PAID', 'operationType' => 'CREDIT', 'transactionDetails' => ['parentTransactionUuid' => '']]);

        self::assertNull($outcome->parentUuid);
    }

    public function testTheDetailedStatusIsReadWhateverItsCaseAndSpacing(): void
    {
        $outcome = TransactionOutcome::fromAnswer(['uuid' => 't', 'status' => 'PAID', 'detailedStatus' => ' captured ']);

        self::assertSame('CAPTURED', $outcome->detailedStatus);
        self::assertTrue($outcome->isCaptured());
        self::assertFalse(TransactionOutcome::fromAnswer(['uuid' => 't', 'status' => 'PAID', 'detailedStatus' => ' authorised '])->isCaptured());
    }

    public function testTheProvenanceFieldsAreReadFromTheAnswer(): void
    {
        $outcome = TransactionOutcome::fromAnswer([
            'uuid' => 'c1',
            'status' => 'PAID',
            'operationType' => 'CREDIT',
            'transactionDetails' => ['parentTransactionUuid' => 'd1'],
            'metadata' => ['thelia_shop' => 'shop-a'],
            'orderDetails' => ['orderId' => 'ORD1', 'mode' => 'test'],
        ]);

        self::assertSame('d1', $outcome->parentUuid);
        self::assertSame('shop-a', $outcome->shopMarker);
        self::assertSame('TEST', $outcome->mode);
        self::assertSame('ORD1', $outcome->orderRef);
        self::assertNull(TransactionOutcome::fromAnswer(['uuid' => 'd1', 'status' => 'PAID'])->parentUuid);
    }
}
