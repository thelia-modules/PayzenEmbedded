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
 * What the platform did with a refund request. It decides on its own: a transaction that was not
 * captured yet is cancelled, a captured one gets a refund transaction.
 */
enum RefundOutcome: string
{
    /** The transaction was cancelled before its capture: nothing was taken from the shopper. */
    case Cancelled = 'CANCELLED';

    /** The shopper got back everything they paid. */
    case Refunded = 'REFUNDED';

    /** The shopper got part of their money back, the rest can still be refunded. */
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';

    /** The platform accepted the refund and is still processing it: the order is left as it is. */
    case Pending = 'PENDING';
}
