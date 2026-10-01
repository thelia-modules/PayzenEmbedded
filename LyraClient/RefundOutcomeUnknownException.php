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

use Thelia\Exception\TheliaProcessException;

/**
 * The platform was asked for the refund and may have made it, but what happened next (its answer,
 * the history) could not be settled. The refund must not be reported as "not sent": the order has
 * to be refreshed from the platform before any new attempt, which the next attempt does first.
 */
final class RefundOutcomeUnknownException extends TheliaProcessException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, null, $previous);
    }
}
