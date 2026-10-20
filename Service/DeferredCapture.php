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

use PayzenEmbedded\LyraClient\LyraTransactionRefundWrapper;
use PayzenEmbedded\LyraClient\LyraTransactionUpdateWrapper;
use PayzenEmbedded\LyraClient\OrderStatusNotUpdatedException;
use PayzenEmbedded\LyraClient\RefundAmount;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\RefundOutcomeUnknownException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Thelia\Domain\Payment\DTO\PaymentOperationResult;
use Thelia\Domain\Payment\Exception\PaymentRefusedException;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransaction;

/**
 * The capture and the release Thelia asks of the module when it authorizes first.
 *
 * The capture validates the authorization on the platform at the amount asked, through the same
 * Transaction/Update call as the capture after picking, under the same lock: the platform takes it
 * at the remittance and lets the rest go. Only an ERROR answer is a refusal; once
 * the platform accepted, a failure to record its answer is logged, never reported as a refusal. Its outcome comes with the notification of the capture, so the line Thelia wrote
 * stays pending until then. The release cancels the whole authorization.
 *
 * What the platform refuses is a refusal for Thelia; a call that got no answer is left to surface,
 * Thelia then keeps the line pending until the platform says.
 */
#[Autoconfigure(public: true)]
final readonly class DeferredCapture
{
    public function __construct(
        private LyraTransactionUpdateWrapper $updateWrapper,
        private LyraTransactionRefundWrapper $refundWrapper,
        private LoggerInterface $logger,
    ) {
    }

    public function capture(Order $order, float $amount): PaymentOperationResult
    {
        // On the contract of the sale unit the order was placed on, as every other call about it.
        return $this->updateWrapper->forOrder($order, fn (): PaymentOperationResult => $this->captureOnItsContract($order, $amount));
    }

    private function captureOnItsContract(Order $order, float $amount): PaymentOperationResult
    {
        return $this->updateWrapper->withOrderLock($order, function () use ($order, $amount): PaymentOperationResult {
            try {
                $response = $this->updateWrapper->sendTransactionUpdateRequest($order, $amount, null, false);
            } catch (TheliaProcessException $unreadable) {
                // The amount could not be sent: nothing reached the platform.
                throw new PaymentRefusedException($unreadable->getMessage(), 0, $unreadable);
            }

            $status = $response['status'] ?? null;

            if ('SUCCESS' === $status && !isset($response['answer']['uuid'])) {
                // The platform may have validated: Thelia keeps the line pending until the
                // notification says, it does not offer a second capture.
                throw new \RuntimeException(\sprintf('PayZen answered the capture of order %s with a success that names no transaction.', (string) $order->getRef()));
            }

            if ('ERROR' !== $status && 'SUCCESS' !== $status) {
                // An answer the client does not know is no refusal: the platform may have
                // validated, so the line stays pending until the notification says.
                throw new \RuntimeException(\sprintf('PayZen answered the capture of order %s with the unknown status "%s".', (string) $order->getRef(), \is_scalar($status) ? (string) $status : get_debug_type($status)));
            }

            if ('ERROR' === $status) {
                throw PaymentRefusedException::withProviderCode(
                    \sprintf('PayZen refused the capture: %s', (string) ($response['answer']['errorMessage'] ?? 'no message')),
                    isset($response['answer']['errorCode']) ? (string) $response['answer']['errorCode'] : null,
                );
            }

            // The platform validated the authorization: the money is taken at the remittance,
            // whatever fails from here on, which only delays what the history shows.
            try {
                $this->updateWrapper->processTransactionUpdateResponse($response);
            } catch (\Throwable $failure) {
                $this->logger->error(\sprintf('Order %s: PayZen validated the capture, but recording its answer failed: %s', (string) $order->getRef(), $failure->getMessage()));
            }

            return PaymentOperationResult::pending((string) $response['answer']['uuid']);
        });
    }

    public function release(Order $order, OrderPaymentTransaction $transaction): PaymentOperationResult
    {
        $amount = RefundAmount::fromMajor((string) $transaction->getAmount(), (string) $order->getCurrency()->getCode());

        try {
            $outcome = $this->refundWrapper->refundTransaction($order, $amount, 'Authorization released by the shop');
        } catch (OrderStatusNotUpdatedException $orderNotMoved) {
            // The platform did what it was asked; only the order could not follow. Caught before
            // TheliaProcessException, which it extends: it is no refusal.
            $outcome = $orderNotMoved->outcome;
        } catch (RefundOutcomeUnknownException $unknown) {
            // The platform may have cancelled the authorization: no refusal either, Thelia keeps the
            // line pending until the platform says.
            throw $unknown;
        } catch (TheliaProcessException $refusal) {
            throw new PaymentRefusedException($refusal->getMessage(), 0, $refusal);
        }

        $reference = (string) $order->getTransactionRef();

        return RefundOutcome::Cancelled === $outcome
            ? PaymentOperationResult::succeeded($reference)
            : PaymentOperationResult::pending($reference);
    }
}
