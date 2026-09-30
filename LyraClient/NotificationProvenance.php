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
 * Whether a transaction the platform notifies is this shop's, for the order the notification
 * names. A notification is signed, and a payment precedes the marker: a debit without one is
 * accepted, since that is how an order gets its transaction. A credit without one is accepted
 * only when it gives money back on the transaction the order stands on: two shops on one
 * contract, or two environments in the TEST space, share their order references, and a refund
 * made on the other one must not settle this order. The listing of Order/Get, which names the
 * order on every transaction, is judged by TransactionProvenance.
 */
final readonly class NotificationProvenance
{
    /**
     * @param string $notifiedMode   TEST or PRODUCTION as the notification says at its top level, '' when it does not
     * @param string $shopMarker     what this shop sends as metadata with every payment
     * @param string $orderDebitUuid the debit the order stands on, '' when the order has none yet
     * @param string $expectedMode   the space this shop is configured for
     */
    public function __construct(
        private string $notifiedMode,
        private string $shopMarker,
        private string $orderDebitUuid,
        private string $expectedMode = 'TEST',
        /** @var list<string> the transactions the history already holds for this order: the shop's */
        private array $knownUuids = [],
    ) {
    }

    public function accepts(TransactionOutcome $transaction): bool
    {
        if ('' !== $this->notifiedMode && $this->notifiedMode !== $this->expectedMode) {
            return false;
        }

        if (\in_array($transaction->uuid, $this->knownUuids, true)) {
            return true;
        }

        if (null !== $transaction->shopMarker) {
            return $transaction->shopMarker === $this->shopMarker;
        }

        if ($transaction->isCredit()) {
            return '' !== $this->orderDebitUuid && $transaction->parentUuid === $this->orderDebitUuid;
        }

        return true;
    }
}
