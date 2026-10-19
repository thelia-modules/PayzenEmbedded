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
 * What the module asks of the platform about a card it kept for a customer, apart from paying
 * with it. One seam, so that what the module does around it runs with no network.
 */
interface StoredTokenGateway
{
    /**
     * Ask the platform to forget the card.
     *
     * @throws TokenCancellationFailedException when the platform did not cancel it, or could not be reached
     */
    public function cancel(string $token): void;
}
