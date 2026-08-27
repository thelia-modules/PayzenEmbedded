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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Translation\Translator;
use Thelia\Core\Install\Database;
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

    /** Payment form types, see the form_type configuration variable */
    const FORM_TYPE_CARD = 'CARD';
    const FORM_TYPE_SMART_FORM = 'SMART_FORM';

    public function pay(Order $order): ?Response
    {
        // Use the embedded javascript client
        $lyraClient = new LyraJavascriptClientManagementWrapper($this->getDispatcher());

        $resultData = $lyraClient->payOrder($order);

        $popupMode = PayzenEmbedded::getConfigValue('popup_mode', false);

        if ($popupMode) {
            // Pass the $resultData to the order-invoice script
            return new JsonResponse($resultData);
        } else {
            /** @var ParserResolver $parserResolver */
            $parserResolver = $this->getContainer()->get('thelia.parser.resolver');

            $templateHelper = $this->getContainer()->get('thelia.template_helper');
            $activeTemplate = $templateHelper->getActiveFrontTemplate();

            $parser = $parserResolver->getParser($activeTemplate->getAbsolutePath(), null);
            $parser->setTemplateDefinition($activeTemplate, true);

            // Display the payment page which includes the javascript form.
            $renderedTemplate = $parser->render(
                "payzen-embedded/embedded-payment-page.html.twig",
                array_merge(
                    [
                        "order_id" => $order->getId(),
                        "cart_count" => $this->getRequest()->getSession()->getSessionCart($this->getDispatcher())->getCartItems()->count(),
                        "smart_form" => self::isSmartFormEnabled(),
                        "card_form_expanded" => (bool) self::getConfigValue('smart_form_card_form_expanded', true),
                        "static_base_url" => self::getStaticResourcesBaseUrl(),
                    ],
                    $resultData
                )
            );

            return new Response($renderedTemplate);
        }
    }

    /**
     * The SmartForm displays the wallets activated on the shop contract, such as Apple Pay or
     * Google Pay, which the card form never shows.
     */
    public static function isSmartFormEnabled(): bool
    {
        return self::FORM_TYPE_SMART_FORM === self::getConfigValue('form_type', self::FORM_TYPE_CARD);
    }

    /**
     * The payment methods the shop contract offers, as last read from the platform. Refreshed when
     * the module configuration page is displayed, see BackHookManager::onModuleConfigure().
     *
     * @return string[]
     */
    public static function getAvailablePaymentMethods(): array
    {
        return self::parsePaymentMethodList(self::getConfigValue('available_payment_methods'));
    }

    /**
     * The payment methods the shop keeps out of the payment form, for instance while one of them is
     * out of service.
     *
     * @return string[]
     */
    public static function getExcludedPaymentMethods(): array
    {
        return self::parsePaymentMethodList(self::getConfigValue('excluded_payment_methods'));
    }

    /**
     * Configuration variables hold payment methods as a list, the back-office joining checkbox
     * values with a semicolon.
     *
     * @return string[]
     */
    public static function parsePaymentMethodList($rawList): array
    {
        if (null === $rawList || '' === trim((string) $rawList)) {
            return [];
        }

        $paymentMethods = array_map(
            static fn ($paymentMethod) => strtoupper(trim($paymentMethod)),
            preg_split('/[;,]/', (string) $rawList) ?: []
        );

        return array_values(array_filter($paymentMethods));
    }

    /**
     * Base URL of the Krypton client resources. Derived from the web service end point, so that a
     * shop running on another Lyra platform, such as SystemPay or Lyra Collect, loads the client
     * from its own platform.
     */
    public static function getStaticResourcesBaseUrl(): string
    {
        $endPoint = rtrim((string) self::getConfigValue('webservice_endpoint'), '/');

        if ('' === $endPoint) {
            $endPoint = 'https://api.payzen.eu';
        }

        return $endPoint . '/static/js/krypton-client/V4.0';
    }

    public function isValidPayment(): bool
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

    public function postActivation(?ConnectionInterface $con = null): void
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

    public function preActivation(?ConnectionInterface $con = null): bool
    {
        $database = new Database($con);
        $database->insertSql(null, [__DIR__ . '/Config/create.sql']);

        return true;
    }

    public function destroy(?ConnectionInterface $con = null, $deleteModuleData = false): void
    {
        if ($deleteModuleData) {
            $database = new Database($con);

            $database->insertSql(null, [__DIR__ . '/Config/destroy.sql']);
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__ . '/I18n/*'])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
