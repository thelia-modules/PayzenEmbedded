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
use PayzenEmbedded\LyraClient\IncompleteSaleUnitContractException;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\StoredMethodRecorder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\SaleUnit;

/**
 * A sale unit is paid on its own contract, whole, or on none: the national values never complete it.
 * Otherwise a unit with a key missing would be paid on the national contract, and two such units
 * would accept each other's notifications, both signed with the national key.
 */
final class SaleUnitContractTest extends PayzenIntegrationTestCase
{
    private const NATIONAL = [
        'mode' => 'TEST',
        'site_id' => '11111111',
        'webservice_endpoint' => 'https://national.example.com',
        'test_password' => 'national-test-password-0123456789',
        'javascript_test_key' => '11111111:national-test-public',
        'signature_test_key' => 'national-test-hmac',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!PayzenEmbedded::supportsSaleUnits()) {
            self::markTestSkipped('This Thelia has no sale units.');
        }

        SaleUnitConfigScope::reset();

        foreach (self::NATIONAL as $name => $value) {
            $this->setModuleConfig($name, $value);
        }
    }

    protected function tearDown(): void
    {
        SaleUnitConfigScope::reset();

        parent::tearDown();
    }

    public function testOutsideASaleUnitTheNationalContractIsRead(): void
    {
        $contract = PayzenEmbedded::paymentContract();

        self::assertSame('11111111', $contract->siteId);
        self::assertSame(self::NATIONAL['test_password'], $contract->password);
    }

    public function testASaleUnitWithAWholeContractIsPaidOnItsOwn(): void
    {
        $unit = $this->unitWithContract('22222222');

        $contract = PayzenEmbedded::runForSaleUnit($unit->getId(), static fn () => PayzenEmbedded::paymentContract());

        self::assertTrue($contract->test);
        self::assertSame('22222222', $contract->siteId);
        self::assertSame('22222222-test-password', $contract->password);
        self::assertSame('22222222:test-public', $contract->publicKey);
        self::assertSame('22222222-test-hmac', $contract->signatureKey);
        self::assertSame('https://national.example.com', $contract->endpoint, 'The address of the platform may be the national one.');
    }

    public function testASaleUnitThatLacksAKeyIsPaidOnNoContract(): void
    {
        $unit = $this->unitWithContract('22222222', without: 'signature_test_key');

        try {
            PayzenEmbedded::runForSaleUnit($unit->getId(), static fn () => PayzenEmbedded::paymentContract());
            self::fail('The national key completed the contract of the sale unit.');
        } catch (IncompleteSaleUnitContractException $refusal) {
            self::assertSame($unit->getId(), $refusal->saleUnitId);
            self::assertSame(['signature_test_key'], $refusal->missingSettings);
            self::assertStringNotContainsString('22222222-test', $refusal->getMessage(), 'The message names the settings, never a value.');
        }
    }

    public function testASaleUnitWithNoValueOfItsOwnIsNotPaidOnTheNationalContract(): void
    {
        $unit = $this->factory->saleUnit();

        $this->expectException(IncompleteSaleUnitContractException::class);

        PayzenEmbedded::runForSaleUnit($unit->getId(), static fn () => PayzenEmbedded::paymentContract());
    }

    public function testPayzenIsNotOfferedOnASaleUnitWhoseContractIsIncomplete(): void
    {
        $unit = $this->unitWithContract('22222222', without: 'test_password');

        self::assertFalse(PayzenEmbedded::runForSaleUnit($unit->getId(), static fn (): bool => (new PayzenEmbedded())->isValidPayment()));
    }

    public function testTheOrderOfASaleUnitWhoseContractIsIncompleteIsNotSentToThePlatform(): void
    {
        $unit = $this->unitWithContract('22222222', without: 'javascript_test_key');
        $client = new RecordingLyraClient($this->dispatcher());

        $result = $client->payOrder($this->orderOn($unit));

        self::assertSame(0, $client->calls, 'Nothing reached the platform.');
        self::assertFalse($result['success']);
        self::assertNotSame('', $result['errorMessage'], 'The customer is told to pay another way.');
        self::assertStringContainsString('javascript_test_key', RecordingTlogDestination::everythingAbout(), 'The log says what the contract lacks.');
    }

    public function testANotificationSignedWithTheNationalKeyIsRefusedOnTheOrderOfASaleUnitWhoseContractIsIncomplete(): void
    {
        $unit = $this->unitWithContract('22222222', without: 'test_password');
        $order = $this->orderOn($unit);

        $response = $this->ipn($this->notificationOfAPaymentOf($order, 10000), self::NATIONAL['test_password']);

        self::assertSame('KO', $response->getContent());
        self::assertFalse($this->reread($order)->isPaid(), 'The order is not paid.');
    }

    public function testANotificationSignedForOneSaleUnitIsRefusedOnTheOrderOfAnother(): void
    {
        $this->unitWithContract('22222222');
        $other = $this->unitWithContract('33333333');
        $order = $this->orderOn($other);
        $notification = PayzenEmbedded::runForSaleUnit($other->getId(), fn (): array => $this->notificationOfAPaymentOf($order, 10000));

        $response = $this->ipn($notification, '22222222-test-password');

        self::assertSame('KO', $response->getContent());
        self::assertFalse($this->reread($order)->isPaid());
    }

    public function testANotificationSignedWithTheKeyOfTheSaleUnitOfTheOrderIsAccepted(): void
    {
        $unit = $this->unitWithContract('22222222');
        $order = $this->orderOn($unit);
        $notification = PayzenEmbedded::runForSaleUnit($unit->getId(), fn (): array => $this->notificationOfAPaymentOf($order, 10000));

        $response = $this->ipn($notification, '22222222-test-password');

        self::assertSame('OK', $response->getContent());
        self::assertTrue($this->reread($order)->isPaid());
    }

    /**
     * A sale unit with a contract of its own in the TEST space, every key derived from its shop
     * identifier, but the one left out.
     */
    private function unitWithContract(string $siteId, ?string $without = null): SaleUnit
    {
        $unit = $this->factory->saleUnit();
        $settings = [
            'mode' => 'TEST',
            'site_id' => $siteId,
            'test_password' => $siteId . '-test-password',
            'javascript_test_key' => $siteId . ':test-public',
            'signature_test_key' => $siteId . '-test-hmac',
        ];

        foreach ($settings as $name => $value) {
            if ($name !== $without) {
                PayzenEmbedded::setConfigValueForSaleUnit($unit->getId(), $name, $value, 'mode' !== $name && 'site_id' !== $name);
            }
        }

        return $unit;
    }

    private function orderOn(SaleUnit $unit): Order
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $order->setSaleUnitId($unit->getId())->save($this->getPropelConnection());

        return $order;
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function ipn(array $answer, string $signedWith): Response
    {
        $raw = json_encode($answer, \JSON_THROW_ON_ERROR);
        $request = Request::create('/payzen-embedded/ipn-callback', 'POST', [
            'kr-answer' => $raw,
            'kr-hash' => hash_hmac('sha256', $raw, $signedWith),
            'kr-hash-algorithm' => 'sha256_hmac',
            'kr-hash-key' => 'password',
        ]);
        $stack = new RequestStack();
        $stack->push($request);

        $controller = new FrontController();
        $controller->requestStack = $stack;

        return $controller->processIpn(
            $this->dispatcher(),
            Translator::getInstance() ?? $this->getService(Translator::class),
            new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class)),
        );
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }

    private function reread(Order $order): Order
    {
        OrderTableMap::clearInstancePool();

        return OrderQuery::create()->findPk($order->getId()) ?? self::fail('The order is gone.');
    }
}
