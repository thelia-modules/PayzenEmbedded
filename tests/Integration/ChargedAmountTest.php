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
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Model\Customer;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;

/**
 * What the module asks PayZen to charge, and with which card. The platform is replaced by a client that
 * keeps the request it was sent: nothing here reaches a network.
 */
final class ChargedAmountTest extends PayzenIntegrationTestCase
{
    private const STORED_TOKEN = 'stored-token-0123456789abcdef';

    public function testAnOrderPaidWithNothingElseIsChargedItsTotal(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());

        $sent = $this->createPayment($order)->sent();

        self::assertSame('V4/Charge/CreatePayment', $sent[0]);
        self::assertSame(10000, $sent[1]['amount']);
    }

    public function testAnOrderPartlyPaidWithAnInstantMeansIsChargedTheBalanceOfItsOwnPart(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $this->part($order, $this->otherModule(), '30.000000', OrderPaymentPartState::SETTLED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::PENDING, true, 2);

        $sent = $this->createPayment($order)->sent();

        self::assertSame(7000, $sent[1]['amount'], 'The gift card paid 30.00: PayZen charges the 70.00 left, never the 100.00 of the order.');
    }

    public function testACardOfTheCustomerIsNeverSentWhenTheyPayWithANewCard(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $this->storedMethod($customer);
        $order = $this->orderWorth('100.000000', $customer);

        $sent = $this->createPayment($order)->sent();

        self::assertArrayNotHasKey('paymentMethodToken', $sent[1], 'A customer who wants a new card is not sent the one they stored.');
        self::assertSame('ASK_REGISTER_PAY', $sent[1]['formAction'], 'The new card may be registered.');
    }

    public function testAStoredCardPaysWithItsTokenAndRegistersNothingAgain(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $method = $this->storedMethod($customer);
        $order = $this->orderWorth('100.000000', $customer);

        $sent = $this->createPayment($order, $method)->sent();

        self::assertSame(self::STORED_TOKEN, $sent[1]['paymentMethodToken']);
        self::assertSame('PAYMENT', $sent[1]['formAction']);
        self::assertSame(10000, $sent[1]['amount']);
    }

    public function testAStoredCardPaysTheBalanceOfAnOrderPartlyPaidElsewhere(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $method = $this->storedMethod($customer);
        $order = $this->orderWorth('100.000000', $customer);
        $this->part($order, $this->otherModule(), '45.500000', OrderPaymentPartState::SETTLED, false, 1);
        $this->part($order, $this->module, '54.500000', OrderPaymentPartState::PENDING, true, 2);

        $sent = $this->createPayment($order, $method)->sent();

        self::assertSame(5450, $sent[1]['amount']);
    }

    public function testAnErrorOfThePlatformNeverQuotesTheTokenInTheAnswerNorInTheLog(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $method = $this->storedMethod($customer);
        $order = $this->orderWorth('100.000000', $customer);

        $result = $this->createPayment($order, $method, [
            'status' => 'ERROR',
            'answer' => [
                'errorCode' => 'PSP_999',
                'errorMessage' => 'unknown paymentMethodToken ' . self::STORED_TOKEN,
                'detailedErrorCode' => 'TOKEN',
                'detailedErrorMessage' => 'the token ' . self::STORED_TOKEN . ' was cancelled',
            ],
        ])->result();

        self::assertFalse($result['success']);
        self::assertSame('PSP_999', $result['errorCode']);
        self::assertStringNotContainsString(self::STORED_TOKEN, json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('[token]', $result['errorMessage']);
        self::assertStringNotContainsString(self::STORED_TOKEN, RecordingTlogDestination::everythingAbout());
        self::assertStringContainsString('PSP_999', RecordingTlogDestination::everythingAbout(), 'The error is logged, minus the token.');
    }

    public function testAFailureOfTheClientNeverQuotesTheTokenEither(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $method = $this->storedMethod($customer);
        $order = $this->orderWorth('100.000000', $customer);

        $result = $this->createPayment($order, $method, new \RuntimeException('call failed, response {"paymentMethodToken":"' . self::STORED_TOKEN . '"}'))->result();

        self::assertFalse($result['success']);
        self::assertStringNotContainsString(self::STORED_TOKEN, json_encode($result, \JSON_THROW_ON_ERROR));
    }

    private function otherModule(): \Thelia\Model\Module
    {
        return ModuleQuery::create()->filterByCode('PayzenEmbedded', \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne()
            ?? self::fail('No other module in the test database.');
    }

    private function storedMethod(Customer $customer): CustomerPaymentMethod
    {
        $method = (new CustomerPaymentMethod())
            ->setCustomerId($customer->getId())
            ->setPaymentModuleId($this->module->getId())
            ->setToken(self::STORED_TOKEN)
            ->setLabel('Visa •••• 4242')
            ->setIsDefault(true);
        $method->save($this->getPropelConnection());

        return $method;
    }

    /**
     * @param array<string, mixed>|\Throwable|null $answer what the platform answers, a form token when null
     */
    private function createPayment(Order $order, ?CustomerPaymentMethod $method = null, array|\Throwable|null $answer = null): PaymentAttempt
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');

        $client = new RecordingLyraClient($dispatcher);
        $client->answer = $answer;

        return new PaymentAttempt($client, $client->payOrder($order, $method));
    }
}

/**
 * @internal
 */
final class PaymentAttempt
{
    public function __construct(
        private readonly RecordingLyraClient $client,
        private readonly array $result,
    ) {
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    public function sent(): array
    {
        return $this->client->sent ?? throw new \LogicException('Nothing was sent to the platform.');
    }

    public function result(): array
    {
        return $this->result;
    }
}
