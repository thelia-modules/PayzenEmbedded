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
 * whether it takes money from the shopper or gives it back, and for how much.
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

    /**
     * @param int $amount in the smallest unit of the currency, as the platform counts it
     */
    public function __construct(
        public string $uuid,
        public string $status,
        public ?\DateTimeImmutable $createdAt,
        public string $operationType = self::OPERATION_DEBIT,
        public int $amount = 0,
    ) {
    }

    public static function fromAnswer(array $answer): self
    {
        $createdAt = null;

        if (isset($answer['creationDate']) && \is_string($answer['creationDate']) && '' !== $answer['creationDate']) {
            try {
                $createdAt = new \DateTimeImmutable($answer['creationDate']);
            } catch (\Exception) {
                $createdAt = null;
            }
        }

        $operationType = strtoupper(trim((string) ($answer['operationType'] ?? '')));

        return new self(
            (string) ($answer['uuid'] ?? ''),
            strtoupper((string) ($answer['status'] ?? '')),
            $createdAt,
            '' === $operationType ? self::OPERATION_DEBIT : $operationType,
            (int) ($answer['amount'] ?? 0)
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

    public function isCredit(): bool
    {
        return self::OPERATION_CREDIT === $this->operationType;
    }
}
