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
use Thelia\Core\Event\TheliaEvents;
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
 * and on nothing else. A GET on the same path, the link of a theme that did not follow, only asks to confirm. The page PayZen sends a refused payment to changes nothing.
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

    public function testTheRouteThatCancelsAnswersAPostOnly(): void
    {
        $route = $this->getService(RouterInterface::class)->getRouteCollection()->get('payzen_embedded_front_abort_payment');
        self::assertNotNull($route);
        self::assertSame(['POST'], $route->getMethods(), 'A link in a page, a mail or an image must not cancel an order.');
    }

    /**
     * A theme that overrides the payment page keeps its link, drawn with the name of the route of 3.6.0: the name still
     * gives the path, and the path answers a GET with a page that asks to confirm, never a 405.
     */
    public function testALinkOfAnOverriddenThemeOpensAConfirmationThatChangesNothing(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);
        $cancelled = 0;
        $this->getService(EventDispatcherInterface::class)->addListener(TheliaEvents::ORDER_UPDATE_STATUS, static function () use (&$cancelled): void {
            ++$cancelled;
        });

        $crawler = $this->client->request('GET', $this->cancelUrl($order));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
        self::assertSame(0, $cancelled, 'Nothing is dispatched by the page that asks to confirm.');
        self::assertStringContainsString($order->getRef(), (string) $this->client->getResponse()->getContent());

        $form = $crawler->filter('form[method="post"]');
        self::assertCount(1, $form);
        self::assertSame($this->cancelUrl($order), $form->attr('action'));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'), 'The form carries a token, which the next test posts back.');
    }

    public function testTheFormOfTheConfirmationCancelsTheOrder(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $crawler = $this->client->request('GET', $this->cancelUrl($order));
        $form = $crawler->filter('form[method="post"]');
        $this->client->request('POST', (string) $form->attr('action'), ['_token' => (string) $form->filter('input[name="_token"]')->attr('value')]);

        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
    }

    public function testTheConfirmationIsRefusedToAVisitor(): void
    {
        $order = $this->order($this->factory->customer($this->factory->customerTitle()));

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testTheConfirmationOfAnOrderOfAnotherCustomerIsRefused(): void
    {
        $this->signedIn();
        $order = $this->order($this->factory->customer($this->factory->customerTitle()));

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString($order->getRef(), (string) $this->client->getResponse()->getContent());
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testTheConfirmationOfAnUnknownOrderIsRefused(): void
    {
        $this->signedIn();

        $this->client->request('GET', $this->getService(RouterInterface::class)->generate('payzen_embedded_front_abort_payment', ['orderId' => \PHP_INT_MAX]));

        self::assertResponseStatusCodeSame(403);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusesOfAnOrderAlreadyPaid')]
    public function testTheConfirmationOfAnOrderAlreadyPaidSendsTheCustomerWhereThePostDoes(string $status): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer, ['statusCode' => $status]);

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertSame($status, $this->statusOf($order));
        self::assertResponseRedirects();
        self::assertSame(parse_url($this->getService(RouterInterface::class)->generate('checkout_confirm', ['order_id' => $order->getId()]), \PHP_URL_PATH), parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    public function testTheConfirmationOfACancelledOrderSendsTheCustomerToTheFailurePage(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer, ['statusCode' => OrderStatus::CODE_CANCELED]);

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
    }

    public function testTheConfirmationOfAnOrderPaidWithAnotherModuleSendsTheCustomerToTheFailurePage(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer, ['paymentModuleCode' => 'Cheque']);

        $this->client->request('GET', $this->cancelUrl($order));

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
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
     * The payment form of a theme from before 3.7.0 posts the refusal of PayZen to this route, with no token of the
     * shop: it is the refusal, answered as the route made for it answers, and nothing is changed.
     */
    public function testTheRefusalPostedByAnOlderThemeWithoutTokenIsAnsweredAsTheRefusalRoute(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $this->client->request('POST', $this->cancelUrl($order), ['kr-answer' => '{}', 'kr-hash' => 'any']);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame($this->locationOfTheRefusalRoute($order), $location);
    }

    public function testTheRefusalPostedByAnOlderThemeDispatchesNothing(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);
        $dispatched = 0;
        $this->getService(EventDispatcherInterface::class)->addListener(TheliaEvents::ORDER_UPDATE_STATUS, static function () use (&$dispatched): void {
            ++$dispatched;
        });

        $this->client->request('POST', $this->cancelUrl($order), ['kr-answer' => '{}', 'kr-hash' => 'any']);

        self::assertSame(0, $dispatched);
    }

    public function testTheRefusalPostedByAnOlderThemeForAVisitorIsAnsweredAsTheRefusalRoute(): void
    {
        $order = $this->order($this->factory->customer($this->factory->customerTitle()));

        $this->client->request('POST', $this->cancelUrl($order), ['kr-answer' => '{}', 'kr-hash' => 'any']);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame($this->locationOfTheRefusalRoute($order), $location);
    }

    public function testTheRefusalPostedByAnOlderThemeOnAnOrderOfAnotherCustomerChangesNothing(): void
    {
        $this->signedIn();
        $order = $this->order($this->factory->customer($this->factory->customerTitle()));

        $this->client->request('POST', $this->cancelUrl($order), ['kr-answer' => '{}', 'kr-hash' => 'any']);

        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame($this->locationOfTheRefusalRoute($order), $location);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function postsThatAreNotTheRefusalOfThePaymentForm(): iterable
    {
        yield 'only the answer' => [['kr-answer' => '{}']];
        yield 'only the hash' => [['kr-hash' => 'any']];
        yield 'neither' => [[]];
        yield 'another token' => [['_token' => 'not-the-token-of-the-session', 'foo' => 'bar']];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('postsThatAreNotTheRefusalOfThePaymentForm')]
    public function testAPostWithoutTokenThatIsNotTheRefusalOfThePaymentFormIsRefused(array $parameters): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $this->client->request('POST', $this->cancelUrl($order), $parameters);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testThePostWithTheTokenStillCancelsWhateverItCarriesBesides(): void
    {
        $customer = $this->signedIn();
        $order = $this->order($customer);

        $this->client->request('POST', $this->cancelUrl($order), ['_token' => $this->tokenOfTheSession(), 'kr-answer' => '{}', 'kr-hash' => 'any']);

        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusOf($order));
        $this->assertSentToTheFailurePage($order);
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

    /**
     * Where the route made for the refusal sends a POST of the payment form for this order, asked as a visitor
     * would see it: the answer the cancellation route has to give the same request.
     */
    private function locationOfTheRefusalRoute(Order $order): string
    {
        $this->client->request('POST', $this->getService(RouterInterface::class)->generate('payzen_embedded_front_payment_refused', ['orderId' => $order->getId()]), ['kr-answer' => '{}', 'kr-hash' => 'any']);

        return (string) $this->client->getResponse()->headers->get('Location');
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
