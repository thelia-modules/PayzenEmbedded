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

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\Admin;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;

/**
 * A wrapper around the Transaction/CancelOrRefund service: gives the shopper their money back.
 */
class LyraTransactionRefundWrapper extends LyraPaymentManagementWrapper
{
    /**
     * Give money back to the shopper of an order, in full or in part.
     *
     * The platform decides what the request means: a transaction waiting for its capture is
     * cancelled, a captured one gets a refund transaction of its own. A cancellation is always
     * total; lowering a transaction before its capture is the job of the update service.
     *
     * One refund at a time per order: two requests reading the same ledger would both pass its
     * check and both reach the platform.
     *
     * @param int      $amount                 in the smallest unit of the order currency
     * @param int|null $expectedRefundedAmount what the caller saw as refunded so far, in the same unit: the
     *                                         refund is refused when the platform knows another figure, so
     *                                         that a refund the caller did not see is never asked for again.
     *                                         Null skips that check, the lock and the balance alone remain.
     *
     * @throws LyraException                   when the platform cannot list the order's transactions
     * @throws TheliaProcessException          when the amount cannot be refunded, or the platform refused
     * @throws RefundOutcomeUnknownException   when the platform did not answer, or its answer could not be recorded
     * @throws OrderStatusNotUpdatedException when the money moved but the order could not follow
     */
    public function refundTransaction(Order $order, int $amount, ?string $comment = null, ?Admin $admin = null, ?int $expectedRefundedAmount = null): RefundOutcome
    {
        if (PayzenEmbedded::getModuleId() !== (int) $order->getPaymentModuleId()) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans('This order was not paid with PayZen.', [], PayzenEmbedded::DOMAIN_NAME)
            );
        }

        if ('' === (string) $order->getTransactionRef()) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans('This order carries no PayZen transaction yet.', [], PayzenEmbedded::DOMAIN_NAME)
            );
        }

        $lock = $this->acquireOrderLock($order);

        try {
            // The history may be behind the platform: a refund whose answer was lost to a timeout,
            // or one made from the PayZen back-office. The platform's own list of the order's
            // transactions is recorded first, so the ledger counts what was really given back.
            $this->syncTransactions($order);
            $this->refreshOrderLock($lock, $order);

            $ledger = (new TransactionHistoryReader())->ledgerOf($order);
            $currencyCode = strtoupper($order->getCurrency()->getCode());

            // The decision is the guard's, see RefundGuard: here it is only put into words.
            $verdict = RefundGuard::verdict($ledger, $amount, $expectedRefundedAmount);

            if (RefundVerdict::StaleView === $verdict) {
                throw new TheliaProcessException(
                    Translator::getInstance()->trans(
                        'The refunds of this order changed since the page was displayed: %refunded %currency refunded so far, %refundable %currency left to refund. Check the history before asking again.',
                        [
                            '%refunded' => RefundAmount::format($ledger->refundedAmount, $currencyCode),
                            '%refundable' => RefundAmount::format($ledger->refundableAmount(), $currencyCode),
                            '%currency' => $currencyCode,
                        ],
                        PayzenEmbedded::DOMAIN_NAME
                    )
                );
            }

            if (RefundVerdict::OutOfBounds === $verdict) {
                throw new TheliaProcessException(
                    Translator::getInstance()->trans(
                        $ledger->isCancellable()
                            ? 'This payment is waiting for its capture: it can only be cancelled in full, for %amount %currency.'
                            : 'The amount to refund should be greater than 0 and at most %amount %currency.',
                        ['%amount' => RefundAmount::format($ledger->maximumAmount(), $currencyCode), '%currency' => $currencyCode],
                        PayzenEmbedded::DOMAIN_NAME
                    )
                );
            }

            try {
                $response = $this->sendCancelOrRefundRequest($order, $amount, $comment);
            } catch (LyraException $exception) {
                // No answer is not a refusal: the platform may have processed the request before
                // the connection dropped. The next attempt starts by reading the platform's list,
                // and refuses to go on until the page shows what that list holds.
                $this->log->addError(sprintf('Order %s: PayZen did not answer the refund request: %s', $order->getRef(), $exception->getMessage()));

                throw new RefundOutcomeUnknownException(
                    Translator::getInstance()->trans('PayZen did not answer the refund request: refresh the order before trying again.', [], PayzenEmbedded::DOMAIN_NAME),
                    $exception
                );
            }

            try {
                return $this->processCancelOrRefundResponse($order, $response, $admin);
            } catch (TheliaProcessException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                // The platform has answered: whatever failed afterwards, the money may have moved.
                $this->log->addError(sprintf('Order %s: PayZen answered the refund, but recording it failed: %s', $order->getRef(), $exception->getMessage()));

                throw new RefundOutcomeUnknownException(
                    Translator::getInstance()->trans('PayZen answered the refund, but its result could not be recorded: refresh the order before trying again.', [], PayzenEmbedded::DOMAIN_NAME),
                    $exception
                );
            }
        } finally {
            $this->releaseOrderLock($lock, $order);
        }
    }

    /**
     * Build the Transaction/CancelOrRefund parameters, and call the service.
     *
     * @param int $amount in the smallest unit of the order currency
     *
     * @return array the web service result (see https://payzen.io/fr-FR/rest/V4.0/api/playground/Transaction/CancelOrRefund/)
     *
     * @throws LyraException
     */
    private function sendCancelOrRefundRequest(Order $order, int $amount, ?string $comment = null): array
    {
        $parameters = [
            'uuid' => $order->getTransactionRef(),
            'amount' => $amount,
            'currency' => strtoupper($order->getCurrency()->getCode()),
            // The platform cancels a transaction waiting for its capture, and refunds a captured one.
            'resolutionMode' => 'AUTO',
        ];

        if (null !== $comment && '' !== trim($comment)) {
            $parameters['comment'] = trim($comment);
        }

        return $this->post('V4/Transaction/CancelOrRefund', $parameters);
    }

    /**
     * Read what the platform did, record it, and move the order accordingly: cancelled when the
     * transaction was cancelled, refunded when nothing is left to refund, untouched after a
     * partial or a pending refund. An answer of another shape is refused before anything is written.
     *
     * @throws TheliaProcessException when the platform refused, or answered something unexpected
     * @throws OrderStatusNotUpdatedException when the refund is recorded but the order could not follow
     */
    private function processCancelOrRefundResponse(Order $order, array $response, ?Admin $admin = null): RefundOutcome
    {
        $answer = \is_array($response['answer'] ?? null) ? $response['answer'] : [];

        // A refusal is an ERROR status with an error answer: read as the Order/Get answer is, before
        // anything in the answer is taken for a transaction.
        if (($response['status'] ?? null) !== 'SUCCESS') {
            throw new TheliaProcessException(Translator::getInstance()->trans('Cannot refund the transaction. Error is : %message (code %code)', [
                '%code' => (string) ($answer['errorCode'] ?? 'undefined error code'),
                '%message' => (string) ($answer['errorMessage'] ?? 'undefined error message'),
            ], PayzenEmbedded::DOMAIN_NAME));
        }

        // The shape of this answer was taken from the documentation: keep what the platform really sends.
        $this->log->addInfo(sprintf(
            'PayZen CancelOrRefund answer for order %s: %s',
            $order->getRef(),
            json_encode(array_intersect_key($answer, array_flip(['uuid', 'status', 'detailedStatus', 'operationType', 'amount', 'currency', 'errorCode', 'errorMessage', 'detailedErrorCode'])))
        ));

        $debitUuid = (string) $order->getTransactionRef();

        $resolution = RefundResolution::fromAnswer(
            $answer,
            $debitUuid,
            static fn (string $message, array $parameters): string => Translator::getInstance()->trans($message, $parameters, PayzenEmbedded::DOMAIN_NAME)
        );

        $this->updateTransactionHistory($resolution->answer, $order, $admin, $debitUuid);

        $ledgerAfter = (new TransactionHistoryReader())->ledgerOf($order);
        $outcome = $resolution->outcome($ledgerAfter);
        $currencyCode = strtoupper($order->getCurrency()->getCode());

        $this->log->addInfo(match ($outcome) {
            RefundOutcome::Cancelled => Translator::getInstance()->trans('Order %ref: transaction cancelled before its capture.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::Refunded => Translator::getInstance()->trans('Order %ref refunded in full.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::PartiallyRefunded => Translator::getInstance()->trans(
                'Order %ref: %amount refunded, %left left to refund.',
                [
                    '%ref' => $order->getRef(),
                    '%amount' => RefundAmount::format($resolution->transaction->amount, $currencyCode),
                    '%left' => RefundAmount::format($ledgerAfter->refundableAmount(), $currencyCode),
                ],
                PayzenEmbedded::DOMAIN_NAME
            ),
            RefundOutcome::Pending => Translator::getInstance()->trans('Order %ref: refund of %amount accepted by the platform, still running.', ['%ref' => $order->getRef(), '%amount' => RefundAmount::format($resolution->transaction->amount, $currencyCode)], PayzenEmbedded::DOMAIN_NAME),
        });

        $newStatus = match ($outcome) {
            RefundOutcome::Cancelled => OrderStatusQuery::getCancelledStatus(),
            RefundOutcome::Refunded => OrderStatusQuery::getRefundedStatus(),
            RefundOutcome::PartiallyRefunded, RefundOutcome::Pending => null,
        };

        if (null === $newStatus) {
            return $outcome;
        }

        try {
            $this->setOrderStatus($order, $newStatus);
        } catch (\Throwable $exception) {
            // The money moved and the history says so: this is not a failed refund.
            $this->log->addError(sprintf('Order %s: refund recorded, but its status could not be updated: %s', $order->getRef(), $exception->getMessage()));

            throw new OrderStatusNotUpdatedException(
                $outcome,
                Translator::getInstance()->trans('The refund was made, but the order status could not be updated: check the order.', [], PayzenEmbedded::DOMAIN_NAME),
                $exception
            );
        }

        return $outcome;
    }
}
