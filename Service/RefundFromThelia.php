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

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\LyraClient\LyraTransactionRefundWrapper;
use PayzenEmbedded\LyraClient\OrderStatusNotUpdatedException;
use PayzenEmbedded\LyraClient\PlatformRefusalException;
use PayzenEmbedded\LyraClient\RefundAmount;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\RefundOutcomeUnknownException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Thelia\Domain\Payment\DTO\PaymentOperationResult;
use Thelia\Domain\Payment\Enum\RefundReason;
use Thelia\Domain\Payment\Exception\PaymentRefusedException;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\Order;

/**
 * The refund Thelia asks of the module from its own refund dialog: the same Transaction/CancelOrRefund
 * call, under the same lock and with the same checks, as the refund form of the module.
 *
 * What the platform refuses is a refusal for Thelia, with the code it answered; a call that got no
 * answer is left to surface, Thelia then keeps the line pending until the platform says. The line is
 * filed under the uuid of the transaction the platform created, which is how its notification finds it.
 */
#[Autoconfigure(public: true)]
final readonly class RefundFromThelia
{
    public function __construct(
        private LyraTransactionRefundWrapper $refundWrapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Gives money back at the platform: a refund transaction of its own, or the cancellation of
     * a payment not captured yet.
     */
    public function refund(Order $order, float $amount, RefundReason $reason, ?string $comment): PaymentOperationResult
    {
        $minorAmount = RefundAmount::fromMajor($amount, (string) $order->getCurrency()->getCode());
        // What the PayZen back-office shows beside the refund: the reason, then the merchant's words.
        $note = $reason->label().(null !== $comment && '' !== $comment ? ': '.$comment : '');

        try {
            $record = $this->refundWrapper->refundRecorded($order, $minorAmount, $note);
        } catch (OrderStatusNotUpdatedException $orderNotMoved) {
            // The platform gave the money back; only the order could not follow.
            return $this->resultOf($orderNotMoved->outcome, (string) $orderNotMoved->transactionUuid);
        } catch (RefundOutcomeUnknownException $unknown) {
            throw $unknown;
        } catch (TheliaProcessException $refusal) {
            throw PaymentRefusedException::withProviderCode(
                $refusal->getMessage(),
                $refusal instanceof PlatformRefusalException ? $refusal->platformCode : null,
                $refusal,
            );
        } catch (LyraException $unreachable) {
            // Raised while listing the order's transactions, before the refund is sent: nothing moved.
            // The SDK words its failure with the URL it called and the raw answer of the platform:
            // that goes to the log only; the journal and the administrator read that nothing moved.
            $this->logger->error(\sprintf('Order %s: PayZen could not list the transactions of the order: %s', (string) $order->getRef(), $unreachable->getMessage()), ['exception' => $unreachable]);

            throw new PaymentRefusedException(\sprintf('PayZen could not list the transactions of order %s: nothing was sent to the platform. Try again later.', (string) $order->getRef()), 0, $unreachable);
        }

        return $this->resultOf($record->outcome, $record->transactionUuid);
    }

    private function resultOf(RefundOutcome $outcome, string $transactionUuid): PaymentOperationResult
    {
        return RefundOutcome::Pending === $outcome
            ? PaymentOperationResult::pending($transactionUuid)
            : PaymentOperationResult::succeeded($transactionUuid);
    }
}
