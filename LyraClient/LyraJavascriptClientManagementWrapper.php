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

use PayzenEmbedded\PayzenEmbedded;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\Order;
use Thelia\Tools\URL;

/**
 * The Javascript client processing.
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 27/05/2019 17:33
 */

class LyraJavascriptClientManagementWrapper extends LyraPaymentManagementWrapper
{
    /**
     * Request payment of order.
     *
     * @param Order $order
     * @return array an array containing payment result.
     */
    public function payOrder(Order $order)
    {
        $resultData = [
            'success' => false,
            'order_id' => $order->getId(),
            'errorCode' => '',
            'errorMessage' => '',
            'detailedErrorCode' => '',
            'detailedErrorMessage' => '',
        ];

        try {
            // Send the payment request
            $response = $this->sendCreatePayementRequest($order);

            // Process the payment response.
            $resultData = $this->processCreatePaymentResponse($order, $response, $resultData);
        } catch (RedirectException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            // Generate an error response.
            $resultData['errorCode'] = '0000';
            $resultData['errorMessage'] = $ex->getMessage();
        }

        return $resultData;
    }

    /**
     * Process the CreatePayement web service response.
     *
     * @param Order $order the current order
     * @param array $response the CreatePayement response
     * @param array $resultData the result data to be enriched by response data
     * @return array the result data
     * @throws \Exception
     */
    protected function processCreatePaymentResponse(Order $order, array $response, array $resultData)
    {
        if ($response['status'] === 'SUCCESS') {
            // This is the Payment response (see https://payzen.io/fr-FR/rest/V4.0/api/playground.html?ws=Payment)
            $answer = $response["answer"];

            if (isset($answer["formToken"])) {
                // If we have a form token, we have to show the payment form.
                // Pass the form token and the order ID to the javascript client
                $resultData['success'] = true;
                $resultData['form_token'] = $answer["formToken"];
                $resultData['public_key'] = $this->getPublicKey();
            } else {
                // No form token means a one click payment. A this point, we will redirect the customer either to
                // the order success or order failure page. Let's decide now !
                $errorMessage = false;

                if ($this->oneClickEnabled) {
                    // Check if the order is paid or unpaid, and update order accordingly.
                    $paymentStatus = $this->processPaymentResponse($answer);

                    if ($paymentStatus === self::PAYMENT_STATUS_NOT_PAID) {
                        $errorMessage = Translator::getInstance()->trans(
                            "Sorry, your one click payment failed.",
                            [],
                            PayzenEmbedded::DOMAIN_NAME
                        );
                    } elseif ($paymentStatus === self::PAYMENT_STATUS_IN_PROGRESS) {
                        // The cart was processed, let's clear it.
                        $this->dispatcher->dispatch(
                            TheliaEvents::ORDER_CART_CLEAR,
                            new OrderEvent($order)
                        );
                    }
                } else {
                    // Should not happen. Theorically :)
                    $errorMessage = Translator::getInstance()->trans(
                        "Sorry, the one click payment option is disabled.",
                        [],
                        PayzenEmbedded::DOMAIN_NAME
                    );
                }

                // Redirect the customer to the succes or failure page
                if ($errorMessage) {
                    $redirectUrl = URL::getInstance()->absoluteUrl("/payzen-embedded/alias-failure/" . $order->getId() . '/' . $errorMessage);
                } else {
                    $redirectUrl = URL::getInstance()->absoluteUrl("/payzen-embedded/alias-success/" . $order->getId());
                }

                throw new RedirectException($redirectUrl);
            }
        } else {
            // We can't display the payment form :(
            $error = $response['answer'];

            // Pass the error details and the order ID to the payment page.
            $resultData['errorCode'] = $error['errorCode'];
            $resultData['errorMessage'] = $error['errorMessage'];
            $resultData['detailedErrorCode'] = $error['detailedErrorCode'];
            $resultData['detailedErrorMessage'] = $error['detailedErrorMessage'];

            // Log the problem
            Tlog::getInstance()->error(
                "PayZen CreatePayment failed, payment form could not be displayed. Error details : "
                . 'errorCode:' . $error['errorCode']
                . ', errorMessage:' . $error['errorMessage']
                . ', detailedErrorCode:' . $error['detailedErrorCode']
                . ', detailedErrorMessage:' . $error['detailedErrorMessage']
            );
        }

        return $resultData;
    }
}
