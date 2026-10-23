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
 * What the shop shows a customer for a card PayZen kept: "Visa •••• 4242", and the last day the
 * card is good for.
 *
 * Read from the card details of the transaction that registered it. Only the four last digits of
 * the masked number are ever taken, and only the brand and the expiry besides: whatever else the
 * answer holds stays out of the shop.
 */
final readonly class StoredMethodLabel
{
    /** Shown when the answer says nothing the customer could recognize the card by. */
    public const FALLBACK = 'PayZen card';

    private const BRANDS = [
        'VISA' => 'Visa',
        'MASTERCARD' => 'Mastercard',
        'MAESTRO' => 'Maestro',
        'AMEX' => 'American Express',
        'CB' => 'CB',
        'E_CARTEBLEUE' => 'e-Carte Bleue',
        'VISA_ELECTRON' => 'Visa Electron',
        'JCB' => 'JCB',
        'DINERS' => 'Diners Club',
        'DISCOVER' => 'Discover',
    ];

    public function __construct(
        public string $label,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @param array<mixed> $answer a transaction as the platform answers it
     */
    public static function fromAnswer(array $answer): self
    {
        $card = $answer['transactionDetails']['cardDetails'] ?? null;

        if (!\is_array($card)) {
            return new self(self::FALLBACK, null);
        }

        return new self(
            self::labelOf($card),
            self::expiryOf($card['expiryMonth'] ?? null, $card['expiryYear'] ?? null),
        );
    }

    /**
     * @param array<mixed> $card
     */
    private static function labelOf(array $card): string
    {
        $brand = self::brandOf($card['effectiveBrand'] ?? $card['brand'] ?? null);
        $lastDigits = self::lastDigitsOf($card['pan'] ?? null);

        if (null !== $lastDigits) {
            return ('' === $brand ? 'Card' : $brand) . ' •••• ' . $lastDigits;
        }

        return '' === $brand ? self::FALLBACK : $brand;
    }

    private static function brandOf(mixed $brand): string
    {
        if (!\is_string($brand)) {
            return '';
        }

        $code = strtoupper(trim($brand));

        if (1 !== preg_match('/^[A-Z0-9_ -]{1,32}$/', $code)) {
            return '';
        }

        return self::BRANDS[$code] ?? ucwords(strtolower(str_replace('_', ' ', $code)));
    }

    private static function lastDigitsOf(mixed $maskedNumber): ?string
    {
        if (!\is_string($maskedNumber) || 1 !== preg_match('/(\d{4})$/', trim($maskedNumber), $match)) {
            return null;
        }

        return $match[1];
    }

    private static function expiryOf(mixed $month, mixed $year): ?\DateTimeImmutable
    {
        if (!is_numeric($month) || !is_numeric($year)) {
            return null;
        }

        $month = (int) $month;
        $year = (int) $year;

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
            return null;
        }

        return new \DateTimeImmutable(\sprintf('last day of %04d-%02d 00:00:00', $year, $month), new \DateTimeZone('UTC'));
    }
}
