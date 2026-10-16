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
 * What the platform sends to the notification URL, read without believing any of it.
 *
 * The signature can only be checked with the key of the contract the order was paid on, and the
 * order is only known from the notification itself: the reference it names selects the key, it
 * does not vouch for anything. Nothing here is trusted until isSignedBy() answered true, and the
 * answer is then the very text that was signed.
 */
final readonly class PlatformNotification
{
    private const SUPPORTED_ALGORITHM = 'sha256_hmac';

    /** The longest order reference the shop stores. */
    private const MAX_REFERENCE_LENGTH = 255;

    /**
     * @param array<mixed> $answer the decoded kr-answer
     */
    private function __construct(
        private string $rawAnswer,
        private string $hash,
        private string $algorithm,
        private string $hashKey,
        public array $answer,
    ) {
    }

    /**
     * @param array<mixed> $post the POST parameters of the request
     *
     * @throws \InvalidArgumentException when the parameters are not a notification
     */
    public static function fromPost(array $post): self
    {
        $fields = [];

        foreach (['kr-hash', 'kr-hash-algorithm', 'kr-hash-key', 'kr-answer'] as $name) {
            if (!isset($post[$name]) || !\is_string($post[$name])) {
                throw new \InvalidArgumentException(sprintf('%s is missing from the notification.', $name));
            }

            $fields[$name] = $post[$name];
        }

        // Read as the platform's SDK reads it: some servers escape the slashes.
        $answer = json_decode($fields['kr-answer'], true) ?? json_decode(stripslashes($fields['kr-answer']), true);

        if (!\is_array($answer)) {
            throw new \InvalidArgumentException('kr-answer is not a JSON object.');
        }

        return new self($fields['kr-answer'], $fields['kr-hash'], $fields['kr-hash-algorithm'], $fields['kr-hash-key'], $answer);
    }

    /**
     * The order reference the notification names. Unverified: good for finding the order, whose
     * contract holds the key, and for nothing else.
     */
    public function claimedOrderReference(): ?string
    {
        $reference = $this->answer['orderDetails']['orderId'] ?? null;

        if (\is_int($reference)) {
            $reference = (string) $reference;
        }

        if (!\is_string($reference) || '' === $reference || \strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            return null;
        }

        return $reference;
    }

    /**
     * Whether the platform signed this notification with the REST password given. The shopper's
     * browser receives the answer signed with the public HMAC key, which is the shopper's to hold:
     * only the password, that the platform signs its notifications with, is accepted here. An empty
     * password would sign with an empty key, which anyone can do.
     */
    public function isSignedBy(string $password): bool
    {
        if ('password' !== $this->hashKey || '' === $password || self::SUPPORTED_ALGORITHM !== $this->algorithm) {
            return false;
        }

        // On some servers the slashes are escaped.
        $signed = str_replace('\/', '/', $this->rawAnswer);

        return hash_equals(hash_hmac('sha256', $signed, $password), $this->hash)
            || hash_equals(hash_hmac('sha256', stripslashes($signed), $password), $this->hash);
    }
}
