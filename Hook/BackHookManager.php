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
use PayzenEmbedded\Form\TransactionGetForm;
use PayzenEmbedded\Form\TransactionUpdateForm;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use PayzenEmbedded\PayzenEmbedded;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\MessageQuery;
use Thelia\Model\OrderQuery;
use Thelia\Tools\URL;

class BackHookManager extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public function onModuleConfigure(HookRenderEvent $event): void
    {
        $form = $this->formFactory->createForm(ConfigurationForm::getName());

        $defaultCurrency = CurrencyQuery::create()->findOneByByDefault(true);

        $confirmationMessage = MessageQuery::create()
            ->findOneByName(PayzenEmbedded::CONFIRMATION_MESSAGE_NAME);

        $event->add(
            $this->render('payzen-embedded/module-configuration.html.twig', [
                'form' => $form->createView()->getView(),
                'default_currency_symbol' => null !== $defaultCurrency ? $defaultCurrency->getSymbol() : '',
                'payment_confirmation_message_id' => null !== $confirmationMessage ? $confirmationMessage->getId() : null,
                'ipn_callback_url' => URL::getInstance()->absoluteUrl('/payzen-embedded/ipn-callback'),
            ])
        );
    }

    public function onOrderEditBottom(HookRenderEvent $event): void
    {
        $orderId = (int) $event->getArgument('order_id');

        $order = OrderQuery::create()->findPk($orderId);

        if (null === $order || PayzenEmbedded::getModuleId() !== $order->getPaymentModuleId()) {
            return;
        }

        $transactions = $this->getTransactionHistory(orderId: $orderId);

        $finished = false;
        $lastTransactionAmount = 0;

        foreach ($transactions as $transaction) {
            $finished = (bool) $transaction['IS_FINISHED'];
            $lastTransactionAmount = $transaction['AMOUNT'] / 100;
        }

        $getForm = $this->formFactory->createForm(TransactionGetForm::getName());
        $updateForm = $this->formFactory->createForm(TransactionUpdateForm::getName());

        $event->add(
            $this->render('payzen-embedded/order-edit.html.twig', [
                'order_id' => $orderId,
                'transactions' => $transactions,
                'finished' => $finished,
                'last_transaction_amount' => $lastTransactionAmount,
                'get_form' => $getForm->createView()->getView(),
                'update_form' => $updateForm->createView()->getView(),
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
            ->orderByUuid(Criteria::ASC);

        $rows = [];
        $orderRefs = [];
        $currencySymbols = [];

        /** @var PayzenEmbeddedTransactionHistory $transaction */
        foreach ($search->find() as $transaction) {
            $rowOrderId = $transaction->getOrderId();

            if ($rowOrderId && !\array_key_exists($rowOrderId, $orderRefs)) {
                $rowOrder = OrderQuery::create()->findPk($rowOrderId);
                $orderRefs[$rowOrderId] = null !== $rowOrder ? $rowOrder->getRef() : null;
            }

            $currencyId = $transaction->getCurrencyId();

            if ($currencyId && !\array_key_exists($currencyId, $currencySymbols)) {
                $currency = CurrencyQuery::create()->findPk($currencyId);
                $currencySymbols[$currencyId] = null !== $currency ? $currency->getSymbol() : '';
            }

            $rows[] = [
                'ID' => $transaction->getId(),
                'ORDER_ID' => $rowOrderId,
                'ORDER_REF' => $rowOrderId ? ($orderRefs[$rowOrderId] ?? null) : null,
                'TRANSACTION_REF' => $transaction->getUuid(),
                'STATUS' => $transaction->getStatus(),
                'DETAILED_STATUS' => $transaction->getDetailedstatus(),
                'AMOUNT' => $transaction->getAmount(),
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
