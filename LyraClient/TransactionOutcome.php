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
 * What the platform says about one transaction: its identifier, its status, when it was created,
 * whether it takes money from the shopper or gives it back, for how much, and how far it got.
 */
final readonly class TransactionOutcome
{
    public const STATUS_PAID = 'PAID';
    public const STATUS_UNPAID = 'UNPAID';
    public const STATUS_RUNNING = 'RUNNING';

    /** The ordinary payment: money goes from the shopper to the shop. */
    public const OPERATION_DEBIT = 'DEBIT';

    /** A refund: the platform creates a transaction of its own that gives money back to the shopper. */
    public const OPERATION_CREDIT = 'CREDIT';

    /** Detailed statuses of a debit the bank has not captured yet: it can still be cancelled, in full. */
    private const NOT_CAPTURED = ['AUTHORISED', 'AUTHORISED_TO_VALIDATE', 'WAITING_AUTHORISATION', 'WAITING_AUTHORISATION_TO_VALIDATE', 'WAITING_FOR_PAYMENT', 'INITIAL', 'UNDER_VERIFICATION'];

    /**
     * @param int $amount in the smallest unit of the currency, as the platform counts it
     */
    public function __construct(
        public string $uuid,
        public string $status,
        public ?\DateTimeImmutable $createdAt,
        public string $operationType = self::OPERATION_DEBIT,
        public int $amount = 0,
        public string $detailedStatus = '',
    ) {
    }

    /**
     * @param string|null $debitUuid the debit the order stands on, when known: a transaction of its
     *                               own that does not say its operation type is then the credit of a
     *                               refund, since the platform creates nothing else for an order
     */
    public static function fromAnswer(array $answer, ?string $debitUuid = null): self
    {
        $createdAt = null;

        if (isset($answer['creationDate']) && \is_string($answer['creationDate']) && '' !== $answer['creationDate']) {
            try {
                $createdAt = new \DateTimeImmutable($answer['creationDate']);
            } catch (\Exception) {
                $createdAt = null;
            }
        }

        $uuid = (string) ($answer['uuid'] ?? '');
        $operationType = strtoupper(trim((string) ($answer['operationType'] ?? '')));

        if ('' === $operationType) {
            $operationType = null !== $debitUuid && '' !== $debitUuid && $uuid !== $debitUuid
                ? self::OPERATION_CREDIT
                : self::OPERATION_DEBIT;
        }

        return new self(
            $uuid,
            strtoupper((string) ($answer['status'] ?? '')),
            $createdAt,
            $operationType,
            (int) ($answer['amount'] ?? 0),
            strtoupper(trim((string) ($answer['detailedStatus'] ?? '')))
        );
    }

    public function isFinished(): bool
    {
        return \in_array($this->status, [self::STATUS_PAID, self::STATUS_UNPAID], true);
    }

    public function isPaid(): bool
    {
        return self::STATUS_PAID === $this->status;
    }

    public function isRunning(): bool
    {
        return self::STATUS_RUNNING === $this->status;
    }

    public function isCredit(): bool
    {
        return self::OPERATION_CREDIT === $this->operationType;
    }

    /**
     * Whether the bank took the money. A paid transaction whose detailed status is not one of the
     * "authorised, not captured yet" ones counts as captured: rows written by older versions carry
     * no usable detailed status, and the shop has been paid for them long ago.
     */
    public function isCaptured(): bool
    {
        return $this->isPaid() && !\in_array($this->detailedStatus, self::NOT_CAPTURED, true);
    }
}
