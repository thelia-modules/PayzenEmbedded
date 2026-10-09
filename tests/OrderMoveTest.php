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

use PayzenEmbedded\LyraClient\OrderMove;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Where a transaction moves the order. Taking the payment at once, an authorization still running
 * is the payment, as it always was. Authorizing first, it is not: the order waits for its capture,
 * which Thelia handles from its payment journal.
 */
final class OrderMoveTest extends TestCase
{
    public function testTakingThePaymentAtOnceARunningAuthorizationPaysTheOrderAsBefore(): void
    {
        self::assertSame(OrderMove::Paid, OrderMove::for($this->debit('RUNNING', 'AUTHORISED'), false));
        self::assertSame(OrderMove::Paid, OrderMove::for($this->debit('PAID', 'CAPTURED'), false));
        self::assertSame(OrderMove::Cancelled, OrderMove::for($this->debit('UNPAID', 'REFUSED'), false));
    }

    public function testAuthorizingFirstAnAuthorizationLeavesTheOrderToTheJournal(): void
    {
        self::assertSame(OrderMove::None, OrderMove::for($this->debit('RUNNING', 'AUTHORISED_TO_VALIDATE'), true));
        self::assertSame(OrderMove::None, OrderMove::for($this->debit('PAID', 'AUTHORISED'), true), 'Validated, not captured yet.');
    }

    public function testAuthorizingFirstTheCaptureStillPaysTheOrder(): void
    {
        self::assertSame(OrderMove::Paid, OrderMove::for($this->debit('PAID', 'CAPTURED'), true));
        self::assertSame(OrderMove::Cancelled, OrderMove::for($this->debit('UNPAID', 'CANCELLED'), true));
    }

    public function testAnUnknownStatusMovesNothing(): void
    {
        self::assertSame(OrderMove::Unsupported, OrderMove::for($this->debit('WHATEVER', ''), false));
    }

    private function debit(string $status, string $detailedStatus): TransactionOutcome
    {
        return TransactionOutcome::fromAnswer(['uuid' => 't1', 'status' => $status, 'detailedStatus' => $detailedStatus, 'amount' => 1000]);
    }
}
