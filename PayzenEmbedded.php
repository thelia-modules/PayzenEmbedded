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

use PayzenEmbedded\Command\CancelLegacyTokensCommand;
use PayzenEmbedded\Service\CardFormProvider;
use PayzenEmbedded\LyraClient\LyraTokenGateway;
use PayzenEmbedded\Service\LegacyTokenCanceller;
use PayzenEmbedded\Service\StoredMethodContract;
use PayzenEmbedded\Service\StoredMethodRevoker;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Payment\Exception\StoredMethodRefusedException;
use Thelia\Log\Tlog;
use Thelia\Core\Install\Database;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Module\BalanceChargingPaymentModuleInterface;
use Thelia\Module\BaseModule;
use Thelia\Module\DeferredStoredMethodForgettingPaymentModuleInterface;
use Thelia\Module\PaymentModuleWithStoredMethodsInterface;

/**
 * Charges the balance of the order when instant means paid a part of it (BalanceChargingPaymentModuleInterface:
 * every amount sent to the platform is getAmountToCharge(), never the total of the order), and keeps the cards
 * PayZen registered among the payment methods of the shop.
 */
class PayzenEmbedded extends AbstractPaymentModule implements BalanceChargingPaymentModuleInterface, PaymentModuleWithStoredMethodsInterface, DeferredStoredMethodForgettingPaymentModuleInterface
{
    /** @var string */
    const DOMAIN_NAME = 'payzenembedded';

    /** The confirmation message identifier */
    const CONFIRMATION_MESSAGE_NAME = 'payzen_embedded_payment_confirmation';

    /** The transaction update event identifier */
    const TRANSACTION_UPDATE_EVENT = "payzenembedded.transaction_update_event";

    /** The transaction refund event identifier, see Event\TransactionRefundEvent */
    const TRANSACTION_REFUND_EVENT = "payzenembedded.transaction_refund_event";

    /** Payment form types, see the form_type configuration variable */
    const FORM_TYPE_CARD = 'CARD';
    const FORM_TYPE_SMART_FORM = 'SMART_FORM';

    /**
     * Whether the Thelia this module runs on gives a module a configuration per sale unit. A release
     * that does not know them, 3.2 for one, has a single configuration, and this module then works
     * as it always did.
     */
    public static function supportsSaleUnits(): bool
    {
        return method_exists(BaseModule::class, 'getConfigValueForSaleUnit')
            && method_exists(Order::class, 'getSaleUnitId')
            && class_exists(SaleUnitConfigScope::class);
    }

    /**
     * Run what is about an order with the configuration of the sale unit the order was placed on,
     * the national one for a national order: the contract, the keys and the settings the payment
     * reads are those of its unit, whatever sale unit the request resolved, or without any request.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function runForOrder(Order $order, callable $callback): mixed
    {
        if (!self::supportsSaleUnits()) {
            return $callback();
        }

        return SaleUnitConfigScope::runFor($order->getSaleUnitId(), $callback);
    }

    /**
     * The same for a sale unit by its id, null being the national configuration.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function runForSaleUnit(?int $saleUnitId, callable $callback): mixed
    {
        if (!self::supportsSaleUnits()) {
            return $callback();
        }

        return SaleUnitConfigScope::runFor($saleUnitId, $callback);
    }

    /**
     * What this module charges for the order, as the platform counts it once converted: the balance
     * of its part when instant means paid the rest, the total of the order otherwise. A static entry
     * for the clients, which are not the module: the module reads its answer from the same event.
     */
    public static function amountToCharge(Order $order, EventDispatcherInterface $dispatcher): string
    {
        $module = new self();
        $module->setDispatcher($dispatcher);

        return $module->getAmountToCharge($order);
    }

    /**
     * The customer may keep a card as long as the shop allows 1-click payments. A shop showing
     * the SmartForm never asks for a registration (see PaymentFormAction), and the cards already
     * kept go on paying.
     */
    public function supportsStoredMethods(): bool
    {
        return (bool) self::getConfigValue('allow_one_click_payments');
    }

    public function pay(Order $order): ?Response
    {
        // The payment is made on the contract of the sale unit the order was placed on.
        return self::runForOrder($order, fn (): ?Response => $this->payOnContractOf($order, null));
    }

    /**
     * The payment page opens on the card the customer chose: a form token asked with its token, which
     * the customer confirms in the form (the 3-D Secure challenge included), so the answer is a page,
     * never a payment made without the customer.
     */
    public function payWithStoredMethod(Order $order, CustomerPaymentMethod $method): ?Response
    {
        // The core checked it before asking: a card of another customer, or kept through another
        // module, never pays this order, whoever asks.
        if ((int) $method->getCustomerId() !== (int) $order->getCustomerId() || (int) $method->getPaymentModuleId() !== self::getModuleId()) {
            throw new \InvalidArgumentException('This stored payment method cannot pay this order.');
        }

        // The token is good on the PayZen contract it was registered on, the one of a sale unit: another sale unit's
        // order would be sent to a contract that does not know it. The checkout does not offer such a card (see
        // StoredMethodsOfTheSaleUnitListener), this is the refusal for one that still reaches the payment.
        StoredMethodContract::refuseUnlessUsableForOrder($method, $order);

        return self::runForOrder($order, fn (): ?Response => $this->payOnContractOf($order, $method));
    }

