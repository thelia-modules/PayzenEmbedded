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
 * A line the module writes to the payment journal of Thelia, see PaymentJournalPlanner.
 */
enum JournalMovement
{
    case Authorization;
    case AuthorizationRefused;
    case Capture;
    case Void;
    case Expiry;
    case Refund;
    case RefundRefused;
}
