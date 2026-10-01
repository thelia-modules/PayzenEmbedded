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
 * Whether a transaction the platform lists under an order reference is this shop's, for this
 * order. Two shops on one contract, or two environments in the TEST space, produce the same
 * references: the marker sent with every payment tells them apart. A transaction without one
 * (created before the marker existed, or by another instance) is trusted only when it is the
 * debit the order stands on, or a credit of that debit. One the history already holds for this
 * order is the shop's: the history refuses a transaction of another order.
 */
final readonly class TransactionProvenance
{
    public function __construct(
        private string $orderRef,
        private string $mode,
        private string $shopMarker,
        private string $orderDebitUuid,
        /** @var list<string> the transactions the history already holds for this order */
        private array $knownUuids = [],
    ) {
    }

    public function accepts(TransactionOutcome $transaction): bool
    {
        if ($transaction->orderRef !== $this->orderRef) {
            return false;
        }

        if ('' !== $transaction->mode && $transaction->mode !== $this->mode) {
            return false;
        }

        // A marker of another shop is never this shop's, whatever the history holds.
        if (null !== $transaction->shopMarker && $transaction->shopMarker !== $this->shopMarker) {
            return false;
        }

        if (\in_array($transaction->uuid, $this->knownUuids, true)) {
            return true;
        }

        if (null !== $transaction->shopMarker) {
            return $transaction->shopMarker === $this->shopMarker;
        }

        if ('' === $this->orderDebitUuid) {
            return false;
        }

        if ($transaction->isCredit()) {
            return $transaction->parentUuid === $this->orderDebitUuid;
        }

        return $transaction->uuid === $this->orderDebitUuid;
    }
}
