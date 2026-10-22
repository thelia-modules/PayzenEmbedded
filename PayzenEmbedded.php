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

use PayzenEmbedded\LyraClient\TransactionHistoryReader;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\Service\CardFormProvider;
use PayzenEmbedded\Service\DeferredCapture;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope;
use Thelia\Core\Translation\Translator;
use Thelia\Core\Install\Database;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Domain\Payment\DTO\PaymentOperationResult;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransaction;
use Thelia\Model\OrderPaymentTransactionQuery;
use Thelia\Domain\Payment\Exception\PaymentRefusedException;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Module\BaseModule;
use Thelia\Module\PaymentModuleCancellingOnReleaseInterface;
use Thelia\Module\PaymentModuleCapturingOnceInterface;

class PayzenEmbedded extends AbstractPaymentModule implements PaymentModuleCapturingOnceInterface, PaymentModuleCancellingOnReleaseInterface
{
    /** @var string */
    const DOMAIN_NAME = 'payzenembedded';

    /** The confirmation message identifier */
    const CONFIRMATION_MESSAGE_NAME = 'payzen_embedded_payment_confirmation';

    /** The transaction update event identifier */
    const TRANSACTION_UPDATE_EVENT = "payzenembedded.transaction_update_event";

    /** The transaction refund event identifier, see Event\TransactionRefundEvent */
    const TRANSACTION_REFUND_EVENT = "payzenembedded.transaction_refund_event";

    /** A transaction the platform reported was recorded, see Event\TransactionReportedEvent */
    const TRANSACTION_REPORTED_EVENT = "payzenembedded.transaction_reported_event";

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
     * Whether the shop authorizes first and captures later: the validation mode is manual, so the
     * platform holds the amount until the shop validates it, from Thelia's capture dialog.
     */
    public static function authorizesFirst(): bool
    {
        return 'YES' === self::getConfigValue('validation_mode', '');
    }

    /**
     * Whether giving the payment of the order back would cancel its authorization: authorizing
     * first, a payment the bank has not taken yet is cancelled in full. That releases what
     * Thelia's journal holds, a decision Thelia leaves to its capture right.
     */
    public static function givingBackReleasesTheAuthorization(Order $order): bool
    {
        return self::authorizesFirstFor($order) && (new TransactionHistoryReader())->ledgerOf($order)->isCancellable();
    }

    /**
     * Whether the module authorized the order first, its capture being Thelia's: read on the
     * order's journal, which keeps what was done whatever the configuration became since, then
     * on the transaction the platform reports, when it waits for its validation. An order with
     * neither reads the configuration of its sale unit.
     */
    public static function authorizesFirstFor(Order $order, ?TransactionOutcome $incoming = null): bool
    {
        // An authorization line, refused ones included: a second attempt follows a first refused one.
        if (OrderPaymentTransactionQuery::create()->filterByOrderId((int) $order->getId())->filterByType(PaymentTransactionType::AUTHORIZATION->value)->exists()) {
            return true;
        }

        // A transaction waiting for its validation was created authorizing first, whatever the
        // configuration became before its first notification.
        if (null !== $incoming && $incoming->waitsForValidation()) {
            return true;
        }

        if (OrderPaymentTransactionQuery::create()->filterByOrderId((int) $order->getId())->exists()) {
            return false;
        }

        // A debit already authorized without waiting for its validation, or captured, was taken
        // at once, whatever the configuration became before its first notification.
        if (null !== $incoming && !$incoming->isCredit() && ($incoming->isAuthorized() || $incoming->isCaptured())) {
            return false;
        }

        return self::runForOrder($order, static fn (): bool => self::authorizesFirst());
    }

    /** Whether Thelia's payment journal holds a capture of the order, waiting for its answer or taken. */
    public static function journalHoldsACapture(Order $order): bool
    {
        return OrderPaymentTransactionQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByType(PaymentTransactionType::CAPTURE->value)
            ->filterByState([PaymentTransactionState::PENDING->value, PaymentTransactionState::SUCCEEDED->value], Criteria::IN)
            ->exists();
    }

    /** Whether Thelia's payment journal holds the capture of the order, taken. */
    public static function journalHoldsTheCapture(Order $order): bool
    {
        return OrderPaymentTransactionQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByType(PaymentTransactionType::CAPTURE->value)
            ->filterByState(PaymentTransactionState::SUCCEEDED->value)
            ->exists();
    }

    /** PayZen takes a single capture per authorization, and lets the rest go with it. */
    public function capturesOnce(): bool
    {
        return true;
    }

    /** A transaction cancelled or lapsed at PayZen cancels its order, from the notification. */
    public function cancelsOrderOnRelease(): bool
    {
        return true;
    }

    public function supportsDeferredCapture(): bool
    {
        return self::authorizesFirst();
    }

    public function capture(Order $order, float $amount, OrderPaymentTransaction $transaction): PaymentOperationResult
    {
        // PayZen takes a single capture per authorization: validating it again would set the
        // amount of the transaction anew, lowering what the first capture took.
        if (self::journalHoldsACaptureBesides($order, $transaction)) {
            throw new PaymentRefusedException('PayZen takes a single capture per authorization, and one was already asked for this order: the rest of the authorization is released with it.');
        }

        return $this->getContainer()->get(DeferredCapture::class)->capture($order, $amount);
    }

    public function voidAuthorization(Order $order, OrderPaymentTransaction $transaction): PaymentOperationResult
    {
        // Once a capture was asked, the platform lets the rest of the authorization go with it:
        // cancelling the transaction would cancel that capture too.
        if (self::journalHoldsACaptureBesides($order, $transaction)) {
            return PaymentOperationResult::succeeded((string) $order->getTransactionRef());
        }

        return $this->getContainer()->get(DeferredCapture::class)->release($order, $transaction);
    }

    /**
     * Whether the journal holds a capture of the order, waiting for its answer or taken, other
     * than the line being written.
     */
    private static function journalHoldsACaptureBesides(Order $order, OrderPaymentTransaction $transaction): bool
    {
        return OrderPaymentTransactionQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByType(PaymentTransactionType::CAPTURE->value)
            ->filterByState([PaymentTransactionState::PENDING->value, PaymentTransactionState::SUCCEEDED->value], Criteria::IN)
            ->filterById((int) $transaction->getId(), Criteria::NOT_EQUAL)
            ->exists();
    }

    public function pay(Order $order): ?Response
    {
        // The payment is made on the contract of the sale unit the order was placed on.
        return self::runForOrder($order, fn (): ?Response => $this->payOnContractOf($order));
    }

    private function payOnContractOf(Order $order): ?Response
    {
        // Through the provider, not the client: a theme showing the card fields in its own
        // checkout step asks the provider for the same order in the same request, and the answer
        // is held there, so the platform is called once whichever of the two asks first.
        $payload = $this->getContainer()->get(CardFormProvider::class)->forOrder($order);

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
