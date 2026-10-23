<?php

declare(strict_types=1);

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

namespace PayzenEmbedded\Tests\Integration;

use PayzenEmbedded\Loop\OneClickPaymentLoop;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Template\Element\BaseLoop;
use Thelia\Core\Template\Element\LoopResult;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\ModuleQuery;

/**
 * The loop the front templates ask whether a customer has a card PayZen kept reads the payment methods
 * of the shop, where the cards are since 3.6.0.
 */
final class OneClickPaymentLoopTest extends PayzenIntegrationTestCase
{
    public function testTheLoopListsTheCardsKeptThroughThisModuleForTheCustomerOnly(): void
    {
        $customer = $this->customer();
        $other = ModuleQuery::create()->filterByCode('PayzenEmbedded', Criteria::NOT_EQUAL)->findOne() ?? self::fail('No other module.');
        $mine = $this->keep($customer->getId(), $this->module->getId(), 'loop-token-mine');
        $this->keep($customer->getId(), $other->getId(), 'loop-token-of-another-module');
        $this->keep($this->customer()->getId(), $this->module->getId(), 'loop-token-of-another-customer');

        $loop = (new \ReflectionClass(OneClickPaymentLoop::class))->newInstanceWithoutConstructor();
        $arguments = (new \ReflectionMethod($loop, 'getArgDefinitions'))->invoke($loop);
        $arguments->get('customer_id')->setValue($customer->getId());
        (new \ReflectionProperty(BaseLoop::class, 'args'))->setValue($loop, $arguments);

        $result = $loop->parseResults(new LoopResult($loop->buildModelCriteria()->find()));

        self::assertCount(1, $result);
        $row = $result->getResultDataCollection()->getFirst() ?? self::fail('No row.');
        self::assertSame($mine->getId(), $row->getId());
    }

    private function keep(int $customerId, int $moduleId, string $token): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId($customerId)
            ->setPaymentModuleId($moduleId)
            ->setToken($token)
            ->setLabel('Visa •••• 4242')
            ->setIsDefault(false);
        $method->save($this->getPropelConnection());

        return $method;
    }
}
