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
 * A sale unit whose own contract lacks a setting: nothing is paid, notified, refunded or updated
 * on it, and the national contract never stands in for it. The message names the settings, never
 * a value.
 */
final class IncompleteSaleUnitContractException extends \RuntimeException
{
    /**
     * @param list<string> $missingSettings
     */
    public function __construct(
        public readonly int $saleUnitId,
        public readonly array $missingSettings,
    ) {
        parent::__construct(sprintf(
            'The PayZen contract of sale unit %d is incomplete (missing: %s): nothing is done on it, and the national contract is not used in its place.',
            $saleUnitId,
            implode(', ', $missingSettings),
        ));
    }
}
