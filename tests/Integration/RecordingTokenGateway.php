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

namespace PayzenEmbedded\Tests\Integration;

use PayzenEmbedded\LyraClient\StoredTokenGateway;

/**
 * The platform side of the cards, replaced by a script: it keeps what it was asked to cancel, or fails as told.
 */
final class RecordingTokenGateway implements StoredTokenGateway
{
    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<int|null> the sale unit whose contract was in scope at each cancellation, null being the national one */
    public array $contractsOfTheCancellations = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function cancel(string $token): void
    {
        $this->contractsOfTheCancellations[] = \Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope::current();

        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->cancelled[] = $token;
    }
}
