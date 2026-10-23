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

use PayzenEmbedded\Controller\FrontController;
use PayzenEmbedded\LyraClient\TokenCancellationFailedException;
use PayzenEmbedded\Service\StoredMethodRevoker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\CustomerPaymentMethodQuery;
use Thelia\Tools\TokenProvider;

/**
 * The customer asking to forget their cards: a POST that carries the token of their session, an answer when
 * PayZen refuses, nothing but a refusal for a visitor.
 */
final class AliasClearTest extends PayzenIntegrationTestCase
{
    private const TOKEN = 'kept-token-alias-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setModuleConfig('allow_one_click_payments', '1');
    }

    public function testTheRouteAnswersAPostOnly(): void
    {
        $route = static::getContainer()->get('router')->getRouteCollection()->get('payzen_embedded_front_alias_clear');

        self::assertNotNull($route);
        self::assertSame(['POST'], $route->getMethods(), 'A link in a page or a mail must not cancel the cards.');
    }

    public function testARequestWithoutTheTokenOfTheSessionForgetsNothingAndSaysSo(): void
    {
        $customer = $this->customer();
        $method = $this->keptCard($customer);
        $revoker = new RecordingTokenGateway();
        $this->revokeWith($revoker);
        $session = $this->sessionOf($customer);

        $response = $this->clear($session, $this->post($session, 'not-the-token'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame([], $revoker->cancelled, 'PayZen was not asked.');
        self::assertNotNull(CustomerPaymentMethodQuery::create()->findPk($method->getId()), 'The card is still kept.');
        self::assertCount(1, $session->getFlashBag()->get('error'), 'The customer is told.');
    }

    public function testARequestWithoutAnyTokenForgetsNothing(): void
    {
        $customer = $this->customer();
        $method = $this->keptCard($customer);
        $session = $this->sessionOf($customer);

        $this->clear($session, $this->post($session, null));

        self::assertNotNull(CustomerPaymentMethodQuery::create()->findPk($method->getId()));
    }

    public function testARequestWithTheTokenForgetsTheCardsThroughPayZen(): void
    {
        $customer = $this->customer();
        $method = $this->keptCard($customer);
        $gateway = new RecordingTokenGateway();
        $this->revokeWith($gateway);
        $session = $this->sessionOf($customer);

        $this->clear($session, $this->post($session, static::getContainer()->get(TokenProvider::class)->assignToken()));

        self::assertSame([self::TOKEN], $gateway->cancelled);
        self::assertNull(CustomerPaymentMethodQuery::create()->findPk($method->getId()), 'The card is forgotten.');
        self::assertSame([], $session->getFlashBag()->get('error'));
    }

    public function testACardPayZenDidNotCancelStaysAndTheCustomerIsTold(): void
    {
        $customer = $this->customer();
        $method = $this->keptCard($customer);
        $this->revokeWith(new RecordingTokenGateway(new TokenCancellationFailedException('PSP_123')));
        $session = $this->sessionOf($customer);

        $this->clear($session, $this->post($session, static::getContainer()->get(TokenProvider::class)->assignToken()));

        self::assertNotNull(CustomerPaymentMethodQuery::create()->findPk($method->getId()), 'The card is still kept.');
        $flashes = $session->getFlashBag()->get('error');
        self::assertCount(1, $flashes);
        self::assertStringNotContainsString(self::TOKEN, (string) $flashes[0]);
    }

    /**
     * PayZen refuses, or cannot be reached: the next card would wait for the same timeout, and the page answers once.
     */
    public function testTheLoopStopsAtTheFirstRefusalAndTheCustomerIsToldHowManyCardsRemain(): void
    {
        $customer = $this->customer();
        $this->keptCard($customer, 'kept-token-first-0123456789abcdef');
        $this->keptCard($customer, 'kept-token-second-0123456789abcdef', false);
        $this->keptCard($customer, 'kept-token-third-0123456789abcdef', false);
        $gateway = $this->unreachableAfter(0);
        $this->revokeWith($gateway);
        $session = $this->sessionOf($customer);

        $this->clear($session, $this->post($session, static::getContainer()->get(TokenProvider::class)->assignToken()));

        self::assertSame(1, $gateway->asked, 'PayZen was asked once: the other cards were not tried.');
        self::assertCount(3, CustomerPaymentMethodQuery::create()->filterByCustomerId($customer->getId())->find(), 'Every card is kept.');
        $flashes = $session->getFlashBag()->get('error');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('3', (string) $flashes[0], 'The customer is told how many cards remain.');
    }

    public function testACardForgottenBeforeTheRefusalIsGoneAndTheOthersAreCounted(): void
    {
        $customer = $this->customer();
        $this->keptCard($customer, 'kept-token-first-0123456789abcdef');
        $this->keptCard($customer, 'kept-token-second-0123456789abcdef', false);
        $this->keptCard($customer, 'kept-token-third-0123456789abcdef', false);
        $gateway = $this->unreachableAfter(1);
        $this->revokeWith($gateway);
        $session = $this->sessionOf($customer);

        $this->clear($session, $this->post($session, static::getContainer()->get(TokenProvider::class)->assignToken()));

        self::assertSame(2, $gateway->asked, 'The second card failed, the third was not tried.');
        self::assertCount(2, CustomerPaymentMethodQuery::create()->filterByCustomerId($customer->getId())->find(), 'One card was forgotten.');
        $flashes = $session->getFlashBag()->get('error');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('2', (string) $flashes[0]);
        self::assertStringNotContainsString('kept-token', (string) $flashes[0]);
    }

    /**
     * PayZen refuses one card, not the next: the refusal is about that card, the others are asked.
     */
    public function testARefusalOfOneCardLetsTheNextOneBeForgotten(): void
    {
        $customer = $this->customer();
        $this->keptCard($customer, 'kept-token-first-0123456789abcdef');
        $second = $this->keptCard($customer, 'kept-token-second-0123456789abcdef', false);
        $gateway = $this->scripted(['refused', null]);
        $this->revokeWith($gateway);
        $session = $this->sessionOf($customer);

        $this->clear($session, $this->post($session, static::getContainer()->get(TokenProvider::class)->assignToken()));

        self::assertSame(2, $gateway->asked, 'The second card was asked after the refusal of the first.');
        $left = CustomerPaymentMethodQuery::create()->filterByCustomerId($customer->getId())->find();
        self::assertCount(1, $left, 'The second card is forgotten, the first is kept.');
        self::assertNotSame($second->getId(), $left[0]->getId());
        $flashes = $session->getFlashBag()->get('error');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('1', (string) $flashes[0]);
        self::assertStringNotContainsString('2', (string) $flashes[0]);
    }

    public function testARefusalThenAnUnreachablePlatformCountsTheRefusedCardAndTheOnesNotTried(): void
    {
        $customer = $this->customer();
        $this->keptCard($customer, 'kept-token-first-0123456789abcdef');
        $this->keptCard($customer, 'kept-token-second-0123456789abcdef', false);
        $this->keptCard($customer, 'kept-token-third-0123456789abcdef', false);
        $this->keptCard($customer, 'kept-token-fourth-0123456789abcdef', false);
        $gateway = $this->scripted(['refused', null, TokenCancellationFailedException::UNREACHABLE]);
        $this->revokeWith($gateway);
        $session = $this->sessionOf($customer);

        $this->clear($session, $this->post($session, static::getContainer()->get(TokenProvider::class)->assignToken()));

        self::assertSame(3, $gateway->asked, 'The fourth card was not tried.');
        self::assertCount(3, CustomerPaymentMethodQuery::create()->filterByCustomerId($customer->getId())->find(), 'One card was forgotten.');
        $flashes = $session->getFlashBag()->get('error');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('3', (string) $flashes[0], 'The refused card, the unreachable one and the one not tried.');
    }

    public function testAVisitorIsRefusedWithoutAnError(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $this->expectException(AccessDeniedHttpException::class);

        $this->clear($session, $this->post($session, 'any'));
    }

    public function testTheHookThatShowsTheFormIsWiredWithTheTokenProvider(): void
    {
        self::assertInstanceOf(\PayzenEmbedded\Hook\FrontHookManager::class, static::getContainer()->get(\PayzenEmbedded\Hook\FrontHookManager::class));
    }

    private function clear(Session $session, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $stack = new RequestStack();
        $stack->push($request);
        $controller = new FrontController();
        $controller->requestStack = $stack;

        return $controller->clearCustomerToken(
            $session,
            $this->getService(CustomerPaymentMethodService::class),
            static::getContainer()->get(TokenProvider::class),
            Translator::getInstance() ?? $this->getService(Translator::class),
        );
    }

    private function post(Session $session, ?string $token): Request
    {
        $request = Request::create('/payzen-embedded/alias-clear', 'POST', null === $token ? [] : ['_token' => $token]);
        $request->setSession($session);
        // The token provider reads the session of the request the container holds.
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);

        return $request;
    }

    private function sessionOf(Customer $customer): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setCustomerUser($customer);

        return $session;
    }

    private function revokeWith(\PayzenEmbedded\LyraClient\StoredTokenGateway $gateway): void
    {
        static::getContainer()->set(StoredMethodRevoker::class, new StoredMethodRevoker($gateway));
    }

    /**
     * A platform that answers each call as told: null cancels the card, a word refuses it with that reason.
     *
     * @param list<string|null> $answers
     */
    private function scripted(array $answers): object
    {
        return new class($answers) implements \PayzenEmbedded\LyraClient\StoredTokenGateway {
            public int $asked = 0;

            public function __construct(private readonly array $answers)
            {
            }

            public function cancel(string $token): void
            {
                $answer = $this->answers[$this->asked++] ?? null;

                if (null !== $answer) {
                    throw new TokenCancellationFailedException($answer);
                }
            }
        };
    }

    /**
     * A platform that cancels the first `$succeeding` cards it is asked about, then cannot be reached.
     */
    private function unreachableAfter(int $succeeding): object
    {
        return new class($succeeding) implements \PayzenEmbedded\LyraClient\StoredTokenGateway {
            public int $asked = 0;

            public function __construct(private readonly int $succeeding)
            {
            }

            public function cancel(string $token): void
            {
                if (++$this->asked > $this->succeeding) {
                    throw new TokenCancellationFailedException('unreachable');
                }
            }
        };
    }

    private function keptCard(Customer $customer, string $token = self::TOKEN, bool $default = true): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($this->module->getId())
            ->setToken($token)
            ->setLabel('Visa •••• 4242')
            ->setIsDefault($default);
        $method->save($this->getPropelConnection());

        return $method;
    }
}
