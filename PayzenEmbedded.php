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

use PayzenEmbedded\LyraClient\LyraJavascriptClientManagementWrapper;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;

class PayzenEmbedded extends AbstractPaymentModule
{
    /** @var string */
    const DOMAIN_NAME = 'payzenembedded';

    /** The confirmation message identifier */
    const CONFIRMATION_MESSAGE_NAME = 'payzen_embedded_payment_confirmation';

    /** The transaction update event identifier */
    const TRANSACTION_UPDATE_EVENT = "payzenembedded.transaction_update_event";

    /**
     * Payment method type codes, used by the Lyra "paymentMethods" API parameter to restrict
     * the methods displayed in the smartForm.
     *
     * IMPORTANT: the exact codes available depend on your Lyra contract and on the methods enabled
     * in your Lyra Back Office. Validate them against your Back Office / Lyra support before enabling
     * a restriction in production (see Readme.md). Contract-specific methods (Oney, Alma, Franfinance,
     * local schemes...) can be added through the "additional payment methods" configuration field.
     */
    const PAYMENT_METHOD_CARDS = 'CARDS';
    const PAYMENT_METHOD_APPLE_PAY = 'APPLE_PAY';
    const PAYMENT_METHOD_GOOGLE_PAY = 'GOOGLE_PAY';
    const PAYMENT_METHOD_PAYPAL = 'PAYPAL';
    const PAYMENT_METHOD_CONECS = 'CONECS';

    /**
     * Build the list of payment methods the smartForm should be restricted to, from the module
     * configuration. Returns an empty array when no restriction is configured, in which case every
     * method enabled in the Lyra Back Office is displayed in the smartForm.
     *
     * @return string[]
     */
    public static function getRestrictedPaymentMethods(): array
    {
        if (! (bool) self::getConfigValue('restrict_payment_methods', false)) {
            return [];
        }

        $methods = array_filter(explode(';', (string) self::getConfigValue('allowed_payment_methods', '')));

        foreach (explode("\n", (string) self::getConfigValue('additional_payment_methods', '')) as $additional) {
            if ('' !== $additional = trim($additional)) {
                $methods[] = $additional;
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * Process a payment using the PayZen javascript client
     *
     * @param Order $order
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function pay(Order $order)
    {
        // Use the embedded javascript client
        $lyraClient = new LyraJavascriptClientManagementWrapper($this->getDispatcher());

        $resultData = $lyraClient->payOrder($order);

        $popupMode = PayzenEmbedded::getConfigValue('popup_mode', false);

        if ($popupMode) {
            // Pass the $resultData to the order-invoice script
            return new JsonResponse($resultData);
        } else {
            /** @var ParserInterface $parser */
            $parser = $this->getContainer()->get("thelia.parser");

            $parser->setTemplateDefinition(
                $parser->getTemplateHelper()->getActiveFrontTemplate(),
                true
            );

            // Display the payment page which includes the javascript form.
            $renderedTemplate = $parser->render(
                "payzen-embedded/embedded-payment-page.html",
                array_merge(
                    [
                        "order_id" => $order->getId(),
                        "cart_count" => $this->getRequest()->getSession()->getSessionCart($this->getDispatcher())->getCartItems()->count(),
                    ],
                    $resultData
                )
            );

            return new Response($renderedTemplate);
        }
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
    public function postActivation(ConnectionInterface $con = null): void
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
        $database = new Database($con);
        $database->insertSql(null, array(__DIR__ . '/Config/create.sql'));

        return true;
    }

    public function destroy(ConnectionInterface $con = null, $deleteModuleData = false): void
    {
        if ($deleteModuleData) {
            $database = new Database($con);

            $database->insertSql(null, array(__DIR__ . '/Config/destroy.sql'));
        }
    }

    public function update($currentVersion, $newVersion, ConnectionInterface $con = null): void
    {
        if (null === $con) {
            return;
        }

        // 2.6.0: keep track of the payment method type (card, wallet, PayPal...) used for each transaction.
        $columnExists = $con
            ->query("SHOW COLUMNS FROM `payzen_embedded_transaction_history` LIKE 'payment_method_type'")
            ->fetch();

        if (false === $columnExists) {
            $con->exec(
                "ALTER TABLE `payzen_embedded_transaction_history` ADD COLUMN `payment_method_type` VARCHAR(64) NULL AFTER `detailedStatus`"
            );
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()). "/I18n/*"])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
