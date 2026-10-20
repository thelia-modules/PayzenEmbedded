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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The cancel link of the payment page stays in the browser history: it cancels an order whose
 * payment is still open, never one PayZen already paid or authorized. Boots Thelia, see
 * phpunit.integration.xml.dist.
 */
final class CancelPaymentTest extends WebIntegrationTestCase
{
    private CustomerSessionInjector $injector;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        if (isset($this->injector)) {
            $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
            $this->injector->clear();
        }

        parent::tearDown();
    }

    public function testTheCustomerCancelsAnOrderWhosePaymentIsOpen(): void
    {
        $order = $this->factory->order();
        $this->injector->setCustomer($order->getCustomer());

        $this->client->request('GET', '/payzen-embedded/cancel-payment/'.$order->getId());

        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusOf((int) $order->getId()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function securedStatuses(): iterable
    {
        yield 'paid' => [OrderStatus::CODE_PAID];
        yield 'authorized' => [OrderStatus::CODE_AWAITING_CAPTURE];
    }

    #[DataProvider('securedStatuses')]
    public function testAnOrderWhosePaymentWentThroughIsLeftAsItIs(string $statusCode): void
    {
        $order = $this->factory->order(null, ['statusCode' => $statusCode]);
        $this->injector->setCustomer($order->getCustomer());

        $this->client->request('GET', '/payzen-embedded/cancel-payment/'.$order->getId());

        self::assertSame($statusCode, $this->statusOf((int) $order->getId()));
    }

    public function testAVisitorWhoIsNotSignedInCancelsNothing(): void
    {
        $order = $this->factory->order();

        $this->client->request('GET', '/payzen-embedded/cancel-payment/'.$order->getId());

        self::assertTrue($this->client->getResponse()->isRedirect(), 'The visitor is sent to the login page.');
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf((int) $order->getId()));
    }

    public function testACustomerCancelsNoOrderOfAnotherCustomer(): void
    {
        $order = $this->factory->order();
        $this->injector->setCustomer($this->factory->order()->getCustomer());

        $this->client->request('GET', '/payzen-embedded/cancel-payment/'.$order->getId());

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf((int) $order->getId()));
    }

    private function statusOf(int $orderId): string
    {
        OrderTableMap::clearInstancePool();

        return (string) OrderQuery::create()->findPk($orderId)?->getOrderStatus()->getCode();
    }
}
