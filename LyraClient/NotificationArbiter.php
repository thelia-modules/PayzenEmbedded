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
 * Decides whether a PayZen notification is entitled to move the order it names.
 *
 * An order can carry several transactions, one per payment attempt, and the platform notifies each
 * of them on its own schedule: the browser return of the second attempt can land before the server
 * notification of the first. Applied in the order they arrive, a refusal that belongs to an attempt
 * the shopper already gave up on would cancel an order that is paid for.
 *
 * Only the answer itself is read here, never the order: the order status is the shop's own business
 * and a shop is free to move it by hand. What this settles is which transaction the shop is looking
 * at, and the platform's own creation date is what orders them.
 */
final readonly class NotificationArbiter
{
    /**
     * @param TransactionOutcome      $incoming the transaction the platform is notifying about
     * @param TransactionOutcome|null $applied  the last transaction this order was moved on, null
     *                                          when none was ever recorded
     */
    /**
     * Whether a notification only repeats the transaction the order stands on, in the state the
     * history holds for it: the order's own reference, not merely the row the history would fall
     * back on when that reference has none.
     */
    public function isUnchanged(TransactionOutcome $incoming, ?TransactionOutcome $applied, string $orderTransactionRef): bool
    {
        return null !== $applied
            && '' !== $orderTransactionRef
            && $applied->uuid === $orderTransactionRef
            && $incoming->uuid === $applied->uuid
            && $incoming->status === $applied->status;
    }

    /**
     * Whether the notification of a credit may be written over the row the history holds for it:
     * a credit the platform finished (given back, or refused) is never put back as running by a
     * notification that arrives late or is replayed. The platform's own listing still writes it.
     */
    public function acceptsCredit(TransactionOutcome $incoming, ?TransactionOutcome $known): bool
    {
        return null === $known || !$known->isFinished() || $incoming->isFinished();
    }

    public function accepts(TransactionOutcome $incoming, ?TransactionOutcome $applied): bool
    {
        if (null === $applied) {
            return true;
        }

        if ($incoming->uuid === $applied->uuid) {
            // The same attempt, notified twice: the browser return and the server notification say
            // the same thing. Anything after a finished state is a replay, and a replay of a state
            // the platform has left behind (RUNNING after PAID) would undo the outcome.
            return !$applied->isFinished();
        }

        // A paid order stays paid. Another attempt reaching the platform after the shopper has paid
        // is not something a shop settles by cancelling the order it already owes.
        if ($applied->isPaid() && !$incoming->isPaid()) {
            return false;
        }

        // Money the platform took outranks an order that holds none, whatever the order the
        // attempts came in: a refusal notified first must not leave the payment of an earlier
        // attempt on a cancelled order.
        if ($incoming->isPaid() && !$applied->isPaid()) {
            return true;
        }

        // Two attempts, and the platform dates both: the later attempt speaks for the order. An
        // undated transaction never outranks a dated one, in either direction, because nothing
        // places it in the sequence.
        if (null === $incoming->createdAt || null === $applied->createdAt) {
            return null !== $incoming->createdAt;
        }

        return $incoming->createdAt > $applied->createdAt;
    }
}
