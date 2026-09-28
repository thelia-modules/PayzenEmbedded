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

namespace PayzenEmbedded\LyraClient;

/**
 * What the module asks the platform for when it creates a payment (the formAction of
 * Charge/CreatePayment): a plain payment, or a payment with the offer to register the card for
 * the one click payments.
 *
 * The platform leaves Apple Pay and Google Pay out of a SmartForm that asks for a registration
 * without a use case, and the only use cases it knows are recurring ones. A shop offering the
 * wallets therefore never asks for a registration; a customer who already registered a card
 * keeps paying with it, since the token travels on its own.
 */
final readonly class PaymentFormAction
{
    public const PAYMENT = 'PAYMENT';
    public const ASK_REGISTER_PAY = 'ASK_REGISTER_PAY';

    public static function resolve(bool $oneClickEnabled, bool $smartFormEnabled): string
    {
        if ($smartFormEnabled || !$oneClickEnabled) {
            return self::PAYMENT;
        }

        return self::ASK_REGISTER_PAY;
    }
}
