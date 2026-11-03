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
 * What the platform did with a refund request, and the transaction it did it with: the credit it
 * created, or the debit it cancelled before its capture.
 */
final readonly class RefundRecord
{
    public function __construct(
        public RefundOutcome $outcome,
        public string $transactionUuid,
    ) {
    }
}
