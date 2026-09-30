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
 * The one decision taken before a refund is asked for, read on the ledger the platform just
 * confirmed. Pure so that it can be pinned without a platform nor a database; the wrapper turns
 * the verdict into the message the administrator reads.
 */
final readonly class RefundGuard
{
    /**
     * @param int      $amount                 in the smallest unit of the order currency
     * @param int|null $expectedRefundedAmount what the caller saw as refunded so far; null skips
     *                                         that check, the balance alone remains
     */
    public static function verdict(RefundLedger $ledger, int $amount, ?int $expectedRefundedAmount): RefundVerdict
    {
        // The caller decided on a page that showed what was refunded so far. A refund that page
        // did not show (an answer lost to a timeout, a refund made from the PayZen back-office)
        // has to be seen before more is given back: the same refund would otherwise go twice.
        // Checked first: an amount that fits by chance is still asked for on a page that lies.
        if (null !== $expectedRefundedAmount && !$ledger->hasRefunded($expectedRefundedAmount)) {
            return RefundVerdict::StaleView;
        }

        if (!$ledger->allows($amount)) {
            return RefundVerdict::OutOfBounds;
        }

        return RefundVerdict::Allowed;
    }
}
