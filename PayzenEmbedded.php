<?php
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

namespace PayzenEmbedded;

use Lyra\Client;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Tools\URL;

class PayzenEmbedded extends AbstractPaymentModule
{
    /** @var string */
    const DOMAIN_NAME = 'payzenembedded';
    /**
     * The confirmation message identifier
     */
    const CONFIRMATION_MESSAGE_NAME = 'payzen_embedded_payment_confirmation';

    /**
     * @param Order $order
     * @return \Thelia\Core\HttpFoundation\Response
     * @throws \Lyra\Exceptions\LyraException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function pay(Order $order)
    {
        $currency = $order->getCurrency();

        $mode = self::getConfigValue('mode', false);

        if ('TEST' == $mode) {
            $varMode = 'test';
        } else {
            $varMode = 'production';
        }

        try {
            $lyraClient = new Client();

            $publicKey = self::getConfigValue('javascript_' . $varMode . '_key');

            // Inilialize PayZen client
            $lyraClient->setUsername(self::getConfigValue('site_id'));
            $lyraClient->setEndpoint(self::getConfigValue('webservice_endpoint'));

            // Test / Productiuon variable
            $lyraClient->setPassword(self::getConfigValue($varMode . '_password'));
            $lyraClient->setPublicKey($publicKey);
            $lyraClient->setSHA256Key(self::getConfigValue('signature_' . $varMode . '_key'));

            $customer = $order->getCustomer();

            $oneClickAllowed = self::getConfigValue('allow_one_click_payments');

            if ($oneClickAllowed) {
                $formAction = 'ASK_REGISTER_PAY';
            } else {
                $formAction = 'PAYMENT';
            }

            // Request parameters (see https://payzen.io/en-EN/rest/V4.0/api/playground.html?ws=Charge/CreatePayment)
            $store = [
                "amount" => intval(strval($order->getTotalAmount() * 100)),
                'contrib' => 'Thelia version ' . ConfigQuery::read('thelia_version'),
                'currency' => strtoupper($currency->getCode()),
                'orderId' => $order->getId(),
                'formAction' => $formAction,

                'customer' => [
                    'email' => $customer->getEmail(),
                    'reference' => $customer->getRef()
                ],

                'strongAuthentication' => self::getConfigValue('strong_authentication', 'AUTO'),
                'ipnTargetUrl' => URL::getInstance()->absoluteUrl('/payzen-embedded/ipn-callback'),

                'transactionOptions' => [
                    'cardOptions' => [
                        'captureDelay' => self::getConfigValue('capture_delay', 0),
                        'manualValidation' => self::getConfigValue('validation_mode', null) ?: null,
                        'paymentSource' => self::getConfigValue('payment_source', null) ?: null
                    ]
                ],
            ];

            // Add 1-click payment token if we have one, and if it is allowed
            if ($oneClickAllowed && (null !== $tokenData = PayzenEmbeddedCustomerTokenQuery::create()->findOneByCustomerId($customer->getId()))) {
                $store['paymentMethodToken'] = $tokenData->getPaymentToken();
            }

            $response = $lyraClient->post("V4/Charge/CreatePayment", $store);

            if ($response['status'] !== 'SUCCESS') {
                $error = $response['answer'];

                // Pass the error details and the order ID to the javascript client
                $resultData = [
                    'success' => false,
                    'order_id' => $order->getId(),
                    'errorCode' => $error['errorCode'],
                    'errorMessage' => $error['errorMessage'],
                    'detailedErrorCode' => $error['detailedErrorCode'],
                    'detailedErrorMessage' => $error['detailedErrorMessage'],
                ];

                // Log the problem
                Tlog::getInstance()->error(
                    "PayZen CreatePayment failed, payement form could not be displayed. Error details : "
                    . 'errorCode:' . $error['errorCode']
                    . ', errorMessage:' . $error['errorMessage']
                    . ', detailedErrorCode:' . $error['detailedErrorCode']
                    . ', detailedErrorMessage:' . $error['detailedErrorMessage']
                );

            } else {
                // Pass the form token and the order ID to the javascript client
                $resultData = [
                    'success' => true,
                    'form_token' => $response["answer"]["formToken"],
                    'public_key' => $publicKey,
                    'order_id' => $order->getId()
                ];
            }
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

        /** @var ParserInterface $parser */
        $parser = $this->getContainer()->get("thelia.parser");

