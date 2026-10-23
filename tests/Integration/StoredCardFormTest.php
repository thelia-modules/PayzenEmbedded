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

use PayzenEmbedded\LyraClient\LyraJavascriptClientManagementWrapper;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\CardFormProvider;
use PayzenEmbedded\Service\StoredMethodContract;
use PayzenEmbedded\Service\StoredMethodOfAnotherContractException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Thelia\Domain\Payment\StoredPaymentMethodPayment;
use Thelia\Model\CartQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Order;

/**
 * The card form of an order opens on the card the customer chose on the cart, whoever asks for it: the module's own
 * pay(), or a theme that mounts the fields in its checkout step. The platform is replaced everywhere: nothing here
 * reaches a network.
 */
final class StoredCardFormTest extends PayzenIntegrationTestCase
{
    private const TOKEN = 'chosen-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setModuleConfig('allow_one_click_payments', '1');
        $this->setModuleConfig('popup_mode', '1');
    }

    public function testTheFormOpensOnTheCardChosenOnTheCart(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $card = $this->card($customer, self::TOKEN);
        $this->chooseOnTheCartOf($order, $card);
        $client = new RecordingLyraClient($this->dispatcher());

        $payload = $this->provider($client)->forOrder($order);

        self::assertTrue($payload->available);
        self::assertSame(self::TOKEN, $client->sent[1]['paymentMethodToken'], 'The form token was asked with the card of the cart.');
        self::assertSame('PAYMENT', $client->sent[1]['formAction'], 'No card is registered again.');
        self::assertStringNotContainsString(self::TOKEN, json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', 'The token never goes to the browser.');
    }

    public function testWithoutAChosenCardTheFormIsOpenedOnANewCard(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $this->card($customer, self::TOKEN);
        $client = new RecordingLyraClient($this->dispatcher());

        $this->provider($client)->forOrder($order);

        self::assertArrayNotHasKey('paymentMethodToken', $client->sent[1], 'A card kept but not chosen is never sent.');
        self::assertSame('ASK_REGISTER_PAY', $client->sent[1]['formAction']);
    }

    public function testACardGivenIsNeverReplacedByTheOneOfTheCart(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $this->chooseOnTheCartOf($order, $this->card($customer, self::TOKEN));
        $given = $this->card($customer, 'given-token-0123456789abcdef0');
        $client = new RecordingLyraClient($this->dispatcher());

        $this->provider($client)->forOrder($order, $given);

        self::assertSame('given-token-0123456789abcdef0', $client->sent[1]['paymentMethodToken']);
    }

    public function testTheFormOfAnOrderIsAskedOncePerCardWhicheverOfThePayAndTheThemeAsksFirst(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $card = $this->card($customer, self::TOKEN);
        $this->chooseOnTheCartOf($order, $card);

        // The core pays with the card (payWithStoredMethod), then the theme asks for the form of the same order.
        $client = new RecordingLyraClient($this->dispatcher());
        $provider = $this->provider($client);
        $this->moduleWith($provider)->payWithStoredMethod($order, $card);
        $provider->forOrder($order);
        self::assertSame(1, $client->calls, 'One CreatePayment for the order and the card.');

        // The theme first, then the core.
        $other = new RecordingLyraClient($this->dispatcher());
        $provider = $this->provider($other);
        $provider->forOrder($order);
        $this->moduleWith($provider)->payWithStoredMethod($order, $card);
        self::assertSame(1, $other->calls);
    }

    /**
     * The hourly allowance of payments with a stored card is spent by the core when it pays, once per payment: opening a
     * form, as many times as a theme displays its page, spends nothing.
     */
    public function testOpeningTheFormSpendsNoSlotOfTheHourlyAllowance(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $this->chooseOnTheCartOf($order, $this->card($customer, self::TOKEN));
        $before = $this->slotsLeftOf($customer);

        // Distinct providers: nothing is held between them, each one opens a form.
        foreach ([1, 2, 3] as $ignored) {
            $this->provider(new RecordingLyraClient($this->dispatcher()))->forOrder($order);
        }

        self::assertSame($before, $this->slotsLeftOf($customer));
    }

    public function testTheFormIsNotOpenedOnAStoredCardOnceTheAllowanceIsSpentButIsUntilThen(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $this->chooseOnTheCartOf($order, $this->card($customer, self::TOKEN));
        $left = $this->slotsLeftOf($customer);
        $this->spendSlots($customer, $left - 1);

        $lastSlot = new RecordingLyraClient($this->dispatcher());
        $this->provider($lastSlot)->forOrder($order);
        self::assertSame(self::TOKEN, $lastSlot->sent[1]['paymentMethodToken'], 'A slot is left: the form opens on the card.');

        $this->spendSlots($customer, 1);

        $spent = new RecordingLyraClient($this->dispatcher());
        $this->provider($spent)->forOrder($order);
        self::assertArrayNotHasKey('paymentMethodToken', $spent->sent[1], 'No slot left: a new card, never the cap bypassed.');
        self::assertStringContainsString('allowance', RecordingTlogDestination::everythingAbout());
    }

    /**
     * The payment that spent the last slot is the one the form belongs to: the form already opened in the request
     * stays served, it is not replaced by a new card once the slot is spent.
     */
    public function testTheFormOpenedBeforeTheLastSlotWasSpentIsStillServed(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $card = $this->card($customer, self::TOKEN);
        $this->chooseOnTheCartOf($order, $card);
        $this->spendSlots($customer, $this->slotsLeftOf($customer) - 1);
        $client = new RecordingLyraClient($this->dispatcher());
        $provider = $this->provider($client);
        $this->moduleWith($provider)->payWithStoredMethod($order, $card);
        $this->spendSlots($customer, 1);

        $provider->forOrder($order);

        self::assertSame(1, $client->calls, 'The form of the paid order is served again, not asked for a new card.');
    }

    public function testACardOfAnotherSaleUnitChosenOnTheCartIsNotSent(): void
    {
        if (!PayzenEmbedded::supportsSaleUnits()) {
            self::markTestSkipped('This Thelia has no sale units.');
        }

        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $card = $this->card($customer, self::TOKEN, $this->factory->saleUnit()->getId());
        $this->chooseOnTheCartOf($order, $card);
        $client = new RecordingLyraClient($this->dispatcher());

        $this->provider($client)->forOrder($order);

        self::assertArrayNotHasKey('paymentMethodToken', $client->sent[1]);
    }

    public function testAGivenCardOfAnotherSaleUnitIsRefused(): void
    {
        if (!PayzenEmbedded::supportsSaleUnits()) {
            self::markTestSkipped('This Thelia has no sale units.');
        }

        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $card = $this->card($customer, self::TOKEN, $this->factory->saleUnit()->getId());
        $client = new RecordingLyraClient($this->dispatcher());

        try {
            $this->provider($client)->forOrder($order, $card);
            self::fail('A card of another sale unit must not open the form.');
        } catch (StoredMethodOfAnotherContractException) {
            self::assertNull($client->sent);
        }
    }

    public function testAnOrderThatAShopNoLongerOffersStoredMethodsForIsOpenedOnANewCard(): void
    {
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $this->chooseOnTheCartOf($order, $this->card($customer, self::TOKEN));
        $this->setModuleConfig('allow_one_click_payments', '0');
        $client = new RecordingLyraClient($this->dispatcher());

        $this->provider($client)->forOrder($order);

        self::assertArrayNotHasKey('paymentMethodToken', $client->sent[1]);
    }

    private function card(Customer $customer, string $token, ?int $saleUnitId = null): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($this->module->getId())
            ->setToken($token)
            ->setLabel('Visa •••• 4242')
            ->setIsDefault(false);
        $method->save($this->getPropelConnection());
        StoredMethodContract::link($method, $saleUnitId);

        return $method;
    }

    private function chooseOnTheCartOf(Order $order, CustomerPaymentMethod $card): void
    {
        CartQuery::create()->findPk($order->getCartId())
            ?->setCustomerPaymentMethodId($card->getId())
            ->save($this->getPropelConnection());
    }

    private function provider(RecordingLyraClient $client): CardFormProvider
    {
        return new class($this->dispatcher(), $this->getService(StoredPaymentMethodPayment::class), $client) extends CardFormProvider {
            public function __construct(EventDispatcherInterface $dispatcher, StoredPaymentMethodPayment $storedPayment, private readonly RecordingLyraClient $client)
            {
                parent::__construct($dispatcher, $storedPayment);
            }

            protected function createWrapper(): LyraJavascriptClientManagementWrapper
            {
                return $this->client;
            }
        };
    }

    private function moduleWith(CardFormProvider $provider): PayzenEmbedded
    {
        $container = new Container();
        $container->set(CardFormProvider::class, $provider);
        $module = new PayzenEmbedded();
        $module->setContainer($container);
        $module->setDispatcher($this->dispatcher());

        return $module;
    }

    private function limiter(): RateLimiterFactoryInterface
    {
        $property = new \ReflectionProperty(StoredPaymentMethodPayment::class, 'storedMethodPaymentLimiter');

        return $property->getValue($this->getService(StoredPaymentMethodPayment::class));
    }

    /**
     * Read without spending: asking for no token is always granted, what is left is the figure that tells.
     */
    private function slotsLeftOf(Customer $customer): int
    {
        return $this->limiter()->create('customer:' . (int) $customer->getId())->consume(0)->getRemainingTokens();
    }

    private function spendSlots(Customer $customer, int $count): void
    {
        for ($slot = 0; $slot < $count; ++$slot) {
            self::assertTrue($this->limiter()->create('customer:' . (int) $customer->getId())->consume()->isAccepted());
        }
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }
}
