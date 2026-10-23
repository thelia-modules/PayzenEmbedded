<?php

namespace PayzenEmbedded\Loop;

use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Core\Template\Element\LoopResultRow;
use Thelia\Core\Template\Element\PropelSearchLoopInterface;
use Thelia\Core\Template\Loop\Argument\Argument;
use Thelia\Core\Template\Loop\Argument\ArgumentCollection;
use Thelia\Model\CustomerPaymentMethodQuery;
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
    protected function getArgDefinitions(): ArgumentCollection
    {
        return new ArgumentCollection(
            Argument::createIntTypeArgument('customer_id', null, true)
        );
    }

    public function buildModelCriteria(): \Propel\Runtime\ActiveQuery\ModelCriteria
    {
        // The cards PayZen kept for the customer: the payment methods of the shop, kept through this module.
        return CustomerPaymentMethodQuery::create()
            ->filterByCustomerId($this->getCustomerId())
            ->filterByPaymentModuleId(PayzenEmbedded::getModuleId());
    }

    public function parseResults(LoopResult $loopResult): LoopResult
    {
        /** @var \Thelia\Model\CustomerPaymentMethod $token */
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
