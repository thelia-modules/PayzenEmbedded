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
 * The platform did not cancel a token. What it carries is safe to write anywhere: the code the
 * platform gave, or a word of ours. Never what the platform said, nor the exception of the SDK,
 * which quotes the URL and the answer, and the answer may quote the token.
 */
final class TokenCancellationFailedException extends \RuntimeException
{
    /** The platform could not be reached: another card would meet the same wait. */
    public const UNREACHABLE = 'unreachable';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('PayZen did not cancel the stored payment method.');
    }
}
