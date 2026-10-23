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
use PayzenEmbedded\Model\Map\PayzenEmbeddedTransactionHistoryTableMap;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\StoredMethodRecorder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;

/**
 * The notification URL, answered the way the platform reads it: a payment it took on an order the shop
 * refuses to pay is not a failure of the module, and the platform must not replay it.
 */
final class IpnTest extends PayzenIntegrationTestCase
{
    private const PASSWORD = 'test-ipn-password-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setModuleConfig('mode', 'TEST');
        $this->setModuleConfig('test_password', self::PASSWORD);
    }

    public function testAPaymentTakenOnAnOrderTheShopCancelledIsAnsweredOkAndKeptToBeRefunded(): void
    {
        [$order, $uuid, $notification] = $this->cancelledOrderPaid();

        $response = $this->ipn($notification);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
        self::assertSame($uuid, $this->reread($order)->getTransactionRef(), 'The reference of the payment is kept, to refund through it.');
        PayzenEmbeddedTransactionHistoryTableMap::clearInstancePool();
        self::assertNotNull(PayzenEmbeddedTransactionHistoryQuery::create()->filterByOrderId($order->getId())->filterByUuid($uuid)->findOne($this->getPropelConnection()), 'The history keeps the transaction.');
        self::assertTrue($this->reread($order)->isCancelled());
        self::assertStringContainsString('paid after cancellation, to refund', RecordingTlogDestination::everythingAbout());
        self::assertStringContainsString($order->getRef(), RecordingTlogDestination::everythingAbout());
    }

    public function testTheReplayOfThatNotificationIsAnsweredOkToo(): void
    {
        [, , $notification] = $this->cancelledOrderPaid();
        $this->ipn($notification);

        $response = $this->ipn($notification);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
    }

    public function testACardThatCannotBeKeptDoesNotMakeThePlatformReplayThePayment(): void
    {
        $this->setModuleConfig('allow_one_click_payments', '1');
        $order = $this->orderWorth('100.000000', $this->customer());
        $token = 'token-in-the-message-0123456789';
        $notification = $this->notificationOfAPaymentOf($order, 10000, ['paymentMethodToken' => $token]);
        $recorder = new class($this->getService(CustomerPaymentMethodService::class), $token) extends StoredMethodRecorder {
            public function __construct(CustomerPaymentMethodService $storedMethods, private readonly string $token)
            {
                parent::__construct($storedMethods);
            }

            public function recordFrom(Order $order, array $answer): void
            {
                throw new \RuntimeException('cannot keep ' . $this->token);
            }
        };

        $response = $this->ipn($notification, $recorder);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($this->reread($order)->isPaid(false), 'The order moved.');
        self::assertStringContainsString('RuntimeException', RecordingTlogDestination::everythingAbout(), 'The class of the failure is logged.');
        self::assertStringNotContainsString($token, RecordingTlogDestination::everythingAbout(), 'The message of the failure, which may quote the token, is not.');
    }

    public function testATransientFailureStaysAServerError(): void
    {
        [, , $notification] = $this->cancelledOrderPaid();
        // The lock of the order cannot be built: nothing here is a refusal of the shop.
        $failing = static function (): never {
            throw new \RuntimeException('database gone');
        };
        $this->dispatcher()->addListener(\Thelia\Core\Event\TheliaEvents::ORDER_UPDATE_TRANSACTION_REF, $failing, 1000);

        try {
            $response = $this->ipn($notification);
        } finally {
            $this->dispatcher()->removeListener(\Thelia\Core\Event\TheliaEvents::ORDER_UPDATE_TRANSACTION_REF, $failing);
        }

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    /**
     * The transition graph of the shop does not let an unpaid order be paid: the order is not cancelled, nothing is to
     * be refunded, and answering the platform OK would leave the payment taken and the order unpaid for good.
     */
    public function testARefusalOfTheTransitionGraphOnAnOrderThatIsNotCancelledStaysAServerError(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $notification = $this->notificationOfAPaymentOf($order, 10000);
        $this->allowOnly(OrderStatus::CODE_NOT_PAID, [OrderStatus::CODE_CANCELED]);

        $response = $this->ipn($notification);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertTrue($this->reread($order)->isNotPaid(), 'The order stays unpaid.');
        self::assertStringNotContainsString('after cancellation', RecordingTlogDestination::everythingAbout(), 'The payment is not said to be taken after a cancellation.');
    }

    /**
     * A notification replayed at the same time paid the order between the refusal and the answer.
     */
    public function testARefusalOnAnOrderThatIsPaidMeanwhileIsAnsweredOk(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $notification = $this->notificationOfAPaymentOf($order, 10000);
        $paidMeanwhile = function () use ($order): never {
            OrderQuery::create()->filterById($order->getId())->update(['StatusId' => (int) \Thelia\Model\OrderStatusQuery::getPaidStatus()->getId()], $this->getPropelConnection());

            throw new \Thelia\Domain\Order\Exception\OrderStatusTransitionRefusedException($order->getRef(), 'not_paid', 'paid');
        };
        $this->dispatcher()->addListener(\Thelia\Core\Event\TheliaEvents::ORDER_UPDATE_STATUS, $paidMeanwhile, 1000);

        try {
            $response = $this->ipn($notification);
        } finally {
            $this->dispatcher()->removeListener(\Thelia\Core\Event\TheliaEvents::ORDER_UPDATE_STATUS, $paidMeanwhile);
        }

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($this->reread($order)->isPaid(false));
        self::assertStringContainsString('paid again on an order already paid, to refund', RecordingTlogDestination::everythingAbout(), 'A double payment is not called a payment after a cancellation.');
        self::assertStringNotContainsString('after cancellation', RecordingTlogDestination::everythingAbout());
    }

    /**
     * An order the shop refunded meanwhile, and refused the status again: answered, not replayed forever.
     */
    public function testARefusalOnAnOrderThatIsRefundedMeanwhileIsAnsweredOk(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $notification = $this->notificationOfAPaymentOf($order, 10000);
        $refundedMeanwhile = function () use ($order): never {
            OrderQuery::create()->filterById($order->getId())->update(['StatusId' => (int) \Thelia\Model\OrderStatusQuery::getRefundedStatus()->getId()], $this->getPropelConnection());

            throw new \Thelia\Domain\Order\Exception\OrderStatusTransitionRefusedException($order->getRef(), 'not_paid', 'paid');
        };
        $this->dispatcher()->addListener(\Thelia\Core\Event\TheliaEvents::ORDER_UPDATE_STATUS, $refundedMeanwhile, 1000);

        try {
            $response = $this->ipn($notification);
        } finally {
            $this->dispatcher()->removeListener(\Thelia\Core\Event\TheliaEvents::ORDER_UPDATE_STATUS, $refundedMeanwhile);
        }

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('paid again on an order already refunded, to refund', RecordingTlogDestination::everythingAbout());
    }

    /**
     * @param list<string> $toCodes
     */
    private function allowOnly(string $fromCode, array $toCodes): void
    {
        $id = static fn (string $code): int => (int) (\Thelia\Model\OrderStatusQuery::create()->findOneByCode($code) ?? self::fail("Order status $code is missing."))->getId();

        $this->getService(\Thelia\Domain\Order\Service\OrderStatusTransitionWriter::class)->replaceTargets($id($fromCode), array_map($id, $toCodes));
    }

    /**
     * @return array{Order, string, array<string, mixed>}
     */
    private function cancelledOrderPaid(): array
    {
        $order = $this->orderWorth('100.000000', $this->customer(), ['statusCode' => OrderStatus::CODE_CANCELED]);
        $this->part($order, $this->otherModule(), '30.000000', OrderPaymentPartState::RELEASED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::RELEASED, true, 2);
        $notification = $this->notificationOfAPaymentOf($order, 7000);

        return [$order, $notification['transactions'][0]['uuid'], $notification];
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function ipn(array $answer, ?StoredMethodRecorder $recorder = null): Response
    {
        $raw = json_encode($answer, \JSON_THROW_ON_ERROR);
        $request = Request::create('/payzen-embedded/ipn-callback', 'POST', [
            'kr-answer' => $raw,
            'kr-hash' => hash_hmac('sha256', $raw, self::PASSWORD),
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
            $recorder ?? new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class)),
        );
    }

    protected function tearDown(): void
    {
        // The rows go back with the transaction of the test; the graph built from them stays in the service.
        $this->getService(\Thelia\Domain\Order\Service\OrderStatusTransitionGraphProvider::class)->reset();

        parent::tearDown();
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

    private function otherModule(): \Thelia\Model\Module
    {
        return \Thelia\Model\ModuleQuery::create()->filterByCode('PayzenEmbedded', \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne()
            ?? self::fail('No other module in the test database.');
    }
}
