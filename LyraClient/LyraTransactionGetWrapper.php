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

/**
 * A wrapper around CreatePayment service to manage bith Javascript Client and PCI-DSS calls
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 27/05/2019 17:33
 */

class LyraTransactionGetWrapper extends LyraPaymentManagementWrapper
{
    /**
     * Perform a Get request to get transaction info and store it in the history table
     *
     * @param Order $order
     * @throws LyraException
     * @throws \Exception
     */
    public function getTransaction(Order $order)
    {
        $response = $this->sendTransactionGetRequest($order);

        $this->processTransactionGetResponse($response);
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
