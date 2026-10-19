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

namespace PayzenEmbedded\Tests;

use PayzenEmbedded\LyraClient\StoredMethodLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the customer sees of a card PayZen kept: the brand, the last four digits and the expiry, taken from
 * the transaction that registered it, and never more.
 */
final class StoredMethodLabelTest extends TestCase
{
    public function testTheLabelNamesTheBrandAndTheLastFourDigits(): void
    {
        $card = StoredMethodLabel::fromAnswer($this->answer(['pan' => '497010XXXXXX0055', 'effectiveBrand' => 'VISA', 'expiryMonth' => 12, 'expiryYear' => 2030]));

        self::assertSame('Visa •••• 0055', $card->label);
        self::assertSame('2030-12-31', $card->expiresAt?->format('Y-m-d'));
    }

    public function testTheCardExpiresOnTheLastDayOfItsMonth(): void
    {
        self::assertSame('2028-02-29', StoredMethodLabel::fromAnswer($this->answer(['expiryMonth' => '2', 'expiryYear' => '2028']))->expiresAt?->format('Y-m-d'));
        self::assertSame('2027-02-28', StoredMethodLabel::fromAnswer($this->answer(['expiryMonth' => 2, 'expiryYear' => 2027]))->expiresAt?->format('Y-m-d'));
    }

    public function testOnlyTheLastFourDigitsOfANumberAreEverKept(): void
    {
        $card = StoredMethodLabel::fromAnswer($this->answer(['pan' => '4970101234560055', 'effectiveBrand' => 'MASTERCARD']));

        self::assertSame('Mastercard •••• 0055', $card->label);
        self::assertStringNotContainsString('123456', $card->label);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function cardsThatSayLittle(): iterable
    {
        yield 'no brand' => [['pan' => '497010XXXXXX0055'], 'Card •••• 0055'];
        yield 'no number' => [['effectiveBrand' => 'CB'], 'CB'];
        yield 'an unknown brand is made readable' => [['effectiveBrand' => 'MY_NEW_BRAND', 'pan' => 'XXXXXXXXXXXX1234'], 'My New Brand •••• 1234'];
        yield 'nothing' => [[], StoredMethodLabel::FALLBACK];
        yield 'a number with no digits at its end' => [['pan' => '497010XXXXXXXXXX', 'effectiveBrand' => 'VISA'], 'Visa'];
        yield 'a brand that is not a word' => [['effectiveBrand' => '<script>alert(1)</script>', 'pan' => '497010XXXXXX0055'], 'Card •••• 0055'];
        yield 'a brand that is not text' => [['effectiveBrand' => ['VISA'], 'pan' => '497010XXXXXX0055'], 'Card •••• 0055'];
    }

    /**
     * @param array<string, mixed> $details
     */
    #[DataProvider('cardsThatSayLittle')]
    public function testACardThatSaysLittleStillGetsALabel(array $details, string $expected): void
    {
        self::assertSame($expected, StoredMethodLabel::fromAnswer($this->answer($details))->label);
    }

    public function testAnAnswerWithNoCardDetailsGetsTheFallback(): void
    {
        $card = StoredMethodLabel::fromAnswer(['status' => 'PAID', 'transactionDetails' => []]);

        self::assertSame(StoredMethodLabel::FALLBACK, $card->label);
        self::assertNull($card->expiresAt);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function expiriesThatMakeNoSense(): iterable
    {
        yield 'month 13' => [13, 2030];
        yield 'month 0' => [0, 2030];
        yield 'year 30' => [12, 30];
        yield 'text' => ['soon', 'never'];
        yield 'missing' => [null, null];
    }

    #[DataProvider('expiriesThatMakeNoSense')]
    public function testAnExpiryThatMakesNoSenseIsNone(mixed $month, mixed $year): void
    {
        self::assertNull(StoredMethodLabel::fromAnswer($this->answer(['pan' => 'XXXXXXXXXXXX1234', 'expiryMonth' => $month, 'expiryYear' => $year]))->expiresAt);
    }

    /**
     * @param array<string, mixed> $cardDetails
     *
     * @return array<string, mixed>
     */
    private function answer(array $cardDetails): array
    {
        return ['status' => 'PAID', 'transactionDetails' => ['cardDetails' => $cardDetails]];
    }
}
