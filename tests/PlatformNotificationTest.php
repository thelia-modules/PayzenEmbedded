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

namespace PayzenEmbedded\Tests;

use PayzenEmbedded\LyraClient\PlatformNotification;
use PHPUnit\Framework\TestCase;

/**
 * A notification names an order and carries a signature. The order it names only selects the key,
 * the signature then has to be the one of that key: the key of another contract, the public key
 * the shopper holds, or no key at all, never pass.
 */
final class PlatformNotificationTest extends TestCase
{
    private const PASSWORD_OF_A = 'password-of-contract-a';
    private const PASSWORD_OF_B = 'password-of-contract-b';

    public function testANotificationSignedWithTheKeyOfItsContractIsAccepted(): void
    {
        self::assertTrue($this->notification('ORD1', self::PASSWORD_OF_B)->isSignedBy(self::PASSWORD_OF_B));
    }

    public function testANotificationSignedWithAnotherContractKeyIsRefused(): void
    {
        $signedByB = $this->notification('ORD1', self::PASSWORD_OF_B);

        self::assertFalse($signedByB->isSignedBy(self::PASSWORD_OF_A));
    }

    public function testTheKeyOfTheSaleUnitIsTheOnlyOneThatPasses(): void
    {
        // The order named belongs to the sale unit A: the notification signed with B's key is refused
        // when checked with A's, and the other way round.
        $forOrderOfA = $this->notification('ORDER-OF-A', self::PASSWORD_OF_A);
        $forgedWithB = $this->notification('ORDER-OF-A', self::PASSWORD_OF_B);

        self::assertTrue($forOrderOfA->isSignedBy(self::PASSWORD_OF_A));
        self::assertFalse($forgedWithB->isSignedBy(self::PASSWORD_OF_A));
        self::assertFalse($forOrderOfA->isSignedBy(self::PASSWORD_OF_B));
    }

    public function testAnEmptyPasswordSignsNothing(): void
    {
        $signedWithNothing = $this->notification('ORD1', '');

        self::assertFalse($signedWithNothing->isSignedBy(''));
    }

    public function testTheAnswerSignedWithThePublicKeyIsNotThePlatformsWord(): void
    {
        // What the shopper's browser receives: signed with the public HMAC key, which the shopper holds.
        $answer = $this->answer('ORD1');
        $notification = PlatformNotification::fromPost([
            'kr-hash' => hash_hmac('sha256', $answer, 'public-hmac-key'),
            'kr-hash-algorithm' => 'sha256_hmac',
            'kr-hash-key' => 'sha256_hmac',
            'kr-answer' => $answer,
        ]);

        self::assertFalse($notification->isSignedBy('public-hmac-key'));
    }

    public function testAnUnsupportedAlgorithmIsRefused(): void
    {
        $answer = $this->answer('ORD1');
        $notification = PlatformNotification::fromPost([
            'kr-hash' => hash_hmac('sha256', $answer, self::PASSWORD_OF_A),
            'kr-hash-algorithm' => 'md5',
            'kr-hash-key' => 'password',
            'kr-answer' => $answer,
        ]);

        self::assertFalse($notification->isSignedBy(self::PASSWORD_OF_A));
    }

    public function testAnAnswerChangedAfterItWasSignedIsRefused(): void
    {
        $signed = $this->answer('ORD-OF-A');
        $notification = PlatformNotification::fromPost([
            'kr-hash' => hash_hmac('sha256', $signed, self::PASSWORD_OF_B),
            'kr-hash-algorithm' => 'sha256_hmac',
            'kr-hash-key' => 'password',
            'kr-answer' => $this->answer('ORD-OF-B'),
        ]);

        self::assertFalse($notification->isSignedBy(self::PASSWORD_OF_B));
    }

    public function testTheClaimedReferenceIsReadFromTheAnswer(): void
    {
        self::assertSame('ORD000000000042', $this->notification('ORD000000000042', self::PASSWORD_OF_A)->claimedOrderReference());
    }

    public function testNoReferenceIsClaimedWhenTheAnswerHasNone(): void
    {
        $post = $this->post('{"transactions":[]}', self::PASSWORD_OF_A);

        self::assertNull(PlatformNotification::fromPost($post)->claimedOrderReference());
    }

    public function testAReferenceThatIsNotAReasonableStringIsNotClaimed(): void
    {
        foreach (['{"orderDetails":{"orderId":["a"]}}', '{"orderDetails":{"orderId":""}}', '{"orderDetails":{"orderId":"' . str_repeat('A', 300) . '"}}'] as $answer) {
            self::assertNull(PlatformNotification::fromPost($this->post($answer, self::PASSWORD_OF_A))->claimedOrderReference(), $answer);
        }
    }

    public function testAnIncompleteRequestIsNotANotification(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlatformNotification::fromPost(['kr-answer' => '{}']);
    }

    public function testAnAnswerThatIsNotJsonIsNotANotification(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlatformNotification::fromPost($this->post('not json', self::PASSWORD_OF_A));
    }

    private function answer(string $orderReference): string
    {
        return json_encode(['orderDetails' => ['orderId' => $orderReference], 'transactions' => []], \JSON_THROW_ON_ERROR);
    }

    private function notification(string $orderReference, string $signedWith): PlatformNotification
    {
        return PlatformNotification::fromPost($this->post($this->answer($orderReference), $signedWith));
    }

    /**
     * @return array<string, string>
     */
    private function post(string $answer, string $signedWith): array
    {
        return [
            'kr-hash' => hash_hmac('sha256', $answer, $signedWith),
            'kr-hash-algorithm' => 'sha256_hmac',
            'kr-hash-key' => 'password',
            'kr-answer' => $answer,
        ];
    }
}
