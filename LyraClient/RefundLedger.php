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
 * What a shop may still give back on an order: the money it received on the transaction the order
 * stands on, less the money it already returned or is returning, and the authorisation still
 * waiting for its capture, which can only be cancelled in full. Amounts are in the smallest unit
 * of the currency, as the platform counts them.
 */
final readonly class RefundLedger
{
    private function __construct(
        public int $paidAmount,
        /** The money returned or on its way back: promised, so never offered twice. */
        public int $refundedAmount,
        /** The money the platform confirmed it gave back: what settles the order. */
        public int $settledRefundedAmount,
        public int $authorisedAmount,
        /** Money taken on another debit of the order (a double payment) and not given back yet. */
        public int $otherPaymentsLeft = 0,
    ) {
    }

    /**
     * @param iterable<TransactionOutcome> $transactions        every transaction of the order
     * @param string                       $orderTransactionRef the debit the order stands on; the other
     *                                                          debits are attempts the shopper gave up
     *                                                          on. Empty when unknown: every debit counts.
     */
    public static function fromTransactions(iterable $transactions, string $orderTransactionRef): self
    {
        $paidAmount = 0;
        $refundedAmount = 0;
        $settledRefundedAmount = 0;
        $authorisedAmount = 0;
        $otherPayments = [];
        $otherCredits = [];

        foreach ($transactions as $transaction) {
            if ($transaction->isCredit()) {
                // A credit of another debit (a double payment refunded from the PayZen back-office)
                // is not this one's; a credit whose parent is unknown counts, since older rows and
                // older answers carry none.
                $ofThisDebit = '' === $orderTransactionRef
                    || null === $transaction->parentUuid
                    || $transaction->parentUuid === $orderTransactionRef;

                // A refund on its way, or in a state the module does not know, is money already
                // promised: it is never offered twice, only a refusal frees it. Only a refund the
                // platform confirmed is money given back: the one on its way may still be refused,
                // and nothing would bring back an order settled on it.
                if ($ofThisDebit && TransactionOutcome::STATUS_UNPAID !== $transaction->status) {
                    $refundedAmount += $transaction->amount;
                }

                if ($ofThisDebit && $transaction->isPaid()) {
                    $settledRefundedAmount += $transaction->amount;
                }

                if (!$ofThisDebit && $transaction->isPaid()) {
                    $otherCredits[$transaction->parentUuid] = ($otherCredits[$transaction->parentUuid] ?? 0) + $transaction->amount;
                }

                continue;
            }

            if ('' !== $orderTransactionRef && $transaction->uuid !== $orderTransactionRef) {
                // Another attempt: given up on, unless the platform took its money too. A payment
                // taken twice is not settled until both are given back.
                if ($transaction->isPaid()) {
                    $otherPayments[$transaction->uuid] = $transaction->amount;
                }

                continue;
            }

            if ($transaction->isCaptured()) {
                $paidAmount += $transaction->amount;
            } elseif ($transaction->isRunning() || $transaction->isPaid()) {
                $authorisedAmount += $transaction->amount;
            }
        }

        $otherPaymentsLeft = 0;

        foreach ($otherPayments as $uuid => $amount) {
            $otherPaymentsLeft += max(0, $amount - ($otherCredits[$uuid] ?? 0));
        }

        return new self($paidAmount, $refundedAmount, $settledRefundedAmount, $authorisedAmount, $otherPaymentsLeft);
    }

    public function refundableAmount(): int
    {
        return max(0, $this->paidAmount - $this->refundedAmount);
    }

    /**
     * Whether the customer got back all they paid: the one rule that settles an order as refunded,
     * for the refund service, the notification of a credit and the history refresh alike. A refund
     * still running does not count, whatever it promises, and neither does an order another payment
     * of which the platform took and still holds.
     */
    public function isFullyRefunded(): bool
    {
        return $this->paidAmount > 0 && $this->settledRefundedAmount >= $this->paidAmount && 0 === $this->otherPaymentsLeft;
    }

    /** Whether a refund of this amount can be asked for on a captured payment. */
    public function covers(int $amount): bool
    {
        return $amount > 0 && $amount <= $this->refundableAmount();
    }

    /** An authorisation the bank has not captured, with nothing captured nor refunded: it can be cancelled, in full. */
    public function isCancellable(): bool
    {
        return 0 === $this->paidAmount && 0 === $this->refundedAmount && $this->authorisedAmount > 0;
    }

    /** Whether the platform can be asked to give this amount back: a refund, or the cancellation of the whole authorisation. */
    public function allows(int $amount): bool
    {
        return $this->covers($amount) || ($this->isCancellable() && $amount === $this->authorisedAmount);
    }

    /**
     * Whether the money already given back is exactly what the caller saw: a refund the caller did
     * not see (an answer lost to a timeout, a refund made from the PayZen back-office) has to be
     * seen before anything more is given back, or the same refund is asked for twice.
     */
    public function hasRefunded(int $amount): bool
    {
        return $this->refundedAmount === $amount;
    }

    /** What the administrator may ask for: the refundable amount, or the authorisation to cancel. */
    public function maximumAmount(): int
    {
        return $this->isCancellable() ? $this->authorisedAmount : $this->refundableAmount();
    }
}
