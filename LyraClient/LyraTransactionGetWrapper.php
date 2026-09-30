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
     * Bring the order up to date with the platform: the transaction the order stands on is read
     * first and may move the order, as a notification would; then every transaction the platform
     * lists for the order (a refund made from the PayZen back-office, a cancellation, an attempt
     * the notification never reached the shop for) is recorded as the platform holds it, which
     * the arbiter alone would refuse once the transaction is finished. A refund that leaves
     * nothing to refund settles the order, as its notification would; a cancellation made from
     * the PayZen back-office shows in the history and leaves the order status to the shop.
     *
     * An order without a transaction yet (its notification never came) has only the platform's
     * list to learn from. The order is held while it is read, so that a refund and a refresh
     * never write its history at the same time.
     *
     * @throws LyraException
     * @throws TheliaProcessException when the order was not paid with PayZen, is held by another
     *                                operation, or the platform refused
     * @throws \Exception
     */
    public function getTransaction(Order $order): void
    {
        if (PayzenEmbedded::getModuleId() !== (int) $order->getPaymentModuleId()) {
            throw new TheliaProcessException(
                Translator::getInstance()->trans('This order was not paid with PayZen.', [], PayzenEmbedded::DOMAIN_NAME)
            );
        }

        $lock = $this->acquireOrderLock($order);

        try {
            if ('' !== (string) $order->getTransactionRef()) {
                $this->processTransactionGetResponse($this->sendTransactionGetRequest($order));
            }

            $this->syncTransactions($order);

            $ledger = (new TransactionHistoryReader())->ledgerOf($order);

            if ($ledger->isFullyRefunded()) {
                $this->setOrderStatus($order, OrderStatusQuery::getRefundedStatus());
            }
        } finally {
            $lock->release();
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

        // Be sure to have transaction data.
        if (isset($response['answer']['uuid'])) {
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
