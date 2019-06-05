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

class LyraTransactionUpdateWrapper extends LyraPaymentManagementWrapper
{
    /**
     * Process the Transaction/Update request, and update the order if required.
     *
     * @param Order $order the order to process
     * @param float $amount the amount of the transaction should be <= to the current amount.
     * @param \DateTime|null $captureDate the expected cature date, or null to use the default one.
     * @param boolean|null $manualValidation If false, it will be automatically validated, if null, the default configured in the PayZen back-offcie will be used.
     *
     * @return int the order payment statrus, one of self::PAYEMENT_STATUS_*
     *
     * @throws LyraException
     * @throws \Exception
     */
    public function updateTransaction(Order $order, $amount, $captureDate, $manualValidation)
    {
        $response = $this->sendTransactionUpdateRequest($order, $amount, $captureDate, $manualValidation);

        return $this->processTransactionUpdateResponse($response);
    }

    /**
     * Build the Transaction/Update parameters, and call te service.
     *
     * @param Order $order the order to process
     * @param float $amount the amount of the transaction should be <= to the current amount.
     * @param \DateTime|null $captureDate the expected cature date, or null to use the default one.
     * @param boolean|null $manualValidation If false, it will be automatically validated, if null, the default configured in the PayZen back-offcie will be used.
     *
     * @return array the web service result Common/ResponseCodeAnswer (see https://payzen.io/fr-FR/rest/V4.0/api/playground.html?ws=Common/ResponseCodeAnswer)
     *
     * @throws LyraException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function sendTransactionUpdateRequest(Order $order, $amount, $captureDate, $manualValidation)
    {
        // Make the manualValidation parameter. We can only change from manual to automatic, automatic to manual is not allowed.
        if (false === $manualValidation) {
            $manualValidationParam = 'NO';
        } else {
            $manualValidationParam = null;
        }

        // Make the expectedCatureDate parameter
        if (null !== $captureDate) {
            $captureDateParam = $captureDate->format("Y-m-d");
        } else {
            $captureDateParam = null;
        }

        // Request parameters (see https://payzen.io/fr-FR/rest/V4.0/api/playground.html?ws=Transaction/Update)
        $parameters = [
            'uuid' => $order->getTransactionRef(),
            'cardUpdate' => [
                'amount' => intval(strval($amount * 100)),
                'currency' => strtoupper($order->getCurrency()->getCode()),
                'expectedCaptureDate' => $captureDateParam,
                'manualValidation' => $manualValidationParam
            ],
        ];

        return $this->post("V4/Transaction/Update", $parameters);
    }

    /**
     * Process a Transaction/Update response and update the order accordingly.
     *
     * @param array $response a CreatePayment response
     * @return bool true if the payement is successful, false otherwise.
     * @throws \Exception
     */
    public function processTransactionUpdateResponse($response)
    {
        $paymentStatus = self::PAYEMENT_STATUS_NOT_PAID;

        // Be sure to have transaction data.
        if (isset($response['answer']['uuid'])) {
            $orderTransaction = $response['answer']['uuid'];

            $this->log->addInfo(Translator::getInstance()->trans("Payzen response received for transaction %ref.", ['%ref' => $orderTransaction], PayzenEmbedded::DOMAIN_NAME));

            if (null !== $order = $this->getOrderByTransaction($orderTransaction)) {
                $paymentStatus = $this->processOrderStatus($order, $response['answer']);
            }

            $this->log->info(Translator::getInstance()->trans("PayZen response for order %ref processing teminated.", ['%ref' => $order->getRef()], PayzenEmbedded::DOMAIN_NAME));
        } else {
            throw new TheliaProcessException(
                Translator::getInstance()->trans(
                    'Cannnot change transaction. Error is : %message (code %code)',
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
