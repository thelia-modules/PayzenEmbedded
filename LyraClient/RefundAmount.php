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
 * An amount in the smallest unit of a currency, as the platform counts it: read from what an
 * administrator typed, converted from the shop's major unit, and written back for display. No float in between: "1 234,56" is refused
 * rather than read as 1.00, and "12abc" rather than 12.00.
 */
final readonly class RefundAmount
{
    /** Currencies counted without a fractional unit, or with three (ISO 4217 minor units); every other one has two. */
    private const DECIMALS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function decimals(string $currencyCode): int
    {
        return self::DECIMALS[strtoupper($currencyCode)] ?? 2;
    }

    /**
     * @return int|null the amount in minor units, or null when the input is not a plain positive
     *                  amount with at most the decimals of the currency
     */
    public static function fromInput(string $input, string $currencyCode): ?int
    {
        $decimals = self::decimals($currencyCode);
        $normalized = str_replace(',', '.', trim($input));

        $pattern = 0 === $decimals ? '/^\d{1,12}$/' : sprintf('/^\d{1,12}(\.\d{1,%d})?$/', $decimals);

        if (1 !== preg_match($pattern, $normalized)) {
            return null;
        }

        [$units, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $amount = (int) $units * 10 ** $decimals + (int) str_pad($fraction, $decimals, '0');

        return $amount > 0 ? $amount : null;
    }

    /**
     * An amount the shop holds in the major unit (an order total, a form field of the update form),
     * written in minor units the way the platform counts them.
     */
    public static function fromMajor(float|string $amount, string $currencyCode): int
    {
        return (int) round((float) $amount * 10 ** self::decimals($currencyCode));
    }

    /**
     * An amount as an event or a form carries it: text typed by an administrator is read strictly,
     * a number the shop computed (an order total, whatever its decimals) is converted from the
     * major unit. Null when nothing positive can be made of it.
     */
    public static function fromAmount(int|float|string $amount, string $currencyCode): ?int
    {
        if (\is_string($amount)) {
            return self::fromInput($amount, $currencyCode);
        }

        $minorUnits = self::fromMajor($amount, $currencyCode);

        return $minorUnits > 0 ? $minorUnits : null;
    }

    public static function format(int $amount, string $currencyCode): string
    {
        $decimals = self::decimals($currencyCode);

        return number_format($amount / 10 ** $decimals, $decimals, '.', '');
    }
}
