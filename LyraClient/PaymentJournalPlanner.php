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
 * What a transaction the platform reports writes to the payment journal of Thelia, when the module
 * authorizes first and captures later.
 *
 * The journal holds one authorization per order: the transaction the shop captures or releases.
 * Its capture, cancellation or expiry is written against it, and only those of that transaction:
 * the end of an attempt the shopper gave up on releases nothing. An attempt refused before any
 * authorization is written as a refused authorization. Refunds are left to the refund feature.
 *
 * The journal answers a line it already holds with that line, so a notification replayed, or the
 * same transaction read again by a refresh, writes nothing twice.
 */
final readonly class PaymentJournalPlanner
{
    /**
     * @param string|null $authorization the reference of the authorization the journal holds for the order, if any
     *
     * @return list<JournalStep>
     */
    public static function for(TransactionOutcome $incoming, ?string $authorization, ?string $errorCode = null, ?string $errorMessage = null): array
    {
        if ($incoming->isCredit() || '' === $incoming->uuid) {
            return [];
        }

        if (null !== $authorization && $authorization !== $incoming->uuid) {
            return [];
        }

        $authorize = new JournalStep(JournalMovement::Authorization, $incoming->uuid, $incoming->amount);

        if ($incoming->isAuthorized()) {
            return null === $authorization ? [$authorize] : [];
        }

        if ($incoming->isCaptured()) {
            $capture = new JournalStep(JournalMovement::Capture, $incoming->uuid, $incoming->amount);

            // A capture stands on an authorization: one whose notification was lost is written first.
            return null === $authorization ? [$authorize, $capture] : [$capture];
        }

        if (null !== $authorization) {
            return match (true) {
                $incoming->isCancelled() => [new JournalStep(JournalMovement::Void, $incoming->uuid, $incoming->amount)],
                $incoming->isExpired() => [new JournalStep(JournalMovement::Expiry, $incoming->uuid, $incoming->amount)],
                default => [],
            };
        }

        if (TransactionOutcome::STATUS_UNPAID === $incoming->status && !$incoming->isCancelled() && !$incoming->isExpired()) {
            return [new JournalStep(JournalMovement::AuthorizationRefused, $incoming->uuid, $incoming->amount, $errorCode, $errorMessage)];
        }

        return [];
    }
}
