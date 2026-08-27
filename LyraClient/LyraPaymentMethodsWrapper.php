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

use Thelia\Log\Tlog;
use Thelia\Model\CurrencyQuery;

/**
 * Reads the payment methods the shop contract offers, so that the back-office can list them.
 */
class LyraPaymentMethodsWrapper extends LyraClientWrapper
{
    /** Order reference of the payment request used to read the payment methods */
    const PROBE_ORDER_ID = 'PAYMENT-METHODS-PROBE';

    /**
     * The platform offers no service listing the contract content. The list is therefore read from
     * the form token of a payment request which is never displayed, and never becomes a transaction.
     *
     * @return string[] the payment method identifiers, empty if the platform could not be reached
     */
    public function getAvailablePaymentMethods()
    {
        $currency = CurrencyQuery::create()->findOneByByDefault(true);

        try {
            $response = $this->post(
                'V4/Charge/CreatePayment',
                [
                    'amount' => 100,
                    'currency' => strtoupper(null !== $currency ? $currency->getCode() : 'EUR'),
                    'orderId' => self::PROBE_ORDER_ID,
                    'formAction' => 'PAYMENT',
                ]
            );
        } catch (\Exception $ex) {
            Tlog::getInstance()->addError('Failed to read the PayZen payment methods: ' . $ex->getMessage());

            return [];
        }

        if (! isset($response['status'], $response['answer']['formToken']) || 'SUCCESS' !== $response['status']) {
            Tlog::getInstance()->addError(
                'Failed to read the PayZen payment methods: ' . json_encode($response['answer'] ?? $response)
            );

            return [];
        }

        return self::extractPaymentMethods($response['answer']['formToken']);
    }

    /**
     * A form token is a signature followed by the base64 encoded description of the payment form,
     * whose smartForm key holds one entry per available payment method.
     *
     * @return string[]
     */
    protected static function extractPaymentMethods($formToken)
    {
        $jsonStart = strpos($formToken, 'eyJ');

        if (false === $jsonStart) {
            return [];
        }

        $decoded = base64_decode(strtr(substr($formToken, $jsonStart), '-_', '+/'));

        if (! is_string($decoded)) {
            return [];
        }

        // The token is not base64 aligned, so the decoded string ends with a few stray bytes: try
        // the last closing braces until one of them closes a valid JSON payload.
        for ($attempt = 0, $end = strlen($decoded); $attempt < 5; ++$attempt) {
            $end = strrpos(substr($decoded, 0, $end), '}');

            if (false === $end) {
                return [];
            }

            $paymentForm = json_decode(substr($decoded, 0, $end + 1), true);

            if (is_array($paymentForm)) {
                return isset($paymentForm['smartForm']) && is_array($paymentForm['smartForm'])
                    ? array_keys($paymentForm['smartForm'])
                    : [];
            }
        }

        return [];
    }
}
