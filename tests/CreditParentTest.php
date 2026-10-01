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

use PayzenEmbedded\LyraClient\NotificationProvenance;
use PayzenEmbedded\LyraClient\RefundLedger;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\LyraClient\TransactionProvenance;
use PHPUnit\Framework\TestCase;

/**
 * A credit the module asked for is tied to the debit it asked it on, even when the answer does not
 * say so, and the history then recognises it as the shop's when the platform lists or notifies it
 * again: without both, a refund still running would never be seen settled.
 */
final class CreditParentTest extends TestCase
{
    private const REF = 'ORD000000000042';
    private const DEBIT = 'd0000000000000000000000000000000';
    private const CREDIT = 'c0000000000000000000000000000000';
    private const SHOP = 'shop-marker';

    public function testACreditTheModuleAskedForIsTiedToItsDebit(): void
    {
        $credit = TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'RUNNING', 'amount' => 669], self::DEBIT);

        self::assertTrue($credit->isCredit());
        self::assertSame(self::DEBIT, $credit->parentUuid);
    }

    public function testTheParentTheAnswerNamesWins(): void
    {
        $credit = TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'RUNNING', 'operationType' => 'CREDIT', 'transactionDetails' => ['parentTransactionUuid' => 'other']], self::DEBIT);

        self::assertSame('other', $credit->parentUuid);
    }

    public function testNoParentIsMadeUpWithoutTheDebit(): void
    {
        self::assertNull(TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'operationType' => 'CREDIT'])->parentUuid);
        self::assertNull(TransactionOutcome::fromAnswer(['uuid' => self::DEBIT, 'status' => 'PAID'], self::DEBIT)->parentUuid);
    }

    public function testTheListingOfARecordedCreditIsTheShops(): void
    {
        // Order/Get lists the credit with neither marker nor parent.
        $listed = TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'operationType' => 'CREDIT', 'orderDetails' => ['orderId' => self::REF, 'mode' => 'TEST']]);

        self::assertFalse((new TransactionProvenance(self::REF, 'TEST', self::SHOP, self::DEBIT))->accepts($listed));
        self::assertTrue((new TransactionProvenance(self::REF, 'TEST', self::SHOP, self::DEBIT, [self::DEBIT, self::CREDIT]))->accepts($listed));
    }

    public function testAKnownTransactionOfAnotherOrderOrSpaceStaysOut(): void
    {
        $otherOrder = TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'operationType' => 'CREDIT', 'orderDetails' => ['orderId' => 'ORD000000000001', 'mode' => 'TEST']]);
        $otherSpace = TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'operationType' => 'CREDIT', 'orderDetails' => ['orderId' => self::REF, 'mode' => 'PRODUCTION']]);
        $provenance = new TransactionProvenance(self::REF, 'TEST', self::SHOP, self::DEBIT, [self::CREDIT]);

        self::assertFalse($provenance->accepts($otherOrder));
        self::assertFalse($provenance->accepts($otherSpace));
        self::assertFalse((new NotificationProvenance('PRODUCTION', self::SHOP, self::DEBIT, 'TEST', [self::CREDIT]))->accepts($otherSpace));
    }

    public function testTheNotificationOfARecordedCreditIsTheShops(): void
    {
        $notified = TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'operationType' => 'CREDIT']);

        self::assertFalse((new NotificationProvenance('TEST', self::SHOP, self::DEBIT))->accepts($notified));
        self::assertTrue((new NotificationProvenance('TEST', self::SHOP, self::DEBIT, 'TEST', [self::CREDIT]))->accepts($notified));
    }

    public function testTheCreditTheModuleAskedForSettlesTheOrderOnceConfirmed(): void
    {
        $ledger = RefundLedger::fromTransactions([
            new TransactionOutcome(self::DEBIT, 'PAID', null, TransactionOutcome::OPERATION_DEBIT, 669, 'CAPTURED'),
            TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'amount' => 669], self::DEBIT),
        ], self::DEBIT);

        self::assertTrue($ledger->isFullyRefunded());
    }

    public function testACreditWrittenNegativeStillGivesMoneyBack(): void
    {
        self::assertSame(669, TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'operationType' => 'CREDIT', 'amount' => -669])->amount);
        self::assertSame(-5, TransactionOutcome::fromAnswer(['uuid' => self::DEBIT, 'status' => 'PAID', 'operationType' => 'DEBIT', 'amount' => -5])->amount);
    }

    public function testAKnownTransactionMarkedByAnotherShopStaysOut(): void
    {
        $foreign = TransactionOutcome::fromAnswer(['uuid' => self::CREDIT, 'status' => 'PAID', 'operationType' => 'CREDIT', 'metadata' => ['thelia_shop' => 'another-shop'], 'orderDetails' => ['orderId' => self::REF, 'mode' => 'TEST']]);

        self::assertFalse((new TransactionProvenance(self::REF, 'TEST', self::SHOP, self::DEBIT, [self::CREDIT]))->accepts($foreign));
        self::assertFalse((new NotificationProvenance('TEST', self::SHOP, self::DEBIT, 'TEST', [self::CREDIT]))->accepts($foreign));
    }

    public function testACreditStoredNegativeIsReadBackPositive(): void
    {
        self::assertSame(669, TransactionOutcome::fromHistoryRow(self::CREDIT, 'PAID', null, 'CREDIT', -669, 'CAPTURED', self::DEBIT)->amount);
        self::assertSame(-5, TransactionOutcome::fromHistoryRow(self::DEBIT, 'PAID', null, 'DEBIT', -5, 'CAPTURED', null)->amount);
    }
}
