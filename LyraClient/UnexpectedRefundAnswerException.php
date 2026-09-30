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

use Thelia\Exception\TheliaProcessException;

/**
 * The platform accepted the refund request but answered something the module cannot read: no
 * transaction, a credit in a state it does not know, a debit neither cancelled nor the order's.
 * Unlike a refusal, the money may have moved: the order is held until the platform lists it.
 */
final class UnexpectedRefundAnswerException extends TheliaProcessException
{
}
