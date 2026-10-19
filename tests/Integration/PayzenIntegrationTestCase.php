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

use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Log\Tlog;
use Thelia\Model\Customer;
use Thelia\Model\Module;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentPart;
use Thelia\Model\OrderPaymentPartQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Tests that run on the database `bin/test-prepare` builds, with the module active in it. Each test is
 * rolled back; the module's own settings are read again from the database each time.
 */
abstract class PayzenIntegrationTestCase extends IntegrationTestCase
{
    protected FixtureFactory $factory;

    protected Module $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = ModuleQuery::create()->findOneByCode('PayzenEmbedded')
            ?? self::fail('PayzenEmbedded is not registered in the test database: run bin/test-prepare with the module in the shop.');
        self::assertSame(1, (int) $this->module->getActivate(), 'PayzenEmbedded is not active in the test database.');

        $this->factory = $this->createFixtureFactory();
        ModuleConfigQuery::resetConfigCache();

        RecordingTlogDestination::$lines = [];
        Tlog::getInstance()->setLevel(Tlog::DEBUG)->setDestinations(RecordingTlogDestination::class);
    }

    protected function tearDown(): void
    {
        Tlog::getInstance()->setLevel(Tlog::DEFAULT_LEVEL)->setDestinations(Tlog::DEFAUT_DESTINATIONS);
        ModuleConfigQuery::resetConfigCache();

        parent::tearDown();
    }

    protected function customer(): Customer
    {
        return $this->factory->customer($this->factory->customerTitle());
    }

    /**
     * An unpaid order of the customer that names PayZen as its payment module.
     */
    protected function payzenOrder(Customer $customer, array $overrides = []): Order
    {
        $order = $this->factory->order($customer, $overrides);
        $order->setPaymentModuleId($this->module->getId())->save($this->getPropelConnection());

        return $order;
    }

    protected function setModuleConfig(string $name, string $value): void
    {
        PayzenEmbedded::setConfigValue($name, $value);
        ModuleConfigQuery::resetConfigCache();
    }

    /**
     * An order worth `$total`, in one line, no postage and no tax.
     */
    protected function orderWorth(string $total, Customer $customer, array $overrides = []): Order
    {
        $order = $this->payzenOrder($customer, $overrides);
        $this->factory->orderProduct($order, ['quantity' => 1, 'price' => $total, 'promoPrice' => $total]);

        return $order;
    }

    /**
     * One share of the order: an instant means (the module of a gift card, here a module that stands
     * for it) or the provider, which is the primary part and carries the order's payment module.
     */
    protected function part(Order $order, Module $module, string $amount, OrderPaymentPartState $state, bool $primary, int $position): OrderPaymentPart
    {
        $part = (new OrderPaymentPart())
            ->setOrderId($order->getId())
            ->setPaymentModuleId($module->getId())
            ->setAmount($amount)
            ->setState($state->value)
            ->setIsPrimary($primary)
            ->setPosition($position);
        $part->save($this->getPropelConnection());

        return $part;
    }

    /**
     * @return list<string> the states of the parts of the order, in settlement order
     */
    protected function partStates(Order $order): array
    {
        \Thelia\Model\Map\OrderPaymentPartTableMap::clearInstancePool();

        return array_values(array_map(
            static fn (OrderPaymentPart $part): string => (string) $part->getState(),
            iterator_to_array(OrderPaymentPartQuery::create()->filterByOrderId($order->getId())->orderByPosition()->find()),
        ));
    }

    /**
     * What PayZen notifies when a payment of the order succeeds, as the module reads it.
     *
     * @param array<string, mixed> $transaction what is added to the transaction, or replaced in it
     *
     * @return array<string, mixed>
     */
    protected function notificationOfAPaymentOf(Order $order, int $minorUnits, array $transaction = []): array
    {
        return [
            'orderDetails' => ['orderId' => $order->getRef(), 'mode' => PayzenEmbedded::platformMode()],
            'transactions' => [$transaction + [
                'uuid' => 'tx-' . bin2hex(random_bytes(6)),
                'status' => TransactionOutcome::STATUS_PAID,
                'detailedStatus' => 'AUTHORISED',
                'operationType' => TransactionOutcome::OPERATION_DEBIT,
                'amount' => $minorUnits,
                'currency' => $order->getCurrency()->getCode(),
                'creationDate' => '2026-10-19T10:00:00+00:00',
                'metadata' => [TransactionOutcome::SHOP_MARKER_KEY => PayzenEmbedded::shopMarker()],
            ]],
        ];
    }
}
