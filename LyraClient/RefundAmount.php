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
 * An amount in the smallest unit of a currency, as the platform counts it, read from what an
 * administrator typed and written back for display. No float in between: "1 234,56" is refused
 * rather than read as 1.00, and "12abc" rather than 12.00.
 */
final readonly class RefundAmount
{
    /** Currencies the platform counts without a fractional unit, or with three. */
    private const DECIMALS = ['JPY' => 0, 'KRW' => 0, 'XOF' => 0, 'XPF' => 0, 'KWD' => 3, 'TND' => 3];

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

    public static function format(int $amount, string $currencyCode): string
    {
        $decimals = self::decimals($currencyCode);

        return number_format($amount / 10 ** $decimals, $decimals, '.', '');
    }
}
