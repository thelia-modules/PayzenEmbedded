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
     * @param int $amount in the smallest unit of the order currency
     *
     * @throws LyraException
     * @throws TheliaProcessException when the amount cannot be refunded, or the platform refused
     */
    public function refundTransaction(Order $order, int $amount, ?string $comment = null, ?Admin $admin = null): RefundOutcome
    {
        $ledger = (new TransactionHistoryReader())->ledgerOf($order);

        if (!$ledger->covers($amount)) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans(
                    'The amount to refund should be between 0 and %amount.',
                    ['%amount' => number_format($ledger->refundableAmount() / 100, 2, '.', '')],
                    PayzenEmbedded::DOMAIN_NAME
                )
            );
        }

        $response = $this->sendCancelOrRefundRequest($order, $amount, $comment);

        return $this->processCancelOrRefundResponse($order, $response, $admin);
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
    public function sendCancelOrRefundRequest(Order $order, int $amount, ?string $comment = null): array
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
     * Record what the platform did, and move the order accordingly: cancelled when the transaction
     * was cancelled, refunded when nothing is left to refund, untouched after a partial refund.
     *
     * @throws TheliaProcessException when the platform refused, or answered something unexpected
     */
    public function processCancelOrRefundResponse(Order $order, array $response, ?Admin $admin = null): RefundOutcome
    {
        if (!isset($response['answer']['uuid'])) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans(
                    'Cannot refund the transaction. Error is : %message (code %code)',
                    [
                        '%code' => $response['answer']['errorCode'] ?? 'undefined error code',
                        '%message' => $response['answer']['errorMessage'] ?? 'undefined error message',
                    ],
                    PayzenEmbedded::DOMAIN_NAME
                )
            );
        }

        $answer = $response['answer'];
        $outcome = TransactionOutcome::fromAnswer($answer);

        $this->updateTransactionHistory($answer, $order, $admin);

        if ($outcome->isCredit()) {
            if (!$outcome->isPaid()) {
                throw new TheliaProcessException(
                    Translator::getInstance()->trans(
                        'The refund was refused: %message (code %code)',
                        [
                            '%code' => $answer['errorCode'] ?? $answer['detailedStatus'] ?? '',
                            '%message' => $answer['errorMessage'] ?? $answer['detailedErrorMessage'] ?? '',
                        ],
                        PayzenEmbedded::DOMAIN_NAME
                    )
                );
            }

            $ledger = (new TransactionHistoryReader())->ledgerOf($order);

            if ($ledger->refundableAmount() > 0) {
                $this->log->addInfo(
                    Translator::getInstance()->trans(
                        'Order %ref: %amount refunded, %left left to refund.',
                        [
                            '%ref' => $order->getRef(),
                            '%amount' => number_format($outcome->amount / 100, 2, '.', ''),
                            '%left' => number_format($ledger->refundableAmount() / 100, 2, '.', ''),
                        ],
                        PayzenEmbedded::DOMAIN_NAME
                    )
                );

                return RefundOutcome::PartiallyRefunded;
            }

            $this->log->addInfo(
                Translator::getInstance()->trans('Order %ref refunded in full.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME)
            );

            $this->setOrderStatus($order, OrderStatusQuery::getRefundedStatus());

            return RefundOutcome::Refunded;
        }

        // The debit itself came back: the transaction was not captured, so the platform cancelled it.
        if ($outcome->isPaid()) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans(
                    'The transaction %uuid was neither cancelled nor refunded (status %status).',
                    ['%uuid' => $outcome->uuid, '%status' => $answer['detailedStatus'] ?? $outcome->status],
                    PayzenEmbedded::DOMAIN_NAME
                )
            );
        }

        $this->log->addInfo(
            Translator::getInstance()->trans('Order %ref: transaction cancelled before its capture.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME)
        );

        $this->setOrderStatus($order, OrderStatusQuery::getCancelledStatus());

        return RefundOutcome::Cancelled;
    }
}