        $parser->setTemplateDefinition(
            $parser->getTemplateHelper()->getActiveFrontTemplate()
        );

        $renderedTemplate = $parser->render(
            "payzen-embedded/payment-page.html",
            array_merge(
                [
                    "order_id"          => $order->getId(),
                    "cart_count"        => $this->getRequest()->getSession()->getSessionCart($this->getDispatcher())->getCartItems()->count(),
                ],
                $resultData
            )
        );

        return new Response($renderedTemplate);
    }

    /**
     *
     * This method is call on Payment loop.
     *
     * If you return true, the payment method will de display
     * If you return false, the payment method will not be display
     *
     * @return boolean
     */
    public function isValidPayment()
    {
        $valid = false;

        // CHeck if the module has been configured.
        if (! empty(PayzenEmbedded::getConfigValue('site_id'))) {
            $mode = self::getConfigValue('mode', false);

            // If we're in test / restricted production mode, do not display module on the front office, except for allowed IP addresses.
            if ('TEST' === $mode || $mode === 'PRODUCTION_RESTRICTED') {
                $raw_ips = explode("\n", self::getConfigValue('allowed_ip_list', ''));

                $allowed_client_ips = [];

                foreach ($raw_ips as $ip) {
                    $allowed_client_ips[] = trim($ip);
                }

                $client_ip = $this->getRequest()->getClientIp();

                $valid = in_array($client_ip, $allowed_client_ips);
            } elseif ('PRODUCTION' == $mode) {
                $valid = true;
            }

            if ($valid) {
                // Check if total order amount is in the module's limits
                $valid = $this->checkMinMaxAmount();
            }
        }

        return $valid;
    }


    /**
     * Check if total order amount is in the module's limits
     *
     * @return bool true if the current order total is within the min and max limits
     */
    protected function checkMinMaxAmount()
    {
        // Check if total order amount is between the module's limits
        $order_total = $this->getCurrentOrderTotalAmount();

        $min_amount = self::getConfigValue('minimum_amount', 0);
        $max_amount = self::getConfigValue('maximum_amount', 0);

        return $order_total > 0 && ($min_amount <= 0 || $order_total >= $min_amount) && ($max_amount <= 0 || $order_total <= $max_amount);
    }

    /**
     * @param ConnectionInterface|null $con
     * @throws \Exception
     */
    public function postActivation(ConnectionInterface $con = null)
    {
        $languages = LangQuery::create()->find();

        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {
            $message = new Message();
            $message
                ->setName(self::CONFIRMATION_MESSAGE_NAME)
                ->setHtmlLayoutFileName('')
                ->setHtmlTemplateFileName(self::CONFIRMATION_MESSAGE_NAME.'.html')
                ->setTextLayoutFileName('')
                ->setTextTemplateFileName(self::CONFIRMATION_MESSAGE_NAME.'.txt')
            ;

            foreach ($languages as $language) {
                /** @var Lang $language */
                $locale = $language->getLocale();

                $message->setLocale($locale);

                $message->setTitle(
                    Translator::getInstance()->trans('Order payment confirmation', [], $locale)
                );

                $message->setSubject(
                    Translator::getInstance()->trans('Order {$order_ref} payment confirmation', [], $locale)
                );
            }

            $message->save();
        }

        /* Deploy the module's image */
        $module = $this->getModuleModel();

        if (ModuleImageQuery::create()->filterByModule($module)->count() == 0) {
            $this->deployImageFolder($module, sprintf('%s/images', __DIR__), $con);
        }
    }

    public function preActivation(ConnectionInterface $con = null)
    {
        try {
            PayzenEmbeddedCustomerTokenQuery::create()->findOne();

            // Table is already initialized.
        } catch (\Exception $ex) {
            // No table -> create it
            $database = new Database($con);
            $database->insertSql(null, array(__DIR__ . '/Config/thelia.sql'));
        }

        return true;
    }

    public function destroy(ConnectionInterface $con = null, $deleteModuleData = false)
    {
        if ($deleteModuleData) {
            $database = new Database($con);

            $database->insertSql(null, array(__DIR__ . '/Config/destroy.sql'));
        }
    }
}
