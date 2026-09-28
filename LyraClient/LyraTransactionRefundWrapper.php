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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\TheliaProcessException;
use Thelia\Log\Tlog;
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
     * @param int $amount in the smallest unit of the order currency
     *
     * @throws LyraException
     * @throws TheliaProcessException when the amount cannot be refunded, or the platform refused
     */
    public function refundTransaction(Order $order, int $amount, ?string $comment = null, ?Admin $admin = null): RefundOutcome
    {
        $lock = (new LockFactory(new FlockStore()))->createLock('payzen-embedded-refund-' . $order->getId(), 60.0);

        if (!$lock->acquire()) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans('A refund of this order is already running.', [], PayzenEmbedded::DOMAIN_NAME)
            );
        }

        try {
            $ledger = (new TransactionHistoryReader())->ledgerOf($order);
            $currencyCode = strtoupper($order->getCurrency()->getCode());

            if (!$ledger->allows($amount)) {
                throw new TheliaProcessException(
                    Translator::getInstance()->trans(
                        'The amount to refund should be greater than 0 and at most %amount %currency.',
                        ['%amount' => RefundAmount::format($ledger->maximumAmount(), $currencyCode), '%currency' => $currencyCode],
                        PayzenEmbedded::DOMAIN_NAME
                    )
                );
            }

            $response = $this->sendCancelOrRefundRequest($order, $amount, $comment);

            return $this->processCancelOrRefundResponse($order, $response, $admin);
        } finally {
            $lock->release();
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
     * Read what the platform did, record it, and move the order accordingly: cancelled when the
     * transaction was cancelled, refunded when nothing is left to refund, untouched after a
     * partial refund. An answer of another shape is refused before anything is written.
     *
     * @throws TheliaProcessException when the platform refused, or answered something unexpected
     */
    public function processCancelOrRefundResponse(Order $order, array $response, ?Admin $admin = null): RefundOutcome
    {
        $answer = \is_array($response['answer'] ?? null) ? $response['answer'] : [];

        // The shape of this answer was taken from the documentation: keep what the platform really sends.
        $this->log->addInfo(sprintf(
            'PayZen CancelOrRefund answer for order %s: %s',
            $order->getRef(),
            json_encode(array_intersect_key($answer, array_flip(['uuid', 'status', 'detailedStatus', 'operationType', 'amount', 'currency', 'errorCode', 'errorMessage', 'detailedErrorCode'])))
        ));

        $resolution = RefundResolution::fromAnswer(
            $answer,
            (string) $order->getTransactionRef(),
            static fn (string $message, array $parameters): string => Translator::getInstance()->trans($message, $parameters, PayzenEmbedded::DOMAIN_NAME)
        );

        $this->updateTransactionHistory($resolution->answer, $order, $admin);

        $outcome = $resolution->outcome((new TransactionHistoryReader())->ledgerOf($order));
        $currencyCode = strtoupper($order->getCurrency()->getCode());

        switch ($outcome) {
            case RefundOutcome::Cancelled:
                $this->log->addInfo(Translator::getInstance()->trans('Order %ref: transaction cancelled before its capture.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME));
                $this->setOrderStatus($order, OrderStatusQuery::getCancelledStatus());
                break;

            case RefundOutcome::Refunded:
                $this->log->addInfo(Translator::getInstance()->trans('Order %ref refunded in full.', ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME));
                $this->setOrderStatus($order, OrderStatusQuery::getRefundedStatus());
                break;

            case RefundOutcome::PartiallyRefunded:
                $this->log->addInfo(Translator::getInstance()->trans(
                    'Order %ref: %amount refunded, %left left to refund.',
                    [
                        '%ref' => $order->getRef(),
                        '%amount' => RefundAmount::format($resolution->transaction->amount, $currencyCode),
                        '%left' => RefundAmount::format((new TransactionHistoryReader())->ledgerOf($order)->refundableAmount(), $currencyCode),
                    ],
                    PayzenEmbedded::DOMAIN_NAME
                ));
                break;
        }

        return $outcome;
    }
}
