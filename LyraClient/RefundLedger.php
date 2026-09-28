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
 * What a shop may still give back on an order: the money it received, less the money it already
 * returned. Amounts are in the smallest unit of the currency, as the platform counts them.
 */
final readonly class RefundLedger
{
    private function __construct(
        public int $paidAmount,
        public int $refundedAmount,
    ) {
    }

    /**
     * @param iterable<TransactionOutcome> $transactions every transaction of the order
     */
    public static function fromTransactions(iterable $transactions): self
    {
        $paidAmount = 0;
        $refundedAmount = 0;

        foreach ($transactions as $transaction) {
            if (!$transaction->isPaid()) {
                continue;
            }

            if ($transaction->isCredit()) {
                $refundedAmount += $transaction->amount;
            } else {
                $paidAmount += $transaction->amount;
            }
        }

        return new self($paidAmount, $refundedAmount);
    }

    public function refundableAmount(): int
    {
        return max(0, $this->paidAmount - $this->refundedAmount);
    }

    /** Whether a refund of this amount can be asked for. */
    public function covers(int $amount): bool
    {
        return $amount > 0 && $amount <= $this->refundableAmount();
    }

    /** Whether a refund of this amount gives the shopper back everything they paid. */
    public function isSettledBy(int $amount): bool
    {
        return $amount >= $this->refundableAmount();
    }
}