    /**
     * @throws \Thelia\Domain\Payment\Exception\StoredMethodRefusedException when PayZen did not cancel the card
     */
    public function forgetStoredMethod(CustomerPaymentMethod $method): void
    {
        $this->revokeWith($method, static fn (StoredMethodRevoker $revoker) => $revoker->revoke($method));
    }

    /**
     * The anonymization and the deletion of the customer drop the card before PayZen is asked, and its link to the
     * contract of its sale unit goes with it (foreign key on delete cascade): the contract is read now, while the card
     * is still there, so that the card is cancelled on it all the same.
     */
    public function prepareToForgetStoredMethod(CustomerPaymentMethod $method): \Closure
    {
        $saleUnitId = StoredMethodContract::saleUnitToCancelOn($method);

        return fn () => $this->revokeWith($method, static fn (StoredMethodRevoker $revoker) => $revoker->revokeOn($method, $saleUnitId));
    }

    /**
     * @param \Closure(StoredMethodRevoker): void $revoke
     *
     * @throws StoredMethodRefusedException when PayZen did not cancel the card
     */
    private function revokeWith(CustomerPaymentMethod $method, \Closure $revoke): void
    {
        try {
            // The core builds the instance of a module that is not active with its own container, which does
            // not hold the services of the module: the revoker is then built by hand, on the same gateway.
            $revoker = $this->hasContainer() && $this->getContainer()->has(StoredMethodRevoker::class)
                ? $this->getContainer()->get(StoredMethodRevoker::class)
                : new StoredMethodRevoker(new LyraTokenGateway());

            $revoke($revoker);
        } catch (StoredMethodRefusedException $refused) {
            throw $refused;
        } catch (\Throwable $failure) {
            // The only exception the core answers is a refusal: whatever else goes wrong, the card stays kept
            // and the customer is told so, never a server error. The class only, a message may quote the token.
            Tlog::getInstance()->addError(sprintf('PayZen stored payment method %d not forgotten: %s.', (int) $method->getId(), $failure::class));

            throw new StoredMethodRefusedException('error');
        }
    }

    private function payOnContractOf(Order $order, ?CustomerPaymentMethod $storedMethod): ?Response
    {
        // Through the provider, not the client: a theme showing the card fields in its own
        // checkout step asks the provider for the same order in the same request, and the answer
        // is held there, so the platform is called once whichever of the two asks first.
        $payload = $this->getContainer()->get(CardFormProvider::class)->forOrder($order, $storedMethod);

        $resultData = [
            'success' => $payload->available,
            'order_id' => $payload->orderId,
            'form_token' => $payload->formToken,
            'public_key' => $payload->publicKey,
            'errorCode' => $payload->errorCode,
            'errorMessage' => $payload->errorMessage,
            'detailedErrorCode' => $payload->detailedErrorCode,
            'detailedErrorMessage' => $payload->detailedErrorMessage,
        ];

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
     * What names this shop on the platform: two shops on one contract, or two environments in the
     * TEST space, produce the same order references. Sent with every payment as metadata, and
     * required back on what the platform lists or notifies.
     */
    public static function shopMarker(): string
    {
        return md5((string) ConfigQuery::read('url_site', '') . '#' . (string) self::getConfigValue('site_id', ''));
    }

    /** TEST or PRODUCTION, the space the platform files this shop's transactions in. */
    public static function platformMode(): string
    {
        return 'TEST' === self::getConfigValue('mode', 'TEST') ? 'TEST' : 'PRODUCTION';
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

    /**
     * PayZen asks for a payment token for each attempt, and its notification finds the order back by
     * the order's own reference, so the same order can be presented to it as many times as the
     * shopper tries. Nothing on the order is overwritten that a late notification of an earlier
     * attempt would need: each transaction keeps its own row in the module's history, and a
     * notification only moves the order when its transaction outranks the one the order stands on.
     */
    public function supportsPaymentRetry(): bool
    {
        return true;
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

    /**
     * Execute sql files in Config/update/ folder named with module version (ex: 1.0.1.sql).
     */
    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $updateDir = __DIR__ . DS . 'Config' . DS . 'update';

        if (!is_dir($updateDir)) {
            return;
        }

        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in($updateDir);

        $database = new Database($con);

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    /**
     * The cards of the old table that the update did not move (a guest, an anonymized account) are still held by
     * PayZen. They are NOT cancelled here: each call to the platform may wait for its timeout, and this runs inside
     * the update of the module from the back office, between the deactivation and the reactivation of the module. The
     * update counts them and says how to have them cancelled (the command CancelLegacyTokensCommand). Never fails
     * the update that is done.
     */
    public function postUpdate(string $currentVersion, string $newVersion): void
    {
        try {
            $pending = LegacyTokenCanceller::countPending();

            if ($pending > 0) {
                Tlog::getInstance()->addWarning(sprintf(
                    'PayZen: %d card(s) of guests or anonymized accounts are still active at PayZen and wait to be cancelled: run "php Thelia payzen-embedded:legacy-tokens:cancel" (--limit %d by default), until no row is left after the last one tried, or only the cards PayZen refused remain.',
                    $pending,
                    CancelLegacyTokensCommand::DEFAULT_LIMIT
                ));
            }
        } catch (\Throwable $failure) {
            // The class only: a message may quote a token.
            Tlog::getInstance()->addError(sprintf('PayZen cards of the old table not counted: %s.', $failure::class));
        }
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
            ->exclude([__DIR__ . '/I18n/*', __DIR__ . '/tests/*'])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
