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
use PHPUnit\Framework\TestCase;

/**
 * What the module asks the platform for when it creates a payment: a plain payment, or a payment
 * with the offer to register the card. The platform leaves the wallets out of a SmartForm that
 * asks for a registration without a use case, so a shop offering the wallets never asks for one.
 */
final class PaymentFormActionTest extends TestCase
{
    public function testACardFormAsksToRegisterTheCardWhenOneClickIsAllowed(): void
    {
        self::assertSame('ASK_REGISTER_PAY', PaymentFormAction::resolve(oneClickEnabled: true, smartFormEnabled: false));
    }

    public function testACardFormOnlyPaysWhenOneClickIsNotAllowed(): void
    {
        self::assertSame('PAYMENT', PaymentFormAction::resolve(oneClickEnabled: false, smartFormEnabled: false));
    }

    public function testASmartFormNeverAsksToRegisterTheCard(): void
    {
        self::assertSame('PAYMENT', PaymentFormAction::resolve(oneClickEnabled: true, smartFormEnabled: true));
    }
}
