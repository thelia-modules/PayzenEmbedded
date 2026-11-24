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
use Thelia\Model\Customer;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
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
        $order = $this->payzenOrder();
        $this->injector->setCustomer($order->getCustomer());

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf((int) $order->getId()), 'The link only asks to confirm.');

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession($order->getCustomer())]);

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
        $order = $this->payzenOrder($statusCode);
        $this->injector->setCustomer($order->getCustomer());

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertResponseRedirects();
        self::assertSame($statusCode, $this->statusOf((int) $order->getId()), 'Following the link changes nothing.');

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession($order->getCustomer())]);

        self::assertResponseRedirects();
        self::assertSame($statusCode, $this->statusOf((int) $order->getId()), 'The confirmed POST leaves the order as it is.');
    }

    public function testAVisitorWhoIsNotSignedInCancelsNothing(): void
    {
        $order = $this->payzenOrder();

        $this->client->request('GET', $this->cancelUrl($order));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf((int) $order->getId()));
    }

    public function testACustomerCancelsNoOrderOfAnotherCustomer(): void
    {
        $order = $this->payzenOrder();
        $other = $this->payzenOrder()->getCustomer();
        $this->injector->setCustomer($other);

        $this->client->request('GET', $this->cancelUrl($order));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession($other)]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf((int) $order->getId()));
    }

    public function testAPostWithoutAValidTokenCancelsNothing(): void
    {
        $order = $this->payzenOrder();
        $this->injector->setCustomer($order->getCustomer());

        $this->client->request('POST', $this->cancelUrl($order));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => 'not-the-token-of-the-session']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf((int) $order->getId()));
    }

    private function payzenOrder(string $statusCode = OrderStatus::CODE_NOT_PAID): Order
    {
        return $this->factory->order(null, ['statusCode' => $statusCode, 'paymentModuleCode' => 'PayzenEmbedded']);
    }

    private function cancelUrl(Order $order): string
    {
        return '/payzen-embedded/cancel-payment/'.$order->getId();
    }

    /**
     * The token of the session of the customer, read from the form of the confirmation page of a throwaway order
     * of theirs, as a browser holding that page would post it.
     */
    private function tokenOfTheSession(Customer $customer): string
    {
        $throwaway = $this->factory->order($customer, ['paymentModuleCode' => 'PayzenEmbedded']);
        $crawler = $this->client->request('GET', $this->cancelUrl($throwaway));
        $token = (string) $crawler->filter('form[method="post"] input[name="_token"]')->attr('value');

        self::assertNotSame('', $token, 'The confirmation page carries the token of the session.');

        return $token;
    }

    private function statusOf(int $orderId): string
    {
        OrderTableMap::clearInstancePool();

        return (string) OrderQuery::create()->findPk($orderId)?->getOrderStatus()->getCode();
    }
}
