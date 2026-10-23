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

namespace PayzenEmbedded\Service;

use Thelia\Domain\Payment\Exception\CustomerPaymentMethodException;

/**
 * A card registered on the PayZen contract of another sale unit cannot pay this order: its token would be refused by
 * the contract of the order. A refusal about a stored payment method like the core's own, which an HTTP caller
 * answers as such (422). The message says what the customer can do and carries neither the token nor the label.
 */
final class StoredMethodOfAnotherContractException extends CustomerPaymentMethodException
{
    public function __construct()
    {
        parent::__construct('This stored payment method was registered for another sale unit and cannot pay this order. Pay with a new payment method.');
    }
}
