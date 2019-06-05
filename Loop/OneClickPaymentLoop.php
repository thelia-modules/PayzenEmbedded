<?php

namespace PayzenEmbedded\Loop;

use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
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
class OneClickPaymentLoop extends BaseLoop implements PropelSearchLoopInterface
{
    /**
     * @return ArgumentCollection
     */
    protected function getArgDefinitions()
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('customer_id', null, true)
        );
    }

    /**
     * @return PayzenEmbeddedCustomerTokenQuery
     */
    public function buildModelCriteria()
    {
        return PayzenEmbeddedCustomerTokenQuery::create()->filterByCustomerId($this->getCustomerId());
    }

    /**
     * @param LoopResult $loopResult
     * @return LoopResult
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function parseResults(LoopResult $loopResult)
    {
        /** @var \PayzenEmbedded\Model\PayzenEmbeddedCustomerToken $token */
        foreach ($loopResult->getResultDataCollection() as $token) {
            $loopResult->addRow(
                (new LoopResultRow($token))
                    ->set('ID', $token->getId())
                    ->set('CUSTOMER_ID', $token->getCustomerId())
            );
        }

        return $loopResult;
    }
}
