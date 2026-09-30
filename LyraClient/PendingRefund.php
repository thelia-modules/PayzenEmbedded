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
namespace PayzenEmbedded\LyraClient;

/**
 * A refund the platform may have made although its answer never reached the shop: the request
 * timed out, or the answer could not be recorded. Until the platform lists a credit the shop did
 * not know of, or a while has passed, no other refund of the order is asked for: the platform's
 * list may not show the first one yet, and the same money would go twice.
 */
final readonly class PendingRefund
{
    /** How long a refund of unknown outcome holds the order when the platform lists nothing new. */
    public const WAIT_SECONDS = 600;

    private function __construct(
        public int $since,
        /** What the order had refunded when the request was sent, in the smallest unit. */
        public int $refundedBefore,
    ) {
    }

    public static function startedAt(int $since, int $refundedBefore): self
    {
        return new self($since, $refundedBefore);
    }

    /**
     * @param string|null $marker as the module configuration keeps it, '' or null when there is none
     */
    public static function fromMarker(?string $marker): ?self
    {
        if (null === $marker || 1 !== preg_match('/^(\d+)\|(\d+)$/', $marker, $parts)) {
            return null;
        }

        return new self((int) $parts[1], (int) $parts[2]);
    }

    public function marker(): string
    {
        return $this->since . '|' . $this->refundedBefore;
    }

    /**
     * Whether the refund may still be on its way: the platform lists nothing more than before, and
     * the wait is not over. A credit listed since settles the doubt, whatever its state.
     */
    public function stillUnknown(int $refundedNow, int $now): bool
    {
        return $refundedNow <= $this->refundedBefore && $now - $this->since < self::WAIT_SECONDS;
    }

    public function minutesLeft(int $now): int
    {
        return max(1, (int) ceil((self::WAIT_SECONDS - ($now - $this->since)) / 60));
    }
}
