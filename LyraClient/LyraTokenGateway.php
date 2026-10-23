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
 *
 * PSP_1047 ("Alias already cancelled") is a success at once: the platform itself says the token is gone.
 *
 * Any other refusal is followed by a read of the token (Token/Get): one it says it already cancelled (a cancellation
 * date) is gone, which is what the shop wanted, so the cancellation succeeded. Any other case, or a read that fails,
 * is the refusal of the cancellation. "Not found" (PSP_1048, PSP_030), "invalid" (PSP_1049, PSP_031) and PSP_558
 * (alias status not compatible with the operation) stay refusals: a token unknown on this contract may exist on
 * another one (a card is tied to one contract), and dropping it from the shop would leave an active card at PayZen.
 */
class LyraTokenGateway extends LyraClientWrapper implements StoredTokenGateway
{
    public const CONNECTION_TIMEOUT = 5;

    public const TIMEOUT = 10;

    /**
     * "Alias already cancelled", from the PSP error codes page of the Lyra documentation (docs.lyra.com, REST V4.0,
     * errors_psp). The page of Token/Cancel does not list the codes it returns; this one says the alias is cancelled.
     */
    public const ALREADY_CANCELLED = 'PSP_1047';

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
            if (self::ALREADY_CANCELLED === ($response['answer']['errorCode'] ?? null) || $this->isGone($token)) {
                return;
            }

            $code = $response['answer']['errorCode'] ?? '';

            throw new TokenCancellationFailedException(\is_string($code) && 1 === preg_match('/^[A-Za-z0-9_]{1,16}$/', $code) ? $code : 'refused');
        }
    }

    /**
     * Whether the platform says the token is cancelled: a successful answer about that very token that carries a cancellation date.
     * Whatever else, a failed read included, is "not gone": the refusal stands.
     */
    private function isGone(string $token): bool
    {
        try {
            $response = $this->post('V4/Token/Get', ['paymentMethodToken' => $token]);
        } catch (\Throwable) {
            return false;
        }

        if ('SUCCESS' !== ($response['status'] ?? null) || !\is_array($response['answer'] ?? null)) {
            return false;
        }

        $cancellationDate = $response['answer']['cancellationDate'] ?? null;

        // The answer must be about the token asked for: a date on another token proves nothing.
        return $token === ($response['answer']['paymentMethodToken'] ?? null)
            && \is_string($cancellationDate) && '' !== $cancellationDate;
    }
}
