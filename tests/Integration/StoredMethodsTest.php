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

use PayzenEmbedded\LyraClient\LyraTokenGateway;
use PayzenEmbedded\LyraClient\StoredTokenGateway;
use PayzenEmbedded\LyraClient\TokenCancellationFailedException;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\CardFormProvider;
use PayzenEmbedded\Service\StoredMethodContract;
use PayzenEmbedded\Service\StoredMethodRevoker;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Domain\Payment\Exception\StoredMethodRefusedException;
use Thelia\Domain\Payment\StoredPaymentMethodPayment;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\CustomerPaymentMethodQuery;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;

/**
 * The contract of a payment module that keeps payment methods, on what PayZen can do for it: pay with a
 * card kept earlier, and forget one. The platform is replaced everywhere: nothing here reaches a network.
 */
final class StoredMethodsTest extends PayzenIntegrationTestCase
{
    private const TOKEN = 'kept-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        // A client reads the settings when it is built: they are in place before any is.
        $this->setModuleConfig('allow_one_click_payments', '1');
        // The popup answer is a payload, no page to render.
        $this->setModuleConfig('popup_mode', '1');
    }

    public function testTheCoreSeesAModuleThatKeepsPaymentMethodsWhileOneClickPaymentsAreAllowed(): void
    {
        $service = $this->getService(CustomerPaymentMethodService::class);

        $this->setModuleConfig('allow_one_click_payments', '1');
        self::assertTrue((new PayzenEmbedded())->supportsStoredMethods());
        self::assertTrue($service->offersStoredMethods($this->module));

        $this->setModuleConfig('allow_one_click_payments', '0');
        self::assertFalse((new PayzenEmbedded())->supportsStoredMethods());
        self::assertFalse($service->offersStoredMethods($this->module));
    }

    public function testACardKeptEarlierPaysWithItsTokenAndTheBalanceOfTheOrder(): void
    {
        $customer = $this->customer();
        $method = $this->keptCard($customer);
        $order = $this->orderWorth('100.000000', $customer);
        $this->part($order, $this->otherModule(), '30.000000', OrderPaymentPartState::SETTLED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::PENDING, true, 2);
        $client = new RecordingLyraClient($this->dispatcher());

        $response = $this->moduleServing($client)->payWithStoredMethod($order, $method);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(self::TOKEN, $client->sent[1]['paymentMethodToken']);
        self::assertSame(7000, $client->sent[1]['amount']);
        self::assertSame('PAYMENT', $client->sent[1]['formAction']);
        self::assertStringContainsString('form-token', (string) $response->getContent());
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getContent(), 'The card token never goes to the browser.');
    }

    public function testANewCardIsPaidWithoutAnyTokenWhateverTheCustomerKept(): void
    {
        $customer = $this->customer();
        $this->keptCard($customer);
        $order = $this->orderWorth('100.000000', $customer);
        $client = new RecordingLyraClient($this->dispatcher());

        $this->moduleServing($client)->pay($order);

        self::assertArrayNotHasKey('paymentMethodToken', $client->sent[1]);
        self::assertSame('ASK_REGISTER_PAY', $client->sent[1]['formAction']);
        self::assertSame(10000, $client->sent[1]['amount']);
    }

    public function testACardOfAnotherCustomerNeverPaysTheOrder(): void
    {
        $method = $this->keptCard($this->customer());
        $order = $this->orderWorth('100.000000', $this->customer());
        $client = new RecordingLyraClient($this->dispatcher());

        try {
            $this->moduleServing($client)->payWithStoredMethod($order, $method);
            self::fail('A card kept by another customer must not pay.');
        } catch (\InvalidArgumentException) {
        }

        self::assertNull($client->sent, 'The platform was not asked.');
    }

    public function testACardKeptThroughAnotherModuleNeverPaysTheOrder(): void
    {
        $customer = $this->customer();
        $method = $this->keptCard($customer, $this->otherModule());
        $order = $this->orderWorth('100.000000', $customer);
        $client = new RecordingLyraClient($this->dispatcher());

        try {
            $this->moduleServing($client)->payWithStoredMethod($order, $method);
            self::fail('A card kept through another module must not pay.');
        } catch (\InvalidArgumentException) {
        }

        self::assertNull($client->sent);
    }

    public function testACardThePlatformCancelledIsForgottenByTheModule(): void
    {
        $method = $this->keptCard($this->customer());
        $gateway = new RecordingTokenGateway();

        $this->moduleForgettingWith($gateway)->forgetStoredMethod($method);

        self::assertSame([self::TOKEN], $gateway->cancelled);
    }

    public function testACardThePlatformKeepsIsRefusedWithoutSayingWhatThePlatformSaid(): void
    {
        $method = $this->keptCard($this->customer());
        $gateway = new RecordingTokenGateway(new TokenCancellationFailedException('PSP_123'));

        try {
            $this->moduleForgettingWith($gateway)->forgetStoredMethod($method);
            self::fail('A card the platform did not cancel must be refused.');
        } catch (StoredMethodRefusedException $refusal) {
            self::assertSame('PSP_123', $refusal->providerReason);
            self::assertSame(StoredMethodRefusedException::MESSAGE, $refusal->getMessage());
            self::assertNull($refusal->getPrevious());
            self::assertStringNotContainsString(self::TOKEN, (string) $refusal, 'The token is in no part of the exception.');
        }
    }

    public function testACardKeptThroughAnotherModuleIsNotForgottenByThisOne(): void
    {
        $method = $this->keptCard($this->customer(), $this->otherModule());
        $gateway = new RecordingTokenGateway();

        try {
            $this->moduleForgettingWith($gateway)->forgetStoredMethod($method);
            self::fail('PayZen must not cancel a token it did not hand out.');
        } catch (StoredMethodRefusedException) {
            // The only exception the core answers: the card stays kept.
        }

        self::assertSame([], $gateway->cancelled);
    }

    public function testNothingButARefusalComesOutOfTheModuleWhenForgettingACard(): void
    {
        $method = $this->keptCard($this->customer());
        $container = new Container();
        $container->set(StoredMethodRevoker::class, new class(new RecordingTokenGateway()) extends StoredMethodRevoker {
            public function revoke(CustomerPaymentMethod $method): void
            {
                throw new \RuntimeException('database gone, token ' . $method->getToken());
            }
        });

        try {
            $this->module($container)->forgetStoredMethod($method);
            self::fail('A failure of the module keeps the card, with a refusal.');
        } catch (StoredMethodRefusedException $refusal) {
            self::assertStringNotContainsString(self::TOKEN, (string) $refusal);
            self::assertStringContainsString('RuntimeException', RecordingTlogDestination::everythingAbout());
            self::assertStringNotContainsString(self::TOKEN, RecordingTlogDestination::everythingAbout());
        }
    }

    /**
     * The core builds the instance of a module that is not active with its own container, which holds none of the
     * services of the module: the card is still forgotten through PayZen, and still kept when it cannot be.
     */
    public function testAModuleWhoseServicesTheContainerLacksStillAsksThePlatform(): void
    {
        self::assertSame('', (string) PayzenEmbedded::getConfigValue('site_id', ''), 'The test database holds a PayZen contract: this test would reach the platform.');
        $method = $this->keptCard($this->customer());

        try {
            $this->module(new Container())->forgetStoredMethod($method);
            self::fail('A card PayZen was not asked about must stay.');
        } catch (StoredMethodRefusedException $refusal) {
            self::assertSame('unreachable', $refusal->providerReason, 'The platform gateway was asked, with nothing to reach.');
        }
    }

    public function testThePlatformGatewayCancelsAThroughTheTokenEndpoint(): void
    {
        $client = $this->gateway(['status' => 'SUCCESS', 'answer' => ['cancellationDate' => '2026-10-19T10:00:00+00:00']]);

        $client->cancel(self::TOKEN);

        self::assertSame(['V4/Token/Cancel', ['paymentMethodToken' => self::TOKEN]], $client->sent);
    }

    public function testThePlatformGatewayRefusesWithTheCodeOfTheAnswerAndNothingElse(): void
    {
        $client = $this->gateway(['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_077', 'errorMessage' => 'no such token ' . self::TOKEN]]);

        try {
            $client->cancel(self::TOKEN);
            self::fail('An error answer is a failure.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('PSP_077', $failure->reason);
            self::assertStringNotContainsString(self::TOKEN, (string) $failure);
        }
    }

    public function testATokenThePlatformRefusesToCancelBecauseItIsAlreadyCancelledIsCancelled(): void
    {
        $client = $this->scriptedGateway([
            'V4/Token/Cancel' => ['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_ANY']],
            'V4/Token/Get' => ['status' => 'SUCCESS', 'answer' => ['paymentMethodToken' => self::TOKEN, 'cancellationDate' => '2026-10-01T10:00:00+00:00']],
        ]);

        $client->cancel(self::TOKEN);

        self::assertSame(['V4/Token/Cancel', 'V4/Token/Get'], array_column($client->sent, 0));
        self::assertSame(['paymentMethodToken' => self::TOKEN], $client->sent[1][1]);
    }

    /**
     * Only a cancellation date proves the token gone. What the platform answers for a token it does not know is not
     * established (the shape of the answer, the PSP code to come): whichever way it looks, the refusal stands, since
     * a card dropped by the shop while the platform still holds it cannot be cancelled afterwards.
     */
    public function testATokenThePlatformRefusesToCancelAndMayNotKnowStaysRefused(): void
    {
        $client = $this->scriptedGateway([
            'V4/Token/Cancel' => ['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_077']],
            'V4/Token/Get' => ['status' => 'SUCCESS', 'answer' => ['paymentMethodToken' => null, 'cancellationDate' => null]],
        ]);

        try {
            $client->cancel(self::TOKEN);
            self::fail('A read that carries no cancellation date does not prove the token gone.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('PSP_077', $failure->reason);
            self::assertSame(['V4/Token/Cancel', 'V4/Token/Get'], array_column($client->sent, 0));
        }
    }

    public function testACancellationDateOfAnotherTokenProvesNothing(): void
    {
        $client = $this->scriptedGateway([
            'V4/Token/Cancel' => ['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_077']],
            'V4/Token/Get' => ['status' => 'SUCCESS', 'answer' => ['paymentMethodToken' => 'another-token-0123456789', 'cancellationDate' => '2026-10-01T10:00:00+00:00']],
        ]);

        try {
            $client->cancel(self::TOKEN);
            self::fail('A cancellation date on a token that is not the one asked for does not prove it gone.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('PSP_077', $failure->reason);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function datesThatAreNoDate(): iterable
    {
        yield 'empty string' => [''];
        yield 'zero' => [0];
        yield 'null' => [null];
        yield 'a number' => [20261001];
        yield 'a list' => [['2026-10-01']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('datesThatAreNoDate')]
    public function testACancellationDateThatIsNoDateProvesNothing(mixed $date): void
    {
        $client = $this->scriptedGateway([
            'V4/Token/Cancel' => ['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_077']],
            'V4/Token/Get' => ['status' => 'SUCCESS', 'answer' => ['paymentMethodToken' => self::TOKEN, 'cancellationDate' => $date]],
        ]);

        try {
            $client->cancel(self::TOKEN);
            self::fail('Only a non-empty string is a cancellation date.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('PSP_077', $failure->reason);
        }
    }

    public function testATokenThePlatformRefusesToCancelWhileItIsActiveStaysRefused(): void
    {
        $client = $this->scriptedGateway([
            'V4/Token/Cancel' => ['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_077']],
            'V4/Token/Get' => ['status' => 'SUCCESS', 'answer' => ['paymentMethodToken' => self::TOKEN, 'cancellationDate' => null]],
        ]);

        try {
            $client->cancel(self::TOKEN);
            self::fail('A token still active at the platform is not cancelled.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('PSP_077', $failure->reason);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>|\Throwable}>
     */
    public static function readsThatSayNothingAboutTheToken(): iterable
    {
        yield 'the read is refused' => [['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_999']]];
        yield 'the read cannot be done' => [new \RuntimeException('call failed')];
        yield 'the read has no answer' => [['status' => 'SUCCESS']];
        yield 'the read has no status' => [['answer' => ['cancellationDate' => '2026-10-01T10:00:00+00:00']]];
    }

    /**
     * @param array<string, mixed>|\Throwable $read
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('readsThatSayNothingAboutTheToken')]
    public function testATokenThePlatformRefusesToCancelIsNeverCancelledByAReadThatFailed(array|\Throwable $read): void
    {
        $client = $this->scriptedGateway([
            'V4/Token/Cancel' => ['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_077']],
            'V4/Token/Get' => $read,
        ]);

        try {
            $client->cancel(self::TOKEN);
            self::fail('Without a read that says the token is gone, the refusal stands.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('PSP_077', $failure->reason);
            self::assertSame(['V4/Token/Cancel', 'V4/Token/Get'], array_column($client->sent, 0));
        }
    }

    public function testATokenThePlatformCouldNotBeReachedForIsNotReadAgain(): void
    {
        $client = $this->scriptedGateway(['V4/Token/Cancel' => new \RuntimeException('call failed'), 'V4/Token/Get' => ['status' => 'SUCCESS', 'answer' => ['cancellationDate' => '2026-10-01T10:00:00+00:00']]]);

        try {
            $client->cancel(self::TOKEN);
            self::fail('An unreachable platform is a failure.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('unreachable', $failure->reason);
            self::assertSame(['V4/Token/Cancel'], array_column($client->sent, 0));
        }
    }

    public function testThePlatformGatewayHidesWhatAFailedCallQuotes(): void
    {
        $client = $this->gateway(new \RuntimeException('call failed, response {"paymentMethodToken":"' . self::TOKEN . '"}'));

        try {
            $client->cancel(self::TOKEN);
            self::fail('A failed call is a failure.');
        } catch (TokenCancellationFailedException $failure) {
            self::assertSame('unreachable', $failure->reason);
            self::assertNull($failure->getPrevious(), 'The SDK exception quotes the answer, and the answer the token.');
            self::assertStringNotContainsString(self::TOKEN, (string) $failure);
        }
    }

    /**
     * Through the core's own service, with the services the container wires: a card PayZen cannot be asked
     * about (no contract configured in the test database, so that no call leaves) stays kept.
     */
    public function testACardThePlatformCannotBeAskedAboutIsKeptByTheShop(): void
    {
        self::assertSame('', (string) PayzenEmbedded::getConfigValue('site_id', ''), 'The test database holds a PayZen contract: this test would reach the platform.');
        $customer = $this->customer();
        $method = $this->keptCard($customer);

        try {
            $this->getService(CustomerPaymentMethodService::class)->forget($customer, $method);
            self::fail('A card PayZen was not asked about must stay.');
        } catch (StoredMethodRefusedException $refusal) {
            self::assertSame('unreachable', $refusal->providerReason);
        }

        self::assertNotNull(CustomerPaymentMethodQuery::create()->findPk($method->getId()), 'The card is still kept.');
    }

    private function keptCard(Customer $customer, ?Module $through = null): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId(($through ?? $this->module)->getId())
            ->setToken(self::TOKEN)
            ->setLabel('Visa •••• 4242')
            ->setIsDefault(true);
        $method->save($this->getPropelConnection());
        // A card is tied to the contract it was registered on: the national one here, as the update links the cards it migrates.
        StoredMethodContract::link($method, null);

        return $method;
    }

    /**
     * The module as the core runs it, with the card form asked of a client that records instead of calling.
     */
    private function moduleServing(RecordingLyraClient $client): PayzenEmbedded
    {
        $provider = new class($this->dispatcher(), $this->getService(StoredPaymentMethodPayment::class), $client) extends CardFormProvider {
            public function __construct(EventDispatcherInterface $dispatcher, StoredPaymentMethodPayment $storedPayment, private readonly RecordingLyraClient $client)
            {
                parent::__construct($dispatcher, $storedPayment);
            }

            protected function createWrapper(): \PayzenEmbedded\LyraClient\LyraJavascriptClientManagementWrapper
            {
                return $this->client;
            }
        };

        $container = new Container();
        $container->set(CardFormProvider::class, $provider);

        return $this->module($container);
    }

    private function moduleForgettingWith(StoredTokenGateway $gateway): PayzenEmbedded
    {
        $container = new Container();
        $container->set(StoredMethodRevoker::class, new StoredMethodRevoker($gateway));

        return $this->module($container);
    }

    private function module(Container $container): PayzenEmbedded
    {
        $module = new PayzenEmbedded();
        $module->setContainer($container);
        $module->setDispatcher($this->dispatcher());

        return $module;
    }

    /**
     * @param array<string, mixed>|\Throwable $answer
     */
    private function gateway(array|\Throwable $answer): LyraTokenGateway
    {
        return new class($answer) extends LyraTokenGateway {
            /** @var array{string, array<string, mixed>}|null */
            public ?array $sent = null;

            public function __construct(private readonly array|\Throwable $answer)
            {
                parent::__construct();
            }

            public function post($target, $array)
            {
                $this->sent = [$target, $array];

                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    /**
     * A gateway whose platform answers by endpoint.
     *
     * @param array<string, array<string, mixed>|\Throwable> $answers
     */
    private function scriptedGateway(array $answers): LyraTokenGateway
    {
        return new class($answers) extends LyraTokenGateway {
            /** @var list<array{string, array<string, mixed>}> */
            public array $sent = [];

            /**
             * @param array<string, array<string, mixed>|\Throwable> $answers
             */
            public function __construct(private readonly array $answers)
            {
                parent::__construct();
            }

            public function post($target, $array)
            {
                $this->sent[] = [$target, $array];
                $answer = $this->answers[$target] ?? throw new \LogicException('Unexpected call to ' . $target);

                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }

    private function otherModule(): Module
    {
        return ModuleQuery::create()->filterByCode('PayzenEmbedded', \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne()
            ?? self::fail('No other module in the test database.');
    }
}
