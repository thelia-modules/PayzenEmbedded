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
 * The text columns of the transaction history and their size, as Config/schema.xml declares them.
 * What the platform says is cut to its column before it is written: every transaction the platform
 * lists is recorded, and a value too long is refused with the row on a strict server, the refund with it.
 */
final readonly class HistoryColumn
{
    public const LENGTHS = [
        'uuid' => 128,
        'status' => 32,
        'detailedStatus' => 64,
        'operationType' => 16,
        'parentUuid' => 128,
        'errorCode' => 10,
        'errorMessage' => 255,
        'detailedErrorCode' => 10,
        'detailedErrorMessage' => 255,
    ];

    /** The value cut to the column, or null when there is nothing to keep. */
    public static function bounded(string $column, mixed $value): ?string
    {
        $length = self::LENGTHS[$column] ?? throw new \InvalidArgumentException(sprintf('The transaction history has no text column "%s".', $column));

        if (!\is_scalar($value) || '' === (string) $value) {
            return null;
        }

        return mb_substr((string) $value, 0, $length);
    }
}
