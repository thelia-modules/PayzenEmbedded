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

    public function testTheOperationTypeIsReadWhateverItsCase(): void
    {
        self::assertTrue(TransactionOutcome::fromAnswer(['uuid' => 'r1', 'status' => 'PAID', 'operationType' => 'credit'])->isCredit());
    }

    public function testAnAnswerWithoutAmountCountsForNothing(): void
    {
        self::assertSame(0, TransactionOutcome::fromAnswer(['uuid' => 't1', 'status' => 'RUNNING'])->amount);
    }
}
