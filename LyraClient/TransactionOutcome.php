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

    /** The metadata key a payment carries to name the shop that created it. */
    public const SHOP_MARKER_KEY = 'thelia_shop';

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
        /** The debit a credit gives money back on, when the platform says it. */
        public ?string $parentUuid = null,
        /** The shop that created the transaction, when it was sent along at creation. */
        public ?string $shopMarker = null,
        /** TEST or PRODUCTION, as the platform says. */
        public string $mode = '',
        /** The merchant's order reference the platform lists the transaction under. */
        public string $orderRef = '',
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
            // A credit gives money back whatever the sign the platform writes it with: counted
            // negative, it would add to what is left to refund.
            self::OPERATION_CREDIT === $operationType ? abs((int) ($answer['amount'] ?? 0)) : (int) ($answer['amount'] ?? 0),
            strtoupper(trim((string) ($answer['detailedStatus'] ?? ''))),
            // A credit the module asked for on the debit it names gives money back on that debit,
            // whether or not the answer says so.
            self::stringOrNull($answer['transactionDetails']['parentTransactionUuid'] ?? null)
                ?? (self::OPERATION_CREDIT === $operationType && null !== $debitUuid && '' !== $debitUuid && $uuid !== $debitUuid ? $debitUuid : null),
            self::stringOrNull($answer['metadata'][self::SHOP_MARKER_KEY] ?? null),
            strtoupper(trim((string) ($answer['orderDetails']['mode'] ?? ''))),
            trim((string) ($answer['orderDetails']['orderId'] ?? ''))
        );
    }

    /**
     * A row of the module's history, read the way the platform describes a transaction: rows
     * written before 3.4.0 carry no operation type and are debits, and no parent.
     *
     * The creation date is kept as the platform's UTC wall clock (the column has no time zone),
     * and the model reads it back in PHP's default time zone: it is read here as UTC again, or a
     * shop outside UTC would place its own history hours off the notifications it weighs.
     *
     * @param int|string|null $amount in the smallest unit of the currency, as the history stores it
     */
    public static function fromHistoryRow(
        ?string $uuid,
        ?string $status,
        ?\DateTimeInterface $createdAt,
        ?string $operationType,
        int|string|null $amount,
        ?string $detailedStatus,
        ?string $parentUuid,
    ): self {
        $operationType = strtoupper(trim((string) $operationType));

        return new self(
            (string) $uuid,
            strtoupper(trim((string) $status)),
            null !== $createdAt ? new \DateTimeImmutable($createdAt->format('Y-m-d H:i:s.u'), new \DateTimeZone('UTC')) : null,
            '' === $operationType ? self::OPERATION_DEBIT : $operationType,
            (int) $amount,
            strtoupper(trim((string) $detailedStatus)),
            self::stringOrNull($parentUuid)
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
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
