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

use PayzenEmbedded\LyraClient\PaymentFormAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the module asks the platform for when it creates a payment: a plain payment, or a payment
 * with the offer to register the card. The platform leaves the wallets out of a SmartForm that
 * asks for a registration without a use case, so a shop offering the wallets never asks for one.
 * Two booleans, four cases: the whole truth table is pinned, since a wrong rule can agree on
 * three of them.
 */
final class PaymentFormActionTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function cases(): iterable
    {
        yield 'card form, one click allowed: the card may be registered' => [true, false, 'ASK_REGISTER_PAY'];
        yield 'card form, one click not allowed: a plain payment' => [false, false, 'PAYMENT'];
        yield 'SmartForm, one click allowed: never a registration, the wallets would vanish' => [true, true, 'PAYMENT'];
        yield 'SmartForm, one click not allowed: a plain payment' => [false, true, 'PAYMENT'];
    }

    #[DataProvider('cases')]
    public function testTheFormActionFollowsTheWholeTruthTable(bool $oneClickEnabled, bool $smartFormEnabled, string $expected): void
    {
        self::assertSame($expected, PaymentFormAction::resolve(oneClickEnabled: $oneClickEnabled, smartFormEnabled: $smartFormEnabled));
    }
}
