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

namespace PayzenEmbedded\Service;

use PayzenEmbedded\LyraClient\StoredTokenGateway;
use PayzenEmbedded\LyraClient\TokenCancellationFailedException;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Thelia\Domain\Payment\Exception\StoredMethodRefusedException;
use Thelia\Model\CustomerPaymentMethod;

/**
 * Has PayZen forget a card the shop is about to drop.
 *
 * A card PayZen did not cancel is kept by the shop, which says so to the customer (the core's own
 * words): they ask again later, rather than believing a card is gone that PayZen still holds.
 */
#[Autoconfigure(public: true)]
class StoredMethodRevoker
{
    public function __construct(
        private readonly StoredTokenGateway $gateway,
    ) {
    }

    /**
     * @throws StoredMethodRefusedException when PayZen did not cancel the card, or could not be reached
     */
    public function revoke(CustomerPaymentMethod $method): void
    {
        if ((int) $method->getPaymentModuleId() !== PayzenEmbedded::getModuleId()) {
            throw new \InvalidArgumentException('This stored payment method was not kept through PayZen.');
        }

        try {
            $this->gateway->cancel((string) $method->getToken());
        } catch (TokenCancellationFailedException $failure) {
            // What the platform said goes to the reason only, the core writes it nowhere.
            throw new StoredMethodRefusedException($failure->reason);
        }
    }
}
