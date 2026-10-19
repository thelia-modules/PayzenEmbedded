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
 * The platform side of StoredTokenGateway: Token/Cancel, on the contract in scope.
 */
class LyraTokenGateway extends LyraClientWrapper implements StoredTokenGateway
{
    public const CONNECTION_TIMEOUT = 5;

    public const TIMEOUT = 10;

    public function cancel(string $token): void
    {
        // A shared client speaks for the contract in scope when it is asked, not when it was built.
        $this->useConfiguration();
        // The SDK waits 45 s to connect and 45 s for the answer: too long for a run over many cards, and for a
        // customer waiting for the page.
        $this->setTimeOuts(self::CONNECTION_TIMEOUT, self::TIMEOUT);

        try {
            $response = $this->post('V4/Token/Cancel', ['paymentMethodToken' => $token]);
        } catch (\Throwable) {
            // Not even by its class in a previous exception: see TokenCancellationFailedException.
            throw new TokenCancellationFailedException(TokenCancellationFailedException::UNREACHABLE);
        }

        if ('SUCCESS' !== ($response['status'] ?? null)) {
            $code = $response['answer']['errorCode'] ?? '';

            throw new TokenCancellationFailedException(\is_string($code) && 1 === preg_match('/^[A-Za-z0-9_]{1,16}$/', $code) ? $code : 'refused');
        }
    }
}
