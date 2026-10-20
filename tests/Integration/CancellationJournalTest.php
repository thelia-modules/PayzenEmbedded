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

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\LyraClient\LyraPaymentManagementWrapper;
use PayzenEmbedded\LyraClient\LyraTransactionRefundWrapper;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;
use Thelia\Domain\Payment\Exception\PaymentJournalBusyException;
use Thelia\Domain\Payment\Service\PaymentTransactionRecorder;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransactionQuery;
use Thelia\Model\OrderStatus;
use Thelia\Module\BaseModule;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * Authorizing first, an authorization the platform cancelled reaches Thelia's journal before the
 * order is cancelled: the cancellation then finds nothing held, and asks the platform for nothing.
 * The order lock is held by the test as the operation that cancels holds it, so that a release
 * asked again fails at once instead of reaching the platform. Boots Thelia, see
 * phpunit.integration.xml.dist.
 */
final class CancellationJournalTest extends ActionIntegrationTestCase
{
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(LyraException::class)) {
            self::markTestSkipped('The Lyra SDK is not installed in the shop running the tests.');
        }

        (new \ReflectionProperty(BaseModule::class, 'moduleIds'))->setValue(null, []);
        $this->registerTheModule();
        PayzenEmbedded::setConfigValue('validation_mode', 'YES');
        $this->lockFactory = $this->getService(LockFactory::class);
    }

    public function testACancellationNotifiedByThePlatformIsWrittenBeforeTheOrderIsCancelled(): void
    {
        $order = $this->authorizedOrder();
        $wrapper = new LyraPaymentManagementWrapper($this->getService(EventDispatcherInterface::class), $this->lockFactory);

        $wrapper->withOrderLock($order, fn () => $wrapper->processPaymentResponse([
            'orderDetails' => ['orderId' => $order->getRef(), 'mode' => PayzenEmbedded::platformMode()],
            'transactions' => [$this->cancelled($order)],
        ]));

        $this->assertReleasedOnce($order);
    }

    public function testACancellationAskedFromTheOrderPageIsWrittenBeforeTheOrderIsCancelled(): void
    {
        $order = $this->authorizedOrder();
        $wrapper = new LyraTransactionRefundWrapper($this->getService(EventDispatcherInterface::class), $this->lockFactory);
        $process = new \ReflectionMethod(LyraTransactionRefundWrapper::class, 'processCancelOrRefundResponse');

        $wrapper->withOrderLock($order, fn () => $process->invoke($wrapper, $order, ['status' => 'SUCCESS', 'answer' => $this->cancelled($order)]));

        $this->assertReleasedOnce($order);
    }

    public function testARefundTheJournalCannotWriteYetIsStillARefund(): void
    {
        // The journal learns it from the platform's notification: the money moved all the same.
        $order = $this->authorizedOrder();
        $history = PayzenEmbeddedTransactionHistoryQuery::create()->findOneByUuid($order->getTransactionRef());
        $history->setStatus('PAID')->setDetailedstatus('CAPTURED')->setFinished(true)->save($this->getPropelConnection());
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        $busy = static function (): never {
            throw new PaymentJournalBusyException('Another worker holds the journal.');
        };
        $dispatcher->addListener(PayzenEmbedded::TRANSACTION_REPORTED_EVENT, $busy, 255);
        $wrapper = new LyraTransactionRefundWrapper($dispatcher, $this->lockFactory);
        $process = new \ReflectionMethod(LyraTransactionRefundWrapper::class, 'processCancelOrRefundResponse');

        try {
            $outcome = $process->invoke($wrapper, $order, ['status' => 'SUCCESS', 'answer' => [
                'uuid' => 'credit-'.$order->getId(),
                'status' => 'PAID',
                'operationType' => 'CREDIT',
                'amount' => 4000,
                'currency' => 'EUR',
                'creationDate' => (new \DateTimeImmutable('-1 minute'))->format(\DATE_ATOM),
                'transactionDetails' => ['parentTransactionUuid' => $order->getTransactionRef()],
            ]]);
        } finally {
            $dispatcher->removeListener(PayzenEmbedded::TRANSACTION_REPORTED_EVENT, $busy);
        }

        self::assertSame(RefundOutcome::PartiallyRefunded, $outcome);
    }

    private function assertReleasedOnce(Order $order): void
    {
        $voids = OrderPaymentTransactionQuery::create()->filterByOrderId((int) $order->getId())->filterByType(PaymentTransactionType::VOID->value)->find();
        self::assertCount(1, $voids, 'The release is written once, from the platform\'s word.');
        self::assertTrue($voids[0]->isSucceeded());

        $order->reload();
        self::assertSame(OrderStatus::CODE_CANCELED, (string) $order->getOrderStatus()->getCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelled(Order $order): array
    {
        return [
            'uuid' => $order->getTransactionRef(),
            'status' => 'UNPAID',
            'detailedStatus' => 'CANCELLED',
            'operationType' => 'DEBIT',
            'amount' => 12000,
            'currency' => 'EUR',
            'creationDate' => (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM),
            'metadata' => [TransactionOutcome::SHOP_MARKER_KEY => PayzenEmbedded::shopMarker()],
        ];
    }

    private function authorizedOrder(): Order
    {
        $order = $this->factory->order(null, ['postage' => 120, 'paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
        $uuid = 'tx-'.$order->getId();
        $order->setTransactionRef($uuid)->save($this->getPropelConnection());
        (new PayzenEmbeddedTransactionHistory())
            ->setCustomerId((int) $order->getCustomerId())
            ->setOrderId((int) $order->getId())
            ->setUuid($uuid)
            ->setStatus('RUNNING')
            ->setDetailedstatus('AUTHORISED_TO_VALIDATE')
            ->setOperationtype('DEBIT')
            ->setAmount(12000)
            ->setCurrencyId((int) $order->getCurrencyId())
            ->setCreationdate(new \DateTime('-2 hours'))
            ->setFinished(false)
            ->save($this->getPropelConnection());
        $this->getService(PaymentTransactionRecorder::class)->recordAuthorization($order, 120, $uuid, moduleCode: PayzenEmbedded::getModuleCode());
        $order->reload();

        return $order;
    }

    private function registerTheModule(): void
    {
        if (null !== ModuleQuery::create()->findOneByCode(PayzenEmbedded::getModuleCode())) {
            return;
        }

        (new Module())
            ->setCode(PayzenEmbedded::getModuleCode())
            ->setFullNamespace(PayzenEmbedded::class)
            ->setVersion('3.4.1')
            ->setType(BaseModule::PAYMENT_MODULE_TYPE)
            ->setCategory('payment')
            ->setActivate(BaseModule::IS_ACTIVATED)
            ->save($this->getPropelConnection());
    }
}
