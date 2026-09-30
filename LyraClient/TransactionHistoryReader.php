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

use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use Thelia\Model\Order;

/**
 * The transactions of an order, as the module's history recorded them.
 */
final readonly class TransactionHistoryReader
{
    /**
     * @return list<TransactionOutcome>
     */
    public function outcomesOf(Order $order): array
    {
        $outcomes = [];

        /** @var PayzenEmbeddedTransactionHistory $transaction */
        foreach (PayzenEmbeddedTransactionHistoryQuery::create()->filterByOrderId($order->getId())->find() as $transaction) {
            $outcomes[] = $this->outcomeOf($transaction);
        }

        return $outcomes;
    }

    public function outcomeOf(PayzenEmbeddedTransactionHistory $transaction): TransactionOutcome
    {
        return TransactionOutcome::fromHistoryRow(
            $transaction->getUuid(),
            $transaction->getStatus(),
            $transaction->getCreationdate(),
            $transaction->getOperationtype(),
            $transaction->getAmount(),
            $transaction->getDetailedstatus(),
            $transaction->getParentuuid()
        );
    }

    public function ledgerOf(Order $order): RefundLedger
    {
        return RefundLedger::fromTransactions($this->outcomesOf($order), (string) $order->getTransactionRef());
    }

    /**
     * @return list<string> the transactions the history holds for the order
     */
    public function uuidsOf(Order $order): array
    {
        return array_values(array_filter(array_map(
            static fn (TransactionOutcome $outcome): string => $outcome->uuid,
            $this->outcomesOf($order)
        ), static fn (string $uuid): bool => '' !== $uuid));
    }
}
