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
use PayzenEmbedded\LyraClient\TransactionProvenance;
use PHPUnit\Framework\TestCase;

/**
 * Whether a transaction the platform lists under an order reference is this shop's, for this
 * order. Two shops on one contract, or two environments in the TEST space, produce the same
 * references: the shop marker sent with every payment tells them apart, and a transaction
 * without one is only trusted when it is the debit the order stands on, or a credit of it.
 */
final class TransactionProvenanceTest extends TestCase
{
    private const REF = 'ORD000000000001';
    private const DEBIT = 'debit-1';
    private const SHOP = 'shop-a';

    public function testATransactionCarryingTheShopMarkerIsAccepted(): void
    {
        self::assertTrue($this->provenance()->accepts($this->transaction('other-debit', shopMarker: self::SHOP)));
    }

    public function testATransactionOfAnotherShopIsRefused(): void
    {
        self::assertFalse($this->provenance()->accepts($this->transaction(self::DEBIT, shopMarker: 'shop-b')));
    }

    public function testATransactionOfAnotherModeIsRefused(): void
    {
        self::assertFalse($this->provenance()->accepts($this->transaction(self::DEBIT, shopMarker: self::SHOP, mode: 'PRODUCTION')));
    }

    public function testATransactionListedUnderAnotherReferenceIsRefused(): void
    {
        self::assertFalse($this->provenance()->accepts($this->transaction(self::DEBIT, shopMarker: self::SHOP, orderRef: 'ORD000000000002')));
        self::assertFalse($this->provenance()->accepts($this->transaction(self::DEBIT, shopMarker: self::SHOP, orderRef: '')));
    }

    public function testATransactionWithoutMarkerIsTrustedOnlyAsTheOrderDebitOrItsCredit(): void
    {
        $provenance = $this->provenance();

        self::assertTrue($provenance->accepts($this->transaction(self::DEBIT)));
        self::assertTrue($provenance->accepts($this->transaction('credit-1', operation: TransactionOutcome::OPERATION_CREDIT, parent: self::DEBIT)));
        self::assertFalse($provenance->accepts($this->transaction('foreign-debit')));
        self::assertFalse($provenance->accepts($this->transaction('foreign-credit', operation: TransactionOutcome::OPERATION_CREDIT, parent: 'foreign-debit')));
        self::assertFalse($provenance->accepts($this->transaction('orphan-credit', operation: TransactionOutcome::OPERATION_CREDIT)));
    }

    public function testWithoutAKnownDebitNothingUnmarkedIsTrusted(): void
    {
        $provenance = new TransactionProvenance(self::REF, 'TEST', self::SHOP, '');

        self::assertFalse($provenance->accepts($this->transaction('any-debit')));
        self::assertTrue($provenance->accepts($this->transaction('any-debit', shopMarker: self::SHOP)));
    }

    private function provenance(): TransactionProvenance
    {
        return new TransactionProvenance(self::REF, 'TEST', self::SHOP, self::DEBIT);
    }

    private function transaction(string $uuid, string $operation = TransactionOutcome::OPERATION_DEBIT, ?string $parent = null, ?string $shopMarker = null, string $mode = 'TEST', string $orderRef = self::REF): TransactionOutcome
    {
        return new TransactionOutcome($uuid, 'PAID', null, $operation, 1000, 'CAPTURED', $parent, $shopMarker, $mode, $orderRef);
    }
}
