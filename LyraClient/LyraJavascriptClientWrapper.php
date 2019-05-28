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
use Symfony\Component\Routing\Router;
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

class LyraJavascriptClientWrapper extends LyraCreatePaymentWrapper
{
    /**
     * Request payment of order.
     *
     * @param Order $order
     * @return array an array containing payment result.
     */
    public function payOrder(Order $order)
    {
        try {
            // Send the payment request
            $response = $this->sendCreatePayementRequest($order);

            // Process the payment response.
            $resultData = $this->processCreatePaymentResponse($order, $response);
        } catch (RedirectException $ex) {
            throw $ex;
        } catch (\Exception $ex) {
            // Generate an error response.
            $resultData = [
                'success' => false,
                'order_id' => $order->getId(),
                'errorCode' => '0000',
                'errorMessage' => $ex->getMessage(),
                'detailedErrorCode' => '',
                'detailedErrorMessage' => '',
            ];
        }

        return $resultData;
    }

    /**
     * Process the CreatePayement web service response.
     *
     * @param Order $order the current order
     * @param array $response the CreatePayement response
     * @return array the result data
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function processCreatePaymentResponse(Order $order, array $response)
    {
        $resultData = [
            'success' => false,
            'order_id' => $order->getId(),
            'errorCode' => '',
            'errorMessage' => '',
            'detailedErrorCode' => '',
            'detailedErrorMessage' => '',
        ];

        if ($response['status'] === 'SUCCESS') {
            // This is the Paymenet response (see https://payzen.io/fr-FR/rest/V4.0/api/playground.html?ws=Payment)
            $answer = $response["answer"];

            if (isset($answer["formToken"])) {
                // If we have a form token, we have to show the payement form.
                // Pass the form token and the order ID to the javascript client
                $resultData['success'] = true;
                $resultData['form_token'] = $answer["formToken"];
                $resultData['public_key'] = $this->getPublicKey();
            } else {
                // No form token means a one click payment. A this point, we will redirect the customer either to
                // the order success or order failure page. Let's decide now !

                $errorMessage = false;

                if ($this->oneClickEnabled) {
                    // Check if the order is paid or unpaid.
                    $paymentStatus = $this->processPaymentResponse($answer);

                    if ($paymentStatus === self::PAYEMENT_STATUS_NOT_PAID) {
                        $errorMessage = Translator::getInstance()->trans(
                            "Sorry, your one click payement failed.",
                            [],
                            PayzenEmbedded::DOMAIN_NAME
                        );
                    } elseif ($paymentStatus === self::PAYEMENT_STATUS_IN_PROGRESS) {
                        // Tell the customer the payment is in progress.
                        throw new RedirectException(
                            URL::getInstance()->absoluteUrl("/payzen-embedded/alias-in-progress/" . $order->getId())
                        );
                    }
                } else {
                    // Should not happen. Theorically :)
                    $errorMessage = Translator::getInstance()->trans(
                        "Sorry, the one click payement option is disabled.",
                        [],
                        PayzenEmbedded::DOMAIN_NAME
                    );
                }

                if ($errorMessage) {
                    $redirectUrl = URL::getInstance()->absoluteUrl("/payzen-embedded/alias-failure/" . $order->getId() . '/' . $errorMessage);
                } else {
                    $redirectUrl = URL::getInstance()->absoluteUrl("/payzen-embedded/alias-success/" . $order->getId());
                }

                throw new RedirectException($redirectUrl);
            }
        } else {
            // We can't display the payement form :(
            $error = $response['answer'];

            // Pass the error details and the order ID to the payment page.
            $resultData['errorCode'] = $error['errorCode'];
            $resultData['errorMessage'] = $error['errorMessage'];
            $resultData['detailedErrorCode'] = $error['detailedErrorCode'];
            $resultData['detailedErrorMessage'] = $error['detailedErrorMessage'];

            // Log the problem
            Tlog::getInstance()->error(
                "PayZen CreatePayment failed, payement form could not be displayed. Error details : "
                . 'errorCode:' . $error['errorCode']
                . ', errorMessage:' . $error['errorMessage']
                . ', detailedErrorCode:' . $error['detailedErrorCode']
                . ', detailedErrorMessage:' . $error['detailedErrorMessage']
            );
        }

        return $resultData;
    }
}
