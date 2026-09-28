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
 * The transaction the order stands on: the one its transaction reference names when the history
 * knows it, and only failing that the paid one, or the latest attempt the platform dated. A
 * credit never governs an order: it is money the shop asked to give back, not a payment attempt.
 */
final readonly class GoverningTransaction
{
    /**
     * @param iterable<TransactionOutcome> $transactions every transaction of the order
     * @param string                       $orderTransactionRef the debit the order stands on, or '' when unknown
     */
    public static function among(iterable $transactions, string $orderTransactionRef): ?TransactionOutcome
    {
        $governing = null;

        foreach ($transactions as $outcome) {
            if ($outcome->isCredit()) {
                continue;
            }

            if ('' !== $orderTransactionRef && $outcome->uuid === $orderTransactionRef) {
                return $outcome;
            }

            if (null === $governing) {
                $governing = $outcome;
                continue;
            }

            if ($outcome->isPaid() && !$governing->isPaid()) {
                $governing = $outcome;
                continue;
            }

            if (!$governing->isPaid()
                && null !== $outcome->createdAt
                && (null === $governing->createdAt || $outcome->createdAt > $governing->createdAt)) {
                $governing = $outcome;
            }
        }

        return $governing;
    }
}
