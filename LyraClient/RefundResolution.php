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

use Thelia\Exception\TheliaProcessException;

/**
 * What the platform's answer to Transaction/CancelOrRefund means. The platform either creates a
 * credit transaction of its own (a refund), or brings the debit back as cancelled. Anything else
 * is refused, and never read as a cancellation: an order is not cancelled on a guess. A credit the
 * platform is still processing is accepted as pending.
 */
final readonly class RefundResolution
{
    /**
     * @param array<string, mixed> $answer the transaction as the platform answered it, its operation type settled
     */
    private function __construct(
        public array $answer,
        public TransactionOutcome $transaction,
    ) {
    }

    /**
     * @param array<string, mixed>         $answer              the "answer" part of the platform response
     * @param string                       $orderTransactionRef the uuid of the debit the order stands on
     * @param null|\Closure(string, array<string, string>): string $translate
     *
     * @throws TheliaProcessException when the answer carries no transaction, a refused credit, or a debit in an unexpected state
     */
    public static function fromAnswer(array $answer, string $orderTransactionRef, ?\Closure $translate = null): self
    {
        $translate ??= static fn (string $message, array $parameters): string => strtr($message, $parameters);

        if (!isset($answer['uuid']) || '' === (string) $answer['uuid']) {
            throw new TheliaProcessException($translate('Cannot refund the transaction. Error is : %message (code %code)', [
                '%code' => (string) ($answer['errorCode'] ?? 'undefined error code'),
                '%message' => (string) ($answer['errorMessage'] ?? 'undefined error message'),
            ]));
        }

        $transaction = TransactionOutcome::fromAnswer($answer, $orderTransactionRef);
        $answer['operationType'] = $transaction->operationType;

        if ($transaction->isCredit()) {
            // A credit the platform refused gives nothing back; one still running is on its way;
            // any other state is not one the module knows how to count.
            if (!$transaction->isPaid() && !$transaction->isRunning()) {
                throw new TheliaProcessException($translate('The refund was refused: %message (code %code)', [
                    '%code' => (string) ($answer['errorCode'] ?? $answer['detailedStatus'] ?? $transaction->status),
                    '%message' => (string) ($answer['errorMessage'] ?? $answer['detailedErrorMessage'] ?? ''),
                ]));
            }

            return new self($answer, $transaction);
        }

        if ($transaction->uuid !== $orderTransactionRef
            || TransactionOutcome::STATUS_UNPAID !== $transaction->status
            || 'CANCELLED' !== $transaction->detailedStatus) {
            throw new TheliaProcessException($translate('The transaction %uuid was neither cancelled nor refunded (status %status).', [
                '%uuid' => $transaction->uuid,
                '%status' => '' !== $transaction->detailedStatus ? $transaction->detailedStatus : $transaction->status,
            ]));
        }

        return new self($answer, $transaction);
    }

    public function isCredit(): bool
    {
        return $this->transaction->isCredit();
    }

    /**
     * @param RefundLedger $ledgerAfterRecording the ledger of the order once this transaction is in the history
     */
    public function outcome(RefundLedger $ledgerAfterRecording): RefundOutcome
    {
        if (!$this->isCredit()) {
            return RefundOutcome::Cancelled;
        }

        if ($this->transaction->isRunning()) {
            return RefundOutcome::Pending;
        }

        return $ledgerAfterRecording->refundableAmount() > 0
            ? RefundOutcome::PartiallyRefunded
            : RefundOutcome::Refunded;
    }
}
