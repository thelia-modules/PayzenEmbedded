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

use PayzenEmbedded\Model\PayzenEmbeddedStoredMethodContractQuery;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\CardFormProvider;
use PayzenEmbedded\Service\StoredMethodContract;
use PayzenEmbedded\Service\StoredMethodOfAnotherContractException;
use PayzenEmbedded\Service\StoredMethodRecorder;
use PayzenEmbedded\Service\StoredMethodRevoker;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Bridge\Propel\Event\PaymentModuleOptionEvent;
use Thelia\Api\Resource\PaymentModuleOptionGroup;
use Thelia\Api\Resource\PaymentModuleStoredMethodOption;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\StoredPaymentMethodPayment;
use Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\CustomerPaymentMethodQuery;
use Thelia\Model\Order;
use Thelia\Model\SaleUnit;

/**
 * A card is good on the PayZen contract it was registered on, the contract of the sale unit of the order that
 * registered it (national: no sale unit). It is registered, offered, paid with and cancelled there, and nowhere else.
 * The platform is replaced everywhere: nothing here reaches a network.
 */
final class StoredMethodContractTest extends PayzenIntegrationTestCase
{
    private const TOKEN = 'unit-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        if (!PayzenEmbedded::supportsSaleUnits()) {
            self::markTestSkipped('This Thelia has no sale units.');
        }

        $this->setModuleConfig('allow_one_click_payments', '1');
        $this->setModuleConfig('popup_mode', '1');
        SaleUnitConfigScope::reset();
    }

    protected function tearDown(): void
    {
        SaleUnitConfigScope::reset();

        parent::tearDown();
    }

    public function testACardIsTiedToTheSaleUnitOfTheOrderThatRegisteredIt(): void
    {
        $customer = $this->customer();
        $unit = $this->factory->saleUnit();

        $card = $this->registeredBy($this->orderOnUnit($customer, $unit), self::TOKEN);

        $link = PayzenEmbeddedStoredMethodContractQuery::create()->findPk($card->getId());
        self::assertNotNull($link, 'The card is linked to a contract.');
        self::assertSame($unit->getId(), $link->getSaleUnitId());
    }

    public function testACardRegisteredOnANationalOrderIsTiedToTheNationalContract(): void
    {
        $card = $this->registeredBy($this->orderOnUnit($this->customer(), null), self::TOKEN);

        $link = PayzenEmbeddedStoredMethodContractQuery::create()->findPk($card->getId());
        self::assertNotNull($link, 'A national card is linked too: a card with no link is of an unknown contract.');
        self::assertNull($link->getSaleUnitId());
    }

    public function testACardAlreadyKeptKeepsItsLinkWhenAnotherSaleUnitHandsItsTokenBack(): void
    {
        $customer = $this->customer();
        $first = $this->factory->saleUnit();
        $second = $this->factory->saleUnit();

        $this->registeredBy($this->orderOnUnit($customer, $first), self::TOKEN);
        $card = $this->registeredBy($this->orderOnUnit($customer, $second), self::TOKEN);

        self::assertSame($first->getId(), PayzenEmbeddedStoredMethodContractQuery::create()->findPk($card->getId())?->getSaleUnitId());
        self::assertSame(1, CustomerPaymentMethodQuery::create()->filterByCustomerId($customer->getId())->count(), 'The card is kept once.');
    }

    public function testACardIsCancelledOnTheContractOfItsSaleUnitWhateverTheRequestResolved(): void
    {
        $unit = $this->factory->saleUnit();
        $otherUnit = $this->factory->saleUnit();
        $card = $this->card($this->customer(), $unit->getId());
        $gateway = new RecordingTokenGateway();
        SaleUnitConfigScope::set($otherUnit->getId());

        $this->moduleForgettingWith($gateway)->forgetStoredMethod($card);

        self::assertSame([self::TOKEN], $gateway->cancelled);
        self::assertSame([$unit->getId()], $gateway->contractsOfTheCancellations, 'PayZen was asked on the contract of the unit of the card.');
        self::assertSame($otherUnit->getId(), SaleUnitConfigScope::current(), 'The scope of the request is given back.');
    }

    public function testANationalCardIsCancelledOnTheNationalContractWhateverTheRequestResolved(): void
    {
        $card = $this->card($this->customer(), null);
        $gateway = new RecordingTokenGateway();
        SaleUnitConfigScope::set($this->factory->saleUnit()->getId());

        $this->moduleForgettingWith($gateway)->forgetStoredMethod($card);

        self::assertSame([null], $gateway->contractsOfTheCancellations);
    }

    public function testACardOfAnotherSaleUnitNeverPaysTheOrder(): void
    {
        $customer = $this->customer();
        $card = $this->card($customer, $this->factory->saleUnit()->getId());
        $order = $this->orderOnUnit($customer, $this->factory->saleUnit());
        $client = new RecordingLyraClient($this->dispatcher());

        try {
            $this->moduleServing($client)->payWithStoredMethod($order, $card);
            self::fail('A card registered on another sale unit must not pay.');
        } catch (StoredMethodOfAnotherContractException $refusal) {
            self::assertStringContainsString('another sale unit', $refusal->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $refusal);
            self::assertInstanceOf(\Thelia\Domain\Payment\Exception\CustomerPaymentMethodException::class, $refusal, 'The core answers it as a refusal about a stored method.');
        }

        self::assertNull($client->sent, 'The platform was not asked.');
    }

    public function testACardOfASaleUnitPaysTheOrdersOfThatSaleUnit(): void
    {
        $customer = $this->customer();
        $unit = $this->factory->saleUnit();
        $card = $this->card($customer, $unit->getId());
        $client = new RecordingLyraClient($this->dispatcher());

        $this->moduleServing($client)->payWithStoredMethod($this->orderOnUnit($customer, $unit), $card);

        self::assertSame(self::TOKEN, $client->sent[1]['paymentMethodToken']);
    }

    /**
     * A sale unit that leaves its contract empty reads the national one, but a unit may set any of the settings of a
     * contract (shop identifier, mode, endpoint, keys) and the module does not compare contracts: the national card
     * is the national orders', and a unit's card its own unit's.
     */
    public function testANationalCardPaysNationalOrdersOnlyAndASaleUnitCardNeverPaysANationalOrder(): void
    {
        $customer = $this->customer();
        $unit = $this->factory->saleUnit();
        $national = $this->card($customer, null, 'national-token-0123456789');
        $ofTheUnit = $this->card($customer, $unit->getId(), 'unit-only-token-0123456789');

        $client = new RecordingLyraClient($this->dispatcher());
        $this->moduleServing($client)->payWithStoredMethod($this->orderOnUnit($customer, null), $national);
        self::assertSame('national-token-0123456789', $client->sent[1]['paymentMethodToken']);

        foreach ([[$this->orderOnUnit($customer, $unit), $national], [$this->orderOnUnit($customer, null), $ofTheUnit]] as [$order, $card]) {
            $refused = new RecordingLyraClient($this->dispatcher());

            try {
                $this->moduleServing($refused)->payWithStoredMethod($order, $card);
                self::fail('The contract of the card is not the one of the order.');
            } catch (StoredMethodOfAnotherContractException) {
                self::assertNull($refused->sent);
            }
        }
    }

    public function testACardWithNoLinkIsOfAnUnknownContractAndPaysNowhere(): void
    {
        $customer = $this->customer();
        $card = $this->card($customer, null, linked: false);
        $client = new RecordingLyraClient($this->dispatcher());

        try {
            $this->moduleServing($client)->payWithStoredMethod($this->orderOnUnit($customer, null), $card);
            self::fail('A card whose contract is unknown must not pay.');
        } catch (StoredMethodOfAnotherContractException) {
            self::assertNull($client->sent);
        }
    }

    public function testACardWithNoLinkIsStillCancelledOnTheNationalContract(): void
    {
        $card = $this->card($this->customer(), null, linked: false);
        $gateway = new RecordingTokenGateway();
        SaleUnitConfigScope::set($this->factory->saleUnit()->getId());

        $this->moduleForgettingWith($gateway)->forgetStoredMethod($card);

        self::assertSame([null], $gateway->contractsOfTheCancellations);
    }

    public function testTheLinkGoesWithTheCard(): void
    {
        $card = $this->card($this->customer(), $this->factory->saleUnit()->getId());
        self::assertNotNull(PayzenEmbeddedStoredMethodContractQuery::create()->findPk($card->getId()));

        $card->delete($this->getPropelConnection());

        self::assertNull(PayzenEmbeddedStoredMethodContractQuery::create()->findPk($card->getId()), 'The foreign key cascades.');
    }

    public function testTheCheckoutOffersTheCardsOfTheSaleUnitOfTheCartOnly(): void
    {
        $customer = $this->customer();
        $unit = $this->factory->saleUnit();
        $otherUnit = $this->factory->saleUnit();
        $national = $this->card($customer, null, 'national-token-0123456789');
        $ofTheUnit = $this->card($customer, $unit->getId(), 'unit-token-0123456789ab');
        $ofTheOtherUnit = $this->card($customer, $otherUnit->getId(), 'other-unit-token-012345');

        self::assertSame([$ofTheUnit->getId()], $this->offeredCardsOnCartOf($customer, $unit));
        self::assertSame([$ofTheOtherUnit->getId()], $this->offeredCardsOnCartOf($customer, $otherUnit));
        self::assertSame([$national->getId()], $this->offeredCardsOnCartOf($customer, null));
    }

    public function testTheCheckoutOffersNoSavedCardsWhenNoneIsGoodOnTheSaleUnitOfTheCart(): void
    {
        $customer = $this->customer();
        $this->card($customer, $this->factory->saleUnit()->getId());

        $groups = $this->optionGroupsOnCartOf($customer, $this->factory->saleUnit());

        self::assertSame([], $groups, 'Not even the option of a new card: the buyer has nothing to choose between.');
    }

    public function testTheCheckoutOfAnotherPaymentModuleIsLeftAlone(): void
    {
        $customer = $this->customer();
        $other = $this->otherModule();
        $card = $this->card($customer, $this->factory->saleUnit()->getId(), through: $other, linked: false);
        $cart = $this->factory->cart($customer);
        $event = new PaymentModuleOptionEvent($other, $cart);
        $event->appendPaymentModuleOptionGroups(
            (new PaymentModuleOptionGroup())->setCode('stored_methods')->setTitle('Saved')->appendPaymentModuleOption(PaymentModuleStoredMethodOption::ofStoredMethod($card))
        );

        $this->dispatcher()->dispatch($event, TheliaEvents::MODULE_PAYMENT_GET_OPTIONS);

        self::assertCount(1, $event->getPaymentModuleOptionGroups());
        self::assertCount(1, $event->getPaymentModuleOptionGroups()[0]->getOptions());
    }

    public function testTheCheckoutNoticeIsShownForACardThatCanPayOnTheSaleUnitOnly(): void
    {
        $customer = $this->customer();
        $unit = $this->factory->saleUnit();
        $this->card($customer, $unit->getId());

        self::assertTrue(StoredMethodContract::customerHasACardFor((int) $customer->getId(), (int) $this->module->getId(), $unit->getId()));
        self::assertFalse(StoredMethodContract::customerHasACardFor((int) $customer->getId(), (int) $this->module->getId(), null));
        self::assertFalse(StoredMethodContract::customerHasACardFor((int) $customer->getId(), (int) $this->module->getId(), $this->factory->saleUnit()->getId()));
    }

    /**
     * @return list<int> the ids of the cards offered to the customer on a cart of the sale unit
     */
    private function offeredCardsOnCartOf(Customer $customer, ?SaleUnit $unit): array
    {
        $groups = $this->optionGroupsOnCartOf($customer, $unit);
        self::assertCount(1, $groups);
        $ids = [];

        foreach ($groups[0]->getOptions() as $option) {
            self::assertInstanceOf(PaymentModuleStoredMethodOption::class, $option);

            if (null !== $option->getCustomerPaymentMethodId()) {
                $ids[] = $option->getCustomerPaymentMethodId();
            }
        }

        return $ids;
    }

    /**
     * @return list<PaymentModuleOptionGroup>
     */
    private function optionGroupsOnCartOf(Customer $customer, ?SaleUnit $unit): array
    {
        $cart = $this->factory->cart($customer);
        $cart->setSaleUnitId($unit?->getId())->save($this->getPropelConnection());
        $event = new PaymentModuleOptionEvent($this->module, $cart);

        $this->dispatcher()->dispatch($event, TheliaEvents::MODULE_PAYMENT_GET_OPTIONS);

        return array_values($event->getPaymentModuleOptionGroups());
    }

    private function orderOnUnit(Customer $customer, ?SaleUnit $unit): Order
    {
        $order = $this->orderWorth('100.000000', $customer);
        $order->setSaleUnitId($unit?->getId())->save($this->getPropelConnection());

        return $order;
    }

    /**
     * A card registered by the payment of the order, as the notification of PayZen registers it.
     */
    private function registeredBy(Order $order, string $token): CustomerPaymentMethod
    {
        $answer = $this->notificationOfAPaymentOf($order, 10000, ['paymentMethodToken' => $token])['transactions'][0];

        (new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class)))->recordFrom($order, $answer);

        \Thelia\Model\Map\CustomerPaymentMethodTableMap::clearInstancePool();

        return CustomerPaymentMethodQuery::create()->filterByCustomerId($order->getCustomerId())->filterByToken($token)->findOne()
            ?? self::fail('The card was not kept.');
    }

    private function card(Customer $customer, ?int $saleUnitId, string $token = self::TOKEN, ?\Thelia\Model\Module $through = null, bool $linked = true): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId(($through ?? $this->module)->getId())
            ->setToken($token)
            ->setLabel('Visa •••• 4242')
            ->setIsDefault(false);
        $method->save($this->getPropelConnection());

        if ($linked) {
            StoredMethodContract::link($method, $saleUnitId);
        }

        return $method;
    }

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

    private function moduleForgettingWith(RecordingTokenGateway $gateway): PayzenEmbedded
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

    private function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }

    private function otherModule(): \Thelia\Model\Module
    {
        return \Thelia\Model\ModuleQuery::create()->findOneByCode('Cheque')
            ?? self::fail('No other payment module in the test database.');
    }
}
