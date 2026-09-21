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
 * What one PayZen transaction says about itself: who it is, when the platform created it, and where
 * it stands. A transaction is the unit an order can have several of, one per payment attempt, and
 * the platform notifies each of them independently.
 */
final readonly class TransactionOutcome
{
    public const STATUS_PAID = 'PAID';
    public const STATUS_UNPAID = 'UNPAID';
    public const STATUS_RUNNING = 'RUNNING';

    public function __construct(
        public string $uuid,
        public string $status,
        public ?\DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array $answer one entry of the `transactions` list of a PayZen answer
     */
    public static function fromAnswer(array $answer): self
    {
        $createdAt = null;

        // The platform sends an ISO 8601 date. A date it did not send, or one it sent in a shape
        // PHP refuses, leaves the transaction undated rather than dated today: an undated
        // transaction is never allowed to outrank a dated one.
        if (isset($answer['creationDate']) && \is_string($answer['creationDate']) && '' !== $answer['creationDate']) {
            try {
                $createdAt = new \DateTimeImmutable($answer['creationDate']);
            } catch (\Exception) {
                $createdAt = null;
            }
        }

        return new self(
            (string) ($answer['uuid'] ?? ''),
            strtoupper((string) ($answer['status'] ?? '')),
            $createdAt
        );
    }

    /**
     * A transaction the platform will not speak about again. RUNNING is not one of them: the
     * platform is still deciding, and it will notify again.
     */
    public function isFinished(): bool
    {
        return \in_array($this->status, [self::STATUS_PAID, self::STATUS_UNPAID], true);
    }

    public function isPaid(): bool
    {
        return self::STATUS_PAID === $this->status;
    }
}
