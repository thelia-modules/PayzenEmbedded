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

use PayzenEmbedded\LyraClient\LyraPaymentManagementWrapper;
use PayzenEmbedded\Model\Map\PayzenEmbeddedTransactionHistoryTableMap;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\StoredMethodRecorder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;

/**
 * The notification of a payment, read by the module the way the platform sends it: what the module tells
 * the core about who confirms, and what the order's parts become.
 */
final class ConfirmationTest extends PayzenIntegrationTestCase
{
    /** @var list<array{?string, ?int}> the source and the status of every status change the core was asked for */
    private array $statusChanges = [];

    private \Closure $spy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spy = function (OrderEvent $event): void {
            $this->statusChanges[] = [$event->getSourceModuleCode(), $event->getStatus()];
        };
        $this->dispatcher()->addListener(TheliaEvents::ORDER_UPDATE_STATUS, $this->spy, 1000);
    }

    protected function tearDown(): void
    {
        $this->dispatcher()->removeListener(TheliaEvents::ORDER_UPDATE_STATUS, $this->spy);

        parent::tearDown();
    }

    public function testTheConfirmationNamesTheModuleAsItsSourceAndSettlesItsPart(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $this->part($order, $this->otherModule(), '30.000000', OrderPaymentPartState::SETTLED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::PENDING, true, 2);

        $status = $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 7000));

        self::assertSame(LyraPaymentManagementWrapper::PAYMENT_STATUS_PAID, $status);
        self::assertTrue($this->reread($order)->isPaid(false));
        self::assertSame(
            [OrderPaymentPartState::SETTLED->value, OrderPaymentPartState::SETTLED->value],
            $this->partStates($order),
            'The part PayZen holds is settled once PayZen confirms.',
        );
        self::assertContains(
            ['PayzenEmbedded', (int) \Thelia\Model\OrderStatusQuery::getPaidStatus()->getId()],
            $this->statusChanges,
            'The paid status is asked for with the module as its source.',
        );
        self::assertSame([], array_filter($this->statusChanges, static fn (array $change): bool => 'PayzenEmbedded' !== $change[0]), 'Every status change the module asks for names it.');
    }

    /**
     * The shop cancelled the order, the card was given back, and PayZen confirms a payment all the same: the
     * core keeps its part to be refunded, only for the module that holds it, and the module keeps what it needs to
     * refund it, then answers the platform so that it stops replaying.
     */
    public function testTheConfirmationOfACancelledOrderWhoseCardWasGivenBackIsKeptToBeRefunded(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer(), ['statusCode' => OrderStatus::CODE_CANCELED]);
        $this->part($order, $this->otherModule(), '30.000000', OrderPaymentPartState::RELEASED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::RELEASED, true, 2);
        $notification = $this->notificationOfAPaymentOf($order, 7000);
        $uuid = $notification['transactions'][0]['uuid'];

        $status = $this->wrapper()->processPaymentResponse($notification);

        self::assertSame(LyraPaymentManagementWrapper::PAYMENT_STATUS_PAID, $status, 'The platform is told the payment is taken: a refusal would have it replay the notification.');
        self::assertSame(
            [OrderPaymentPartState::RELEASED->value, OrderPaymentPartState::SETTLED->value],
            $this->partStates($order),
            'PayZen charged the 70.00: its part is settled again, to be refunded.',
        );
        self::assertCount(
            1,
            OrderHistoryQuery::create()->filterByOrderId($order->getId())->filterByEventType('payment_confirmed_after_release')->find($this->getPropelConnection()),
            'The history of the order says that a payment is to be refunded.',
        );
        self::assertTrue($this->reread($order)->isCancelled());
        self::assertSame($uuid, $this->reread($order)->getTransactionRef(), 'The reference of the payment is kept: the module refunds through it.');
        PayzenEmbeddedTransactionHistoryTableMap::clearInstancePool();
        $row = PayzenEmbeddedTransactionHistoryQuery::create()->filterByOrderId($order->getId())->filterByUuid($uuid)->findOne($this->getPropelConnection());
        self::assertNotNull($row, 'The PayZen history keeps the transaction.');
        self::assertSame('PAID', $row->getStatus());
        self::assertSame(1, $this->logLinesWith('Order ' . $order->getRef() . ': transaction ' . $uuid, 'paid after cancellation, to refund'), 'An error line names the order and says the payment is to be refunded.');
    }

    public function testTheReplayOfThatConfirmationIsAnsweredTheSameWayAndSaysNothingMore(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer(), ['statusCode' => OrderStatus::CODE_CANCELED]);
        $this->part($order, $this->otherModule(), '30.000000', OrderPaymentPartState::RELEASED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::RELEASED, true, 2);
        $notification = $this->notificationOfAPaymentOf($order, 7000);
        $this->wrapper()->processPaymentResponse($notification);
        RecordingTlogDestination::$lines = [];

        $status = $this->wrapper()->processPaymentResponse($notification);

        self::assertSame(LyraPaymentManagementWrapper::PAYMENT_STATUS_PAID, $status);
        self::assertSame(0, $this->logLinesWith('paid after cancellation'), 'The replay is not refused again, and writes no error.');
        self::assertTrue($this->reread($order)->isCancelled());
    }

    public function testARunningPaymentOnACancelledOrderIsKeptThenToo(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer(), ['statusCode' => OrderStatus::CODE_CANCELED]);
        $this->part($order, $this->otherModule(), '30.000000', OrderPaymentPartState::RELEASED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::RELEASED, true, 2);

        $status = $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 7000, ['status' => 'RUNNING', 'detailedStatus' => 'WAITING_AUTHORISATION']));

        self::assertSame(LyraPaymentManagementWrapper::PAYMENT_STATUS_IN_PROGRESS, $status);
        self::assertNotSame('', (string) $this->reread($order)->getTransactionRef());
        self::assertSame(1, $this->logLinesWith('authorised after cancellation, to cancel at the platform'));
    }

    private function logLinesWith(string ...$needles): int
    {
        return \count(array_filter(
            RecordingTlogDestination::$lines,
            static function (string $line) use ($needles): bool {
                foreach ($needles as $needle) {
                    if (!str_contains($line, $needle)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    public function testACardRegisteredWithThePaymentIsKeptAsAPaymentMethodOfTheCustomer(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $token = 'registered-token-0123456789abcdef';

        $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 10000, [
            'paymentMethodToken' => $token,
            'transactionDetails' => ['cardDetails' => ['pan' => '497010XXXXXX4242', 'effectiveBrand' => 'VISA', 'expiryMonth' => 2, 'expiryYear' => 2030]],
        ]));

        $methods = $this->getService(CustomerPaymentMethodService::class)->listFor($customer, $this->module);
        self::assertCount(1, $methods);
        self::assertSame($token, $methods[0]->getToken());
        self::assertSame('Visa •••• 4242', $methods[0]->getLabel());
        self::assertSame('2030-02-28', $methods[0]->getExpiresAt('Y-m-d'));
        self::assertTrue($methods[0]->getIsDefault());
        self::assertStringNotContainsString($token, RecordingTlogDestination::everythingAbout(), 'A token is written in no log.');
    }

    /**
     * The capture of an authorisation notifies the same transaction again, token included: a card the customer forgot
     * between the two is not brought back.
     */
    public function testTheCaptureOfAnAuthorisationDoesNotRegisterACardTheCustomerForgotMeanwhile(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $token = 'registered-token-fedcba9876543210';
        $running = $this->notificationOfAPaymentOf($order, 10000, ['status' => 'RUNNING', 'detailedStatus' => 'WAITING_AUTHORISATION', 'paymentMethodToken' => $token]);
        $uuid = $running['transactions'][0]['uuid'];
        $service = $this->getService(CustomerPaymentMethodService::class);

        $this->wrapper()->processPaymentResponse($running);
        $methods = $service->listFor($customer, $this->module);
        self::assertCount(1, $methods, 'The first notification of the transaction registers the card.');
        $methods[0]->delete($this->getPropelConnection());
        \Thelia\Model\Map\CustomerPaymentMethodTableMap::clearInstancePool();

        $paid = $this->notificationOfAPaymentOf($order, 10000, ['uuid' => $uuid, 'paymentMethodToken' => $token]);
        $status = $this->wrapper()->processPaymentResponse($paid);

        self::assertSame(LyraPaymentManagementWrapper::PAYMENT_STATUS_PAID, $status, 'The capture is applied.');
        self::assertSame([], $service->listFor($customer, $this->module), 'The card the customer forgot is not registered again.');
    }

    /**
     * A refresh of the transaction (Transaction/Get), an update or a capture reads the same answer, token included,
     * and never registers a card: only the notification or the answer of a payment does.
     */
    public function testARefreshOfTheTransactionRegistersNoCard(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $answer = $this->notificationOfAPaymentOf($order, 10000, ['paymentMethodToken' => 'token-seen-by-a-refresh'])['transactions'][0];
        $wrapper = new class($this->dispatcher(), null, new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class))) extends LyraPaymentManagementWrapper {
            public function refresh(Order $order, array $answer): int
            {
                return $this->processOrderStatus($order, $answer);
            }
        };

        $wrapper->refresh($order, $answer);

        self::assertSame([], $this->getService(CustomerPaymentMethodService::class)->listFor($customer, $this->module));
    }

    public function testTheCreditOfARefundRegistersNoCard(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $credit = $this->notificationOfAPaymentOf($order, 10000, ['operationType' => 'CREDIT', 'paymentMethodToken' => 'token-on-a-credit'])['transactions'][0];

        (new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class)))->recordFrom($order, $credit);

        self::assertSame([], $this->getService(CustomerPaymentMethodService::class)->listFor($customer, $this->module));
    }

    public function testTheSameCardRecordedTwiceIsKeptOnce(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);
        $notification = $this->notificationOfAPaymentOf($order, 10000, ['paymentMethodToken' => 'registered-token-replayed']);
        $recorder = new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class));

        $recorder->recordFrom($order, $notification['transactions'][0]);
        $recorder->recordFrom($order, $notification['transactions'][0]);

        self::assertCount(1, $this->getService(CustomerPaymentMethodService::class)->listFor($customer, $this->module));
    }

    public function testAPaymentPlatformRefusedRegistersNoCard(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);

        $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 10000, ['status' => 'UNPAID', 'paymentMethodToken' => 'refused-token-0123456789']));

        self::assertSame([], $this->getService(CustomerPaymentMethodService::class)->listFor($customer, $this->module));
    }

    public function testAShopThatStoppedOfferingStoredMethodsKeepsNoCardAndTheOrderIsPaid(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '0');
        $customer = $this->customer();
        $order = $this->orderWorth('100.000000', $customer);

        $status = $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 10000, ['paymentMethodToken' => 'token-of-a-closed-shop']));

        self::assertSame(LyraPaymentManagementWrapper::PAYMENT_STATUS_PAID, $status);
        self::assertSame([], $this->getService(CustomerPaymentMethodService::class)->listFor($customer, $this->module));
        self::assertStringContainsString('StoredMethodsNotSupportedException', RecordingTlogDestination::everythingAbout());
        self::assertStringNotContainsString('token-of-a-closed-shop', RecordingTlogDestination::everythingAbout());
    }

    public function testAGuestPaysAndKeepsNoCard(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $guest = $this->factory->guestCustomer($this->factory->customerTitle());
        $order = $this->orderWorth('100.000000', $guest);

        $status = $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 10000, ['paymentMethodToken' => 'token-of-a-guest']));

        self::assertSame(LyraPaymentManagementWrapper::PAYMENT_STATUS_PAID, $status);
        self::assertSame([], $this->getService(CustomerPaymentMethodService::class)->listFor($guest, $this->module));
        self::assertStringNotContainsString('token-of-a-guest', RecordingTlogDestination::everythingAbout());
    }

    private function wrapper(): LyraPaymentManagementWrapper
    {
        return new LyraPaymentManagementWrapper(
            $this->dispatcher(),
            null,
            new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class)),
        );
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }

    private function reread(Order $order): Order
    {
        \Thelia\Model\Map\OrderTableMap::clearInstancePool();

        return OrderQuery::create()->findPk($order->getId()) ?? self::fail('The order is gone.');
    }

    private function otherModule(): \Thelia\Model\Module
    {
        return ModuleQuery::create()->filterByCode('PayzenEmbedded', \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne()
            ?? self::fail('No other module in the test database.');
    }
}
