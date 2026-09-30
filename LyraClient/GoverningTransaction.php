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
     * The transaction a notification is weighed against: what the order was moved on before it.
     *
     * Every move sets the order's transaction reference, so an order without one was never moved.
     * Its history may still hold transactions, since the refresh records every transaction the
     * platform lists, but none of them was applied. Such an order takes any payment, whatever the
     * attempts listed after it. A refusal is weighed against a payment the platform lists, so that
     * it never undoes it; a listed refusal or attempt still running weighs nothing.
     *
     * @param iterable<TransactionOutcome> $transactions every transaction of the order
     */
    public static function appliedBefore(iterable $transactions, string $orderTransactionRef, TransactionOutcome $incoming): ?TransactionOutcome
    {
        if ('' !== $orderTransactionRef) {
            return self::among($transactions, $orderTransactionRef);
        }

        if ($incoming->isPaid()) {
            return null;
        }

        $listedPayments = [];

        foreach ($transactions as $outcome) {
            // A paid credit is set aside by among(): it gives money back, it is no payment.
            if ($outcome->uuid !== $incoming->uuid && $outcome->isPaid()) {
                $listedPayments[] = $outcome;
            }
        }

        return self::among($listedPayments, '');
    }

    /**
     * The attempts the history lists that a notification of theirs would move the order onto: the
     * refresh records them without moving the order, and only their notification can. An order
     * without a reference is left out: it takes any payment, and is warned about on its own.
     *
     * @param iterable<TransactionOutcome> $transactions every transaction of the order
     *
     * @return list<TransactionOutcome>
     */
    public static function outrankingAttempts(iterable $transactions, string $orderTransactionRef): array
    {
        if ('' === $orderTransactionRef) {
            return [];
        }

        $rows = \is_array($transactions) ? array_values($transactions) : iterator_to_array($transactions, false);
        $applied = self::among($rows, $orderTransactionRef);
        $arbiter = new NotificationArbiter();
        $outranking = [];

        foreach ($rows as $row) {
            if (!$row->isCredit() && $row->uuid !== $applied?->uuid && $arbiter->accepts($row, $applied)) {
                $outranking[] = $row;
            }
        }

        return $outranking;
    }

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
