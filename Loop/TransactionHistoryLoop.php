<?php

namespace PayzenEmbedded\Loop;

use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use Thelia\Type;
use Thelia\Type\TypeCollection;

/**
 * Class CustomerCardLoop
 * @package ETransaction\Loop
 * @method getOrderId() int|null
 * @method getCustomerId() int|null
 * @method string[] getOrder()
 */
class TransactionHistoryLoop extends BaseLoop implements PropelSearchLoopInterface
{
    protected $timestampable = true;

    /**
     * @return ArgumentCollection
     */
    protected function getArgDefinitions()
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('order_id'),
            Argument::createIntTypeArgument('customer_id'),
            new Argument(
                'order',
                new TypeCollection(
                    new Type\EnumListType(
                        [
                            'id', 'id_reverse',
                            'order_id', 'order_id_reverse',
                            'created', 'created_reverse',
                            'updated', 'updated_reverse',
                            'transaction_ref', 'transaction_ref_reverse'
                        ]
                    )
                ),
                'created'
            )
        );
    }

    /**
     * @return PayzenEmbeddedTransactionHistoryQuery
     */
    public function buildModelCriteria()
    {
        $search = PayzenEmbeddedTransactionHistoryQuery::create();

        if (null !== $this->getOrderId()) {
            $search->filterByOrderId($this->getOrderId());
        }

        if (null !== $this->getCustomerId()) {
            $search->filterByCustomerId($this->getCustomerId());
        }

        $orders  = $this->getOrder();

        foreach ($orders as $order) {
            switch ($order) {
                case "id":
                    $search->orderById(Criteria::ASC);
                    break;
                case "id_reverse":
                    $search->orderById(Criteria::DESC);
                    break;
                case "order_id":
                    $search->orderByOrderId(Criteria::ASC);
                    break;
                case "order_id_reverse":
                    $search->orderByOrderId(Criteria::DESC);
                    break;
                case "transaction_ref":
                    $search->orderByUuid(Criteria::ASC);
                    break;
                case "transaction_ref_reverse":
                    $search->orderByUuid(Criteria::DESC);
                    break;
                case "created":
                    $search->addAscendingOrderByColumn('created_at');
                    break;
                case "created_reverse":
                    $search->addDescendingOrderByColumn('created_at');
                    break;
                case "updated":
                    $search->addAscendingOrderByColumn('updated_at');
                    break;
                case "updated_reverse":
                    $search->addDescendingOrderByColumn('updated_at');
                    break;
            }
        }

        return $search;
    }

    /**
     * @param LoopResult $loopResult
     * @return LoopResult
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function parseResults(LoopResult $loopResult)
    {
        /** @var \PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory $transactionHistory */
        foreach ($loopResult->getResultDataCollection() as $transactionHistory) {
            $loopResult->addRow(
                (new LoopResultRow($transactionHistory))
                    ->set('ID', $transactionHistory->getId())

                    ->set('ORDER_ID', $transactionHistory->getOrderId())
                    ->set('CUSTOMER_ID', $transactionHistory->getCustomerId())
                    ->set('ADMIN_ID', $transactionHistory->getAdminId())

                    ->set('TRANSACTION_REF', $transactionHistory->getUuid())
                    ->set('STATUS', $transactionHistory->getStatus())
                    ->set('DETAILED_STATUS', $transactionHistory->getDetailedstatus())
                    ->set('AMOUNT', $transactionHistory->getAmount())
                    ->set('CURRENCY_ID', $transactionHistory->getCurrencyId())
                    ->set('CREATION_DATE', $transactionHistory->getCreationdate())
                    ->set('ERROR_CODE', $transactionHistory->getErrorcode())
                    ->set('ERROR_MESSAGE', $transactionHistory->getErrormessage())
                    ->set('DETAILED_ERROR_CODE', $transactionHistory->getDetailederrorcode())
                    ->set('DETAILED_ERROR_MESSAGE', $transactionHistory->getDetailederrormessage())
                    ->set('IS_FINISHED', $transactionHistory->getFinished())
            );
        }

        return $loopResult;
    }
}
