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
 * PayZen refused a refund or a cancellation: the message for the merchant, and the code the
 * platform answered, which Thelia writes on the journal line apart from the message.
 */
final class PlatformRefusalException extends TheliaProcessException
{
    public function __construct(string $message, public readonly ?string $platformCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, null, null, $previous);
    }
}
