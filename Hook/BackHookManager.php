<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia 2 PayZen Embedded payment module                                      */
/*                                                                                   */
/*      Copyright (c) Lyra Networks                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*                                                                                   */
/*************************************************************************************/

/**
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 23/05/2019 17:02
 */
namespace PayzenEmbedded\Hook;

use PayzenEmbedded\Form\ConfigurationForm;
use PayzenEmbedded\LyraClient\LyraPaymentMethodsWrapper;
use PayzenEmbedded\Form\TransactionGetForm;
use PayzenEmbedded\Form\TransactionRefundForm;
use PayzenEmbedded\Form\TransactionUpdateForm;
use PayzenEmbedded\LyraClient\RefundAmount;
use PayzenEmbedded\LyraClient\TransactionHistoryReader;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use PayzenEmbedded\PayzenEmbedded;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\MessageQuery;
use Thelia\Model\OrderQuery;
use Thelia\Tools\URL;

class BackHookManager extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly SecurityContext $securityContext,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public function onModuleConfigure(HookRenderEvent $event): void
    {
        $this->refreshAvailablePaymentMethods();

        $form = $this->formFactory->createForm(ConfigurationForm::getName());

        $defaultCurrency = CurrencyQuery::create()->findOneByByDefault(true);

        $confirmationMessage = MessageQuery::create()
            ->findOneByName(PayzenEmbedded::CONFIRMATION_MESSAGE_NAME);

        $availablePaymentMethods = PayzenEmbedded::getAvailablePaymentMethods();

        $event->add(
            $this->render('payzen-embedded/module-configuration.html.twig', [
                'form' => $form->createView()->getView(),
                'default_currency_symbol' => null !== $defaultCurrency ? $defaultCurrency->getSymbol() : '',
                'payment_confirmation_message_id' => null !== $confirmationMessage ? $confirmationMessage->getId() : null,
                'ipn_callback_url' => URL::getInstance()->absoluteUrl('/payzen-embedded/ipn-callback'),
                'is_configured' => !empty(PayzenEmbedded::getConfigValue('site_id')),
                'site_id' => PayzenEmbedded::getConfigValue('site_id'),
                'is_production' => 'PRODUCTION' === PayzenEmbedded::getConfigValue('mode'),
                'available_payment_methods' => $availablePaymentMethods,
                'payment_method_labels' => $this->getPaymentMethodLabels(),
                'smart_form_value' => PayzenEmbedded::FORM_TYPE_SMART_FORM,
            ])
        );
    }

    /**
     * @return array<string, string>
     */
    private function getPaymentMethodLabels(): array
    {
        return [
            'CARDS' => $this->trans('Credit cards', [], PayzenEmbedded::DOMAIN_NAME),
            'APPLE_PAY' => $this->trans('Apple Pay', [], PayzenEmbedded::DOMAIN_NAME),
            'GOOGLE_PAY' => $this->trans('Google Pay', [], PayzenEmbedded::DOMAIN_NAME),
            'PAYPAL' => $this->trans('PayPal', [], PayzenEmbedded::DOMAIN_NAME),
        ];
    }

    /**
     * Reads the payment methods offered by the shop contract, so that the configuration form can
     * list them. A shop which is not configured yet, or a platform out of reach, leaves the last
     * known list in place.
     */
    private function refreshAvailablePaymentMethods(): void
    {
        if (empty(PayzenEmbedded::getConfigValue('site_id'))) {
            return;
        }

        $paymentMethods = (new LyraPaymentMethodsWrapper())->getAvailablePaymentMethods();

        if ([] !== $paymentMethods) {
            PayzenEmbedded::setConfigValue('available_payment_methods', implode(';', $paymentMethods));
        }
    }

    public function onOrderEditBottom(HookRenderEvent $event): void
    {
        // The Smarty back-office hands order_id, the Twig one hands order for this hook only.
        $orderId = (int) ($event->hasArgument('order_id') ? $event->getArgument('order_id') : $event->getArgument('order'));

        $order = OrderQuery::create()->findPk($orderId);

        if (null === $order || PayzenEmbedded::getModuleId() !== $order->getPaymentModuleId()) {
            return;
        }

        $transactions = $this->getTransactionHistory(orderId: $orderId);

        // The transaction to update is the one the order stands on, not the last one listed: the
        // history also holds the attempts the shopper gave up on. It can be updated until captured.
        $reference = (string) $order->getTransactionRef();
        $finished = true;
        $lastTransactionAmount = '0';

        foreach ($transactions as $transaction) {
            if ('CREDIT' === $transaction['OPERATION_TYPE'] || ('' !== $reference && $transaction['TRANSACTION_REF'] !== $reference)) {
                continue;
            }

            $finished = 'UNPAID' === $transaction['STATUS'] || $transaction['IS_CAPTURED'];
            $lastTransactionAmount = $transaction['AMOUNT_FORMATTED'];
        }

        $ledger = (new TransactionHistoryReader())->ledgerOf($order);
        $currencyCode = strtoupper((string) $order->getCurrency()?->getCode());
        $canAct = $this->securityContext->isGranted(['ADMIN'], [AdminResources::MODULE, AdminResources::ORDER], ['PayzenEmbedded'], [AccessManager::UPDATE]);

        $getForm = $this->formFactory->createForm(TransactionGetForm::getName());
        $updateForm = $this->formFactory->createForm(TransactionUpdateForm::getName());
        $refundForm = $this->formFactory->createForm(TransactionRefundForm::getName());

        $event->add(
            $this->render('payzen-embedded/order-edit.html.twig', [
                'order_id' => $orderId,
                'transactions' => $transactions,
                'finished' => $finished || '' === $reference,
                'last_transaction_amount' => $lastTransactionAmount,
                'paid_amount' => RefundAmount::format($ledger->paidAmount, $currencyCode),
                'refunded_amount' => RefundAmount::format($ledger->refundedAmount, $currencyCode),
                'refunded_amount_minor' => $ledger->refundedAmount,
                'refundable_amount' => RefundAmount::format($ledger->refundableAmount(), $currencyCode),
                'is_cancellable' => $ledger->isCancellable(),
                'maximum_amount' => RefundAmount::format($ledger->maximumAmount(), $currencyCode),
                // An order that carries no transaction cannot be refunded nor updated: the history
                // may list its payment, but only the notification ties the order to it.
                'can_give_back' => '' !== $reference && $ledger->maximumAmount() > 0,
                'other_payments_left' => $ledger->otherPaymentsLeft > 0 ? RefundAmount::format($ledger->otherPaymentsLeft, $currencyCode) : null,
                'can_act' => $canAct,
                'currency_symbol' => $order->getCurrency()?->getSymbol() ?? '',
                'get_form' => $getForm->createView()->getView(),
                'update_form' => $updateForm->createView()->getView(),
                'refund_form' => $refundForm->createView()->getView(),
            ])
        );
    }

    public function onCustomerEditBottom(HookRenderEvent $event): void
    {
        $customerId = (int) $event->getArgument('customer_id');

        $event->add($this->render('payzen-embedded/customer-edit.html.twig', [
            'customer_id' => $customerId,
            'transactions' => $this->getTransactionHistory(customerId: $customerId),
        ]));
    }

    /**
     * Reproduces the payzen_embedded_history loop in PHP (default order: order_id, created, transaction_ref).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTransactionHistory(?int $orderId = null, ?int $customerId = null): array
    {
        $search = PayzenEmbeddedTransactionHistoryQuery::create();

        if (null !== $orderId) {
            $search->filterByOrderId($orderId);
        }

        if (null !== $customerId) {
            $search->filterByCustomerId($customerId);
        }

        $search
            ->orderByOrderId(Criteria::ASC)
            ->addAscendingOrderByColumn('created_at')
            ->orderById(Criteria::ASC);

        $rows = [];
        $orderRefs = [];
        $currencySymbols = [];
        $currencyCodes = [];
        $reader = new TransactionHistoryReader();

        /** @var PayzenEmbeddedTransactionHistory $transaction */
        foreach ($search->find() as $transaction) {
            $outcome = $reader->outcomeOf($transaction);
            $rowOrderId = $transaction->getOrderId();

            if ($rowOrderId && !\array_key_exists($rowOrderId, $orderRefs)) {
                $rowOrder = OrderQuery::create()->findPk($rowOrderId);
                $orderRefs[$rowOrderId] = null !== $rowOrder ? $rowOrder->getRef() : null;
            }

            $currencyId = $transaction->getCurrencyId();

            if ($currencyId && !\array_key_exists($currencyId, $currencySymbols)) {
                $currency = CurrencyQuery::create()->findPk($currencyId);
                $currencySymbols[$currencyId] = null !== $currency ? $currency->getSymbol() : '';
                $currencyCodes[$currencyId] = null !== $currency ? (string) $currency->getCode() : '';
            }

            $rows[] = [
                'ID' => $transaction->getId(),
                'ORDER_ID' => $rowOrderId,
                'ORDER_REF' => $rowOrderId ? ($orderRefs[$rowOrderId] ?? null) : null,
                'TRANSACTION_REF' => $transaction->getUuid(),
                'STATUS' => $transaction->getStatus(),
                'DETAILED_STATUS' => $transaction->getDetailedstatus(),
                'OPERATION_TYPE' => $outcome->operationType,
                'IS_CAPTURED' => $outcome->isCaptured(),
                'AMOUNT' => $transaction->getAmount(),
                'AMOUNT_FORMATTED' => RefundAmount::format((int) $transaction->getAmount(), $currencyId ? ($currencyCodes[$currencyId] ?? '') : ''),
                'CURRENCY_ID' => $currencyId,
                'CURRENCY_SYMBOL' => $currencyId ? ($currencySymbols[$currencyId] ?? '') : '',
                'CREATION_DATE' => $transaction->getCreationdate(),
                'UPDATE_DATE' => $transaction->getUpdatedAt(),
                'ERROR_CODE' => $transaction->getErrorcode(),
                'ERROR_MESSAGE' => $transaction->getErrormessage(),
                'DETAILED_ERROR_CODE' => $transaction->getDetailederrorcode(),
                'DETAILED_ERROR_MESSAGE' => $transaction->getDetailederrormessage(),
                'IS_FINISHED' => (bool) $transaction->getFinished(),
            ];
        }

        return $rows;
    }

    public static function getSubscribedHooks(): array
    {
        return [
            "order-edit.bottom" => [
                [
                    "type" => "back",
                    "method" => "onOrderEditBottom"
                ]
            ],
            "customer-edit.bottom" => [
                [
                    "type" => "back",
                    "method" => "onCustomerEditBottom"
                ]
            ],
            "module.configuration" => [
                [
                    "type" => "back",
                    "method" => "onModuleConfigure"
                ]
            ]
        ];
    }
}
