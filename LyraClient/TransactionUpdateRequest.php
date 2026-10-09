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
 * What the update form of the order page asks of the platform.
 *
 * Authorizing first, the capture is Thelia's, from its capture dialog, with its right, its checks
 * and its payment journal: the form then only moves the capture date. Whatever a request carries
 * for the amount and the validation, the amount the platform holds is kept and nothing is validated.
 */
final readonly class TransactionUpdateRequest
{
    public function __construct(
        public string $amount,
        public ?\DateTimeInterface $captureDate,
        public bool $manualValidation,
    ) {
    }

    /**
     * @param array{amount?: mixed, capture_date?: mixed, automatic_validation?: mixed} $data the submitted form
     * @param string $heldAmount what the platform holds for the transaction, in the major unit
     */
    public static function from(array $data, bool $authorizesFirst, string $heldAmount): self
    {
        $captureDate = ($data['capture_date'] ?? null) instanceof \DateTimeInterface ? $data['capture_date'] : null;

        if ($authorizesFirst) {
            return new self($heldAmount, $captureDate, true);
        }

        return new self((string) ($data['amount'] ?? ''), $captureDate, !((bool) ($data['automatic_validation'] ?? false)));
    }
}
