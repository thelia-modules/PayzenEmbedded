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
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Whether a transaction the platform notifies is this shop's, for the order the notification
 * names. A notification is signed, and a payment precedes the marker: a debit without one is
 * accepted, since that is how an order gets its transaction. A credit without one is accepted
 * only when it gives money back on the transaction the order stands on: two shops on one
 * contract, or two environments in the TEST space, share their order references.
 */
final class NotificationProvenanceTest extends TestCase
{
    private const DEBIT = 'debit-1';
    private const SHOP = 'shop-a';

    public function testATransactionOfAnotherShopIsRefused(): void
    {
        self::assertFalse($this->provenance()->accepts($this->transaction('any', shopMarker: 'shop-b')));
    }

    public function testATransactionCarryingTheShopMarkerIsAccepted(): void
    {
        self::assertTrue($this->provenance()->accepts($this->transaction('any', shopMarker: self::SHOP)));
        self::assertTrue($this->provenance()->accepts($this->transaction('credit-x', operation: TransactionOutcome::OPERATION_CREDIT, parent: 'foreign', shopMarker: self::SHOP)));
    }

    public function testANotificationOfAnotherSpaceIsRefused(): void
    {
        $provenance = new NotificationProvenance('PRODUCTION', self::SHOP, self::DEBIT);

        self::assertFalse($provenance->accepts($this->transaction(self::DEBIT, shopMarker: self::SHOP)));
        self::assertTrue((new NotificationProvenance('', self::SHOP, self::DEBIT))->accepts($this->transaction(self::DEBIT)));
    }

    public function testAnUnmarkedDebitIsAcceptedSinceAPaymentPrecedesTheMarker(): void
    {
        self::assertTrue($this->provenance()->accepts($this->transaction('new-attempt')));
        self::assertTrue((new NotificationProvenance('TEST', self::SHOP, ''))->accepts($this->transaction('first-attempt')));
    }

    public function testAnUnmarkedCreditIsAcceptedOnlyOnTheOrderDebit(): void
    {
        $provenance = $this->provenance();

        self::assertTrue($provenance->accepts($this->transaction('credit-1', operation: TransactionOutcome::OPERATION_CREDIT, parent: self::DEBIT)));
        self::assertFalse($provenance->accepts($this->transaction('credit-2', operation: TransactionOutcome::OPERATION_CREDIT, parent: 'foreign-debit')));
        self::assertFalse($provenance->accepts($this->transaction('credit-3', operation: TransactionOutcome::OPERATION_CREDIT)));
        self::assertFalse((new NotificationProvenance('TEST', self::SHOP, ''))->accepts($this->transaction('credit-4', operation: TransactionOutcome::OPERATION_CREDIT, parent: self::DEBIT)));
    }

    private function provenance(): NotificationProvenance
    {
        return new NotificationProvenance('TEST', self::SHOP, self::DEBIT);
    }

    private function transaction(string $uuid, string $operation = TransactionOutcome::OPERATION_DEBIT, ?string $parent = null, ?string $shopMarker = null): TransactionOutcome
    {
        return new TransactionOutcome($uuid, 'PAID', null, $operation, 1000, 'CAPTURED', $parent, $shopMarker);
    }
}
