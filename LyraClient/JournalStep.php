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
 * One line to write to the payment journal of Thelia, with the transaction it is about.
 */
final readonly class JournalStep
{
    /**
     * @param int $amount in the smallest unit of the currency, as the platform counts it
     */
    public function __construct(
        public JournalMovement $movement,
        public string $uuid,
        public int $amount,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }
}
