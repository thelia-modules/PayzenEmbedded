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
 * What the guard says of a refund request, before the platform is asked anything.
 */
enum RefundVerdict: string
{
    /** The amount can be asked for on what the ledger holds. */
    case Allowed = 'ALLOWED';

    /** The caller decided on a figure the platform no longer holds: a refund it did not see went through. */
    case StaleView = 'STALE_VIEW';

    /** The amount is not one the ledger allows: above what is left, or not the whole authorisation to cancel. */
    case OutOfBounds = 'OUT_OF_BOUNDS';
}
