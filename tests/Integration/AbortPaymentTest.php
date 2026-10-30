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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Model\Customer;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The customer gives up paying with PayZen: through the shop's HTTP kernel, the order is cancelled on a POST that
 * carries the token of the session, for an order of the customer that PayZen is to pay and that is not paid yet,
 * and on nothing else. The page PayZen sends a refused payment to changes nothing.
 */
final class AbortPaymentTest extends WebIntegrationTestCase
{
    private const TOKEN_ID = 'payzen_embedded_abort_payment';

    private FixtureFactory $factory;

    private object $visit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new FixtureFactory($this->getPropelConnection());

        // Puts the customer in the session of every request, the way a login does, and reads the token the
        // session holds for the cancellation forms.
        $this->visit = new class(static::getContainer()->get('security.csrf.token_manager')) implements EventSubscriberInterface {
            public ?Customer $customer = null;

            public ?string $token = null;

            public function __construct(private readonly CsrfTokenManagerInterface $tokens)
            {
            }

            public function onKernelRequest(RequestEvent $event): void
            {
                if (null === $this->customer || !$event->isMainRequest() || !$event->getRequest()->hasSession(true)) {
                    return;
                }

                $session = $event->getRequest()->getSession();

                if (!$session->isStarted()) {
                    $session->start();
                }

                $session->set('thelia.customer_user', $this->customer);
                $this->token = $this->tokens->getToken('payzen_embedded_abort_payment')->getValue();
            }

            public static function getSubscribedEvents(): array
            {
                return [KernelEvents::REQUEST => ['onKernelRequest', 200]];
            }
        };

        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->visit);
    }

    protected function tearDown(): void
    {
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->visit);

        parent::tearDown();
    }

    public function testTheRouteAnswersAPostOnly(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $route = $this->getService(RouterInterface::class)->getRouteCollection()->get('payzen_embedded_front_abort_payment');
        self::assertNotNull($route);
        self::assertSame(['POST'], $route->getMethods(), 'A link in a page, a mail or an image must not cancel an order.');

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertResponseStatusCodeSame(405);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testAPostWithoutTheTokenChangesNothing(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $this->client->request('POST', $this->cancelUrl($order));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testAPostWithAnotherTokenChangesNothing(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);
        $this->tokenOfTheSession();

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => 'not-the-token-of-the-session']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testTheCustomerCancelsTheirOrderWaitingForItsPayZenPayment(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession()]);

        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statusesOfAnOrderAlreadyPaid(): iterable
    {
        yield 'paid' => [OrderStatus::CODE_PAID];
        yield 'being prepared' => [OrderStatus::CODE_PROCESSING];
        yield 'sent' => [OrderStatus::CODE_SENT];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusesOfAnOrderAlreadyPaid')]
    public function testAnOrderAlreadyPaidKeepsItsStatus(string $status): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer, ['statusCode' => $status]);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession()]);

        self::assertSame($status, $this->statusOf($order));
        self::assertResponseRedirects();
    }

    public function testACancelledOrderStaysCancelledAndTheCustomerIsSentToTheFailurePage(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer, ['statusCode' => OrderStatus::CODE_CANCELED]);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession()]);

        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
    }

    public function testAnOrderPaidWithAnotherModuleKeepsItsStatus(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer, ['paymentModuleCode' => 'Cheque']);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession()]);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
        self::assertResponseRedirects();
    }

    public function testAnOrderOfAnotherCustomerIsRefused(): void
    {
        $this->signedIn();
        $order = $this->order($this->factory->customer($this->factory->customerTitle()));

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession()]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testAnUnknownOrderIsRefused(): void
    {
        $this->signedIn();

        $this->client->request('POST', $this->getService(RouterInterface::class)->generate('payzen_embedded_front_abort_payment', ['orderId' => \PHP_INT_MAX]), ['_token' => $this->tokenOfTheSession()]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAVisitorIsRefusedWithoutAServerError(): void
    {
        $order = $this->order($this->factory->customer($this->factory->customerTitle()));

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => 'any']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    /**
     * The payment form posts a refused payment without any token of the shop: the page it lands on only sends the
     * customer to the failure page, and the notification of PayZen settles the order.
     */
    public function testTheRefusedPaymentChangesNothingAndSendsTheCustomerToTheFailurePage(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $this->client->request('POST', $this->getService(RouterInterface::class)->generate('payzen_embedded_front_payment_refused', ['orderId' => $order->getId()]), ['kr-answer' => '{}', 'kr-hash' => 'any']);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
    }

    public function testTheRefusedPaymentOfAPaidOrderChangesNothing(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);

        $this->client->request('GET', $this->getService(RouterInterface::class)->generate('payzen_embedded_front_payment_refused', ['orderId' => $order->getId()]));

        self::assertSame(OrderStatus::CODE_PAID, $this->statusOf($order));
        self::assertResponseRedirects();
    }

    private function signedIn(): Customer
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $this->visit->customer = $customer;

        return $customer;
    }

    /**
     * The token the session holds, read on a first request of the visit, as a page holding the form would.
     */
    private function tokenOfTheSession(): string
    {
        $this->client->request('GET', '/payzen-embedded/no-such-page');

        self::assertNotNull($this->visit->token);

        return $this->visit->token;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function order(Customer $customer, array $overrides = []): Order
    {
        return $this->factory->order($customer, $overrides + ['paymentModuleCode' => 'PayzenEmbedded']);
    }

    private function cancelUrl(Order $order): string
    {
        return $this->getService(RouterInterface::class)->generate('payzen_embedded_front_abort_payment', ['orderId' => $order->getId()]);
    }

    private function statusOf(Order $order): string
    {
        OrderTableMap::clearInstancePool();

        return (string) OrderQuery::create()->findPk($order->getId(), $this->getPropelConnection())?->getOrderStatus()?->getCode();
    }

    private function assertSentToTheFailurePage(Order $order): void
    {
        self::assertResponseRedirects();

        $location = (string) $this->client->getResponse()->headers->get('Location');
        $failurePage = $this->getService(RouterInterface::class)->generate('checkout_failed');

        self::assertSame($failurePage, parse_url($location, \PHP_URL_PATH));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame((string) $order->getId(), $query['order_id'] ?? null);
    }
}
