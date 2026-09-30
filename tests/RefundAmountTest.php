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

use PayzenEmbedded\LyraClient\RefundAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an administrator types in the refund form, read into the smallest unit of the currency
 * without a float in between: "1 234,56" is not 1.00, and "12abc" is not 12.00.
 */
final class RefundAmountTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function inputs(): iterable
    {
        yield 'plain euros' => ['12.50', 'EUR', 1250];
        yield 'french decimal comma' => ['12,50', 'EUR', 1250];
        yield 'one decimal' => ['12.5', 'EUR', 1250];
        yield 'no decimal' => ['12', 'EUR', 1200];
        yield 'surrounding spaces' => [' 12.50 ', 'EUR', 1250];
        yield 'zero decimal currency' => ['1000', 'XPF', 1000];
        yield 'thousands separator is refused' => ['1 234,56', 'EUR', null];
        yield 'letters are refused' => ['12abc', 'EUR', null];
        yield 'two commas are refused' => ['12,5.3', 'EUR', null];
        yield 'scientific notation is refused' => ['1e2', 'EUR', null];
        yield 'three decimals are refused' => ['10.005', 'EUR', null];
        yield 'negative is refused' => ['-5', 'EUR', null];
        yield 'zero is refused' => ['0', 'EUR', null];
        yield 'empty is refused' => ['', 'EUR', null];
        yield 'decimals on a zero decimal currency are refused' => ['10.50', 'XPF', null];
        yield 'three decimal currency' => ['1.234', 'TND', 1234];
        yield 'smallest unit of a three decimal currency' => ['0.001', 'KWD', 1];
        yield 'four decimals on a three decimal currency are refused' => ['1.2345', 'KWD', null];
        yield 'thirteen digits are refused' => ['1234567890123', 'EUR', null];
    }

    #[DataProvider('inputs')]
    public function testAnInputReadsAsMinorUnitsOrIsRefused(string $input, string $currency, ?int $expected): void
    {
        self::assertSame($expected, RefundAmount::fromInput($input, $currency));
    }

    /**
     * The whole table of the currencies counted without a fractional unit, or with three (ISO
     * 4217): an entry dropped by mistake would make the platform read 250 yen as 25000, or a
     * dinar amount a thousand times too small.
     */
    public function testTheMinorUnitTableMatchesIso4217(): void
    {
        foreach (['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'] as $currency) {
            self::assertSame(0, RefundAmount::decimals($currency), $currency);
        }

        foreach (['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'] as $currency) {
            self::assertSame(3, RefundAmount::decimals($currency), $currency);
        }

        self::assertSame(2, RefundAmount::decimals('EUR'));
        self::assertSame(2, RefundAmount::decimals('ZZZ'));
        self::assertSame(0, RefundAmount::decimals('jpy'));
    }

    public function testMinorUnitsAreWrittenBackInTheCurrency(): void
    {
        self::assertSame('12.50', RefundAmount::format(1250, 'EUR'));
        self::assertSame('1000', RefundAmount::format(1000, 'XPF'));
        self::assertSame('1.250', RefundAmount::format(1250, 'KWD'));
    }

    /**
     * @return iterable<string, array{int|float|string, string, ?int}>
     */
    public static function amounts(): iterable
    {
        yield 'typed text with too many decimals is refused' => ['9.0849', 'EUR', null];
        yield 'a computed total is converted from the major unit' => [47.4, 'EUR', 4740];
        yield 'a computed legacy total with four decimals is rounded, not refused' => [9.0849, 'EUR', 908];
        yield 'a computed integer total' => [1000, 'JPY', 1000];
        yield 'a computed zero is refused' => [0.0, 'EUR', null];
    }

    /**
     * What an event carries: text typed by an administrator, or a total the shop computed, such
     * as the capture after picking. A legacy order total keeps its four decimals, and a capture
     * refused on that account would leave the authorisation to expire unpaid.
     */
    #[DataProvider('amounts')]
    public function testAnAmountTypedOrComputedReadsAsMinorUnitsOrIsRefused(int|float|string $amount, string $currency, ?int $expected): void
    {
        self::assertSame($expected, RefundAmount::fromAmount($amount, $currency));
    }

    /**
     * The per-gram prices of the shop produce totals on a half cent (1.005). PHP 8.3 rounds them
     * up, since round() pre-rounds the binary value 100.4999…; a runtime that stops pre-rounding
     * would take one cent less from the shopper on every such order. This pins the amount charged.
     */
    public function testAHalfCentOfAComputedTotalRoundsUp(): void
    {
        self::assertSame(101, RefundAmount::fromMajor(1.005, 'EUR'));
        self::assertSame(909, RefundAmount::fromMajor(9.0850, 'EUR'));
        self::assertSame(101, RefundAmount::fromAmount(1.005, 'EUR'));
    }

    public function testAMajorAmountFromTheShopIsWrittenInMinorUnits(): void
    {
        self::assertSame(1250, RefundAmount::fromMajor(12.5, 'EUR'));
        self::assertSame(1250, RefundAmount::fromMajor('12.50', 'EUR'));
        self::assertSame(1000, RefundAmount::fromMajor(1000.0, 'JPY'));
        self::assertSame(1235, RefundAmount::fromMajor(12.345, 'EUR'));
    }

    /**
     * @return iterable<string, array{0: string, 1: float|string, 2: bool, 3?: string}>
     */
    public static function ceilings(): iterable
    {
        yield 'the amount the platform holds for a legacy total' => ['12.35', 12.3456, true];
        yield 'the same, typed with a comma' => ['12,35', '12.3456', true];
        yield 'one cent above it' => ['12.36', 12.3456, false];
        yield 'below the total' => ['5', 12.3456, true];
        yield 'the exact total' => ['12.30', '12.30', true];
        yield 'not an amount' => ['12abc', 12.3456, false];
        yield 'nothing' => ['0', 12.3456, false];
        yield 'a currency without decimals, its total rounded' => ['1000', 1000.4, true, 'JPY'];
        yield 'a currency without decimals refuses a decimal point' => ['999.5', 1000.4, false, 'JPY'];
        yield 'a currency with three decimals' => ['12.346', 12.3456, true, 'KWD'];
        yield 'a currency with three decimals, one unit above' => ['12.347', 12.3456, false, 'KWD'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ceilings')]
    public function testATypedAmountFitsUnderTheTotalInTheSmallestUnit(string $typed, float|string $total, bool $fits, string $currency = 'EUR'): void
    {
        self::assertSame($fits, RefundAmount::fitsUnder($typed, $total, $currency));
    }

    public function testAComputedAmountNeverGoesAboveWhatThePlatformHolds(): void
    {
        self::assertSame(1234, RefundAmount::cappedTo(1235, 1234), 'a legacy total truncated at authorisation, rounded at capture');
        self::assertSame(1000, RefundAmount::cappedTo(1000, 1234), 'a total lowered after picking');
        self::assertSame(1235, RefundAmount::cappedTo(1235, null), 'the history does not know the transaction');
        self::assertSame(1235, RefundAmount::cappedTo(1235, 0));
    }
}
