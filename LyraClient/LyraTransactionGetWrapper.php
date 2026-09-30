<?php
/*************************************************************************************/
/*      Copyright (c) Franck Allimant, CQFDev                                        */
/*      email : thelia@cqfdev.fr                                                     */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE      */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded\LyraClient;

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\Order;
use Thelia\Model\OrderStatusQuery;

/**
 * A wrapper around CreatePayment service to manage bith Javascript Client and PCI-DSS calls
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 27/05/2019 17:33
 */

class LyraTransactionGetWrapper extends LyraPaymentManagementWrapper
{
    /**
     * Bring the order up to date with the platform: every transaction the platform lists for the
     * order (a refund made from the PayZen back-office, a cancellation, an attempt the notification
     * never reached the shop for) is recorded first, as the platform holds it, which the arbiter
     * alone would refuse once the transaction is finished. Then the transaction the order stands on
     * is read and may move the order, as a notification would, with the refunds already counted: a
     * refund that leaves nothing to refund settles the order. A cancellation made from the PayZen
     * back-office cancels the order while the history holds the authorisation as running; one of a
     * payment the history already holds as paid only shows in the history, and leaves the order
     * status to the shop.
     *
     * An order without a transaction yet (its notification never came) has only the platform's
     * list to learn from. The order is held while it is read, so that a refund and a refresh
     * never write its history at the same time.
     *
     * @throws LyraException
     * @throws TheliaProcessException when the order was not paid with PayZen, is held by another
     *                                operation, or the platform refused
     * @return list<TransactionOutcome> the attempts the platform lists that outrank the one the order
     *                                   stands on: only their notification moves the order onto them
     *
     * @throws \Exception
     */
    public function getTransaction(Order $order): array
    {
        if (PayzenEmbedded::getModuleId() !== (int) $order->getPaymentModuleId()) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans('This order was not paid with PayZen.', [], PayzenEmbedded::DOMAIN_NAME)
            );
        }

        $lock = $this->acquireOrderLock($order);
        try {
            // What the order stands on is read again under the lock: a notification may have moved it
            // while the operation waited. Inside the try: the lock is released whatever happens.
            $order->reload();

            // The platform's list first: the refunds it holds are in the history before the transaction
            // the order stands on moves it, so that an order refunded on the platform goes to refunded,
            // not through paid (and the host's pickup notice). The list leaves that transaction to the
            // notification path when it changed, see syncTransactions().
            $diverging = $this->syncTransactions($order);
            $this->refreshOrderLock($lock, $order);

            if ('' !== (string) $order->getTransactionRef()) {
                $this->processTransactionGetResponse($this->sendTransactionGetRequest($order));
                // The response was applied to an order of its own: this one is read again.
                $order->reload();
            }

            // Once the order had its chance to move on it, the transaction the list held back is
            // written as the platform holds it, even where the arbiter left it (a finished one).
            if (null !== $diverging) {
                $this->updateTransactionHistory($diverging, $order);
            }

            $ledger = (new TransactionHistoryReader())->ledgerOf($order);

            // Only an order moved on its payment is refunded: one that carries no transaction yet was
            // never paid in the shop's eyes, and the notification of its payment would undo the status.
            if ('' !== (string) $order->getTransactionRef() && $ledger->isFullyRefunded()) {
                $this->setOrderStatus($order, OrderStatusQuery::getRefundedStatus());
            }

            return GoverningTransaction::outrankingAttempts(
                (new TransactionHistoryReader())->outcomesOf($order),
                (string) $order->getTransactionRef()
            );
        } finally {
            $this->releaseOrderLock($lock, $order);
        }
    }

    /**
     * Build the Transaction/Update parameters, and call te service.
     *
     * @param Order $order the order to process
     *
     * @return array the web service result Common/ResponseCodeAnswer (see https://payzen.io/fr-FR/rest/V4.0/api/playground.html?ws=Common/ResponseCodeAnswer)
     *
     * @throws LyraException
     */
    public function sendTransactionGetRequest(Order $order)
    {
        // Request parameters (see https://payzen.io/fr-FR/rest/V4.0/api/playground.html?ws=Transaction/Update)
        $parameters = [
            'uuid' => $order->getTransactionRef()
        ];

        return $this->post("V4/Transaction/Get", $parameters);
    }

    /**
     * Process a Transaction/Update response and update the order accordingly.
     *
     * @param array $response a CreatePayment response
     * @return int the payment status, one of self::PAYMENT_STATUS_* value
     * @throws \Exception
     */
    public function processTransactionGetResponse($response)
    {
        $paymentStatus = self::PAYMENT_STATUS_NOT_PAID;

        // Be sure to have transaction data: an ERROR status carries an error answer, never a transaction.
        if (($response['status'] ?? null) === 'SUCCESS' && isset($response['answer']['uuid'])) {
            $orderTransaction = $response['answer']['uuid'];

            if (null !== $order = $this->getOrderByTransaction($orderTransaction)) {
                $paymentStatus = $this->processOrderStatus($order, $response['answer']);
            }
        } else {
            throw new TheliaProcessException(
                Translator::getInstance()->trans(
                    'Cannnot get transaction information %code : %message',
                    [
                        '%code' => isset($response['answer']['errorCode']) ? $response['answer']['errorCode'] : 'undefined error code',
                        '%message' => isset($response['answer']['errorMessage']) ? $response['answer']['errorMessage'] : 'undefined error message',
                    ],
                    PayzenEmbedded::DOMAIN_NAME
                )
            );
        }

        return $paymentStatus;
    }
}
