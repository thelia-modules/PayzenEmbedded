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
 * Where a transaction of the order it stands on moves the order.
 *
 * Taking the payment at once, an authorization still running is the payment: the order is paid, as
 * it always was. Authorizing first, it is not: the order waits for its capture, which Thelia moves
 * from its payment journal, and only the capture pays it.
 */
enum OrderMove
{
    case Paid;
    case Cancelled;
    /** The transaction is the journal's business: the order is left as it is. */
    case None;
    case Unsupported;

    public static function for(TransactionOutcome $incoming, bool $authorizeFirst): self
    {
        // Waiting for its authorization or for its validation, the payment is not taken yet.
        if ($authorizeFirst && ($incoming->isAuthorized() || $incoming->waitsForValidation())) {
            return self::None;
        }

        return match ($incoming->status) {
            TransactionOutcome::STATUS_PAID, TransactionOutcome::STATUS_RUNNING => self::Paid,
            TransactionOutcome::STATUS_UNPAID => self::Cancelled,
            default => self::Unsupported,
        };
    }
}
