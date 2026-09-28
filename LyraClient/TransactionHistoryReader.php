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
            $operationType = strtoupper(trim((string) $transaction->getOperationtype()));

            $outcomes[] = new TransactionOutcome(
                (string) $transaction->getUuid(),
                strtoupper((string) $transaction->getStatus()),
                $transaction->getCreationdate() !== null
                    ? \DateTimeImmutable::createFromInterface($transaction->getCreationdate())
                    : null,
                '' === $operationType ? TransactionOutcome::OPERATION_DEBIT : $operationType,
                (int) $transaction->getAmount()
            );
        }

        return $outcomes;
    }

    public function ledgerOf(Order $order): RefundLedger
    {
        return RefundLedger::fromTransactions($this->outcomesOf($order));
    }
}
