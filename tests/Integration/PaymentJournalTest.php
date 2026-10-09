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

use PayzenEmbedded\Event\TransactionReportedEvent;
use PayzenEmbedded\EventListener\PaymentJournalListener;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\DeferredCapture;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;
use Thelia\Domain\Payment\Service\PaymentTransactionRecorder;
use Thelia\Domain\Payment\Service\PaymentTransactionTotalsReader;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransactionQuery;
use Thelia\Model\OrderStatus;
use Thelia\Module\BaseModule;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * What the platform reports, written to Thelia's payment journal when the shop authorizes first,
 * and how the order follows. Runs with the shop's test configuration, which boots Thelia:
 *     vendor/bin/phpunit -c phpunit.xml.dist local/modules/PayzenEmbedded/tests/Integration
 */
final class PaymentJournalTest extends ActionIntegrationTestCase
{
    private PaymentTransactionRecorder $recorder;

    private PaymentTransactionTotalsReader $totals;

    private PaymentJournalListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        // The module id is cached per process, and every test registers the module afresh.
        (new \ReflectionProperty(BaseModule::class, 'moduleIds'))->setValue(null, []);
        $this->registerTheModule();
        PayzenEmbedded::setConfigValue('validation_mode', 'YES');

        $this->recorder = $this->getService(PaymentTransactionRecorder::class);
        $this->totals = $this->getService(PaymentTransactionTotalsReader::class);
        $this->listener = new PaymentJournalListener($this->recorder, $this->totals);
    }

    public function testAnAuthorizationPutsTheOrderOnHoldForItsCapture(): void
    {
        $order = $this->order();

        $this->report($order, ['uuid' => 'tx1', 'status' => 'RUNNING', 'detailedStatus' => 'AUTHORISED_TO_VALIDATE', 'amount' => 12000]);
        $this->report($order, ['uuid' => 'tx1', 'status' => 'RUNNING', 'detailedStatus' => 'AUTHORISED_TO_VALIDATE', 'amount' => 12000]);

        $journal = OrderPaymentTransactionQuery::create()->findJournal((int) $order->getId());
        self::assertCount(1, $journal, 'A replayed notification writes nothing twice.');
        self::assertSame(PaymentTransactionType::AUTHORIZATION->value, $journal[0]->getType());
        self::assertSame('120.000000', $journal[0]->getAmount());
        self::assertSame('tx1', $journal[0]->getPspReference());
        self::assertSame(OrderStatus::CODE_AWAITING_CAPTURE, $this->statusOf($order));
    }

    public function testAPartialCaptureConfirmedByThePlatformPaysTheOrderAndReleasesTheRest(): void
    {
        $order = $this->authorizedOrder();
        // What Thelia wrote when the merchant asked to capture 40.00 and the module answered pending.
        $pending = $this->recorder->recordCapture($order, 40, null, PaymentTransactionState::PENDING);
        $this->recorder->attachReference($pending, 'tx1');

        $this->report($order, ['uuid' => 'tx1', 'status' => 'PAID', 'detailedStatus' => 'CAPTURED', 'amount' => 4000]);

        $pending->reload();
        self::assertTrue($pending->isSucceeded(), 'The notification settles the capture Thelia asked for.');
        $totals = $this->totals->forOrder((int) $order->getId());
        self::assertSame('40.000000', $totals->captured);
        self::assertSame('80.000000', $totals->voided, 'The platform let the rest go.');
        self::assertSame('0.000000', $totals->remainingToCapture);
        self::assertSame(OrderStatus::CODE_PAID, $this->statusOf($order));
    }

    public function testACancelledAuthorizationIsReleasedInTheJournal(): void
    {
        $order = $this->authorizedOrder();

        $this->report($order, ['uuid' => 'tx1', 'status' => 'UNPAID', 'detailedStatus' => 'CANCELLED', 'amount' => 12000]);

        self::assertSame('120.000000', $this->totals->forOrder((int) $order->getId())->voided);
    }

    public function testAnExpiredAuthorizationIsWrittenAsSuch(): void
    {
        $order = $this->authorizedOrder();

        $this->report($order, ['uuid' => 'tx1', 'status' => 'UNPAID', 'detailedStatus' => 'EXPIRED', 'amount' => 12000]);

        $expiry = OrderPaymentTransactionQuery::create()->findJournal((int) $order->getId())[0];
        self::assertSame(PaymentTransactionType::VOID->value, $expiry->getType());
        self::assertSame(PaymentTransactionRecorder::REASON_EXPIRED, $expiry->getErrorCode());
    }

    public function testARefusedAttemptIsWrittenWithTheBanksWords(): void
    {
        $order = $this->order();

        $this->report($order, ['uuid' => 'tx0', 'status' => 'UNPAID', 'detailedStatus' => 'REFUSED', 'amount' => 12000, 'detailedErrorCode' => '05', 'detailedErrorMessage' => 'Do not honor']);

        $line = OrderPaymentTransactionQuery::create()->findJournal((int) $order->getId())[0];
        self::assertTrue($line->isFailed());
        self::assertSame('05', $line->getErrorCode());
        self::assertSame('Do not honor', $line->getErrorMessage());
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusOf($order));
    }

    public function testTakingThePaymentAtOnceTheJournalIsLeftToThelia(): void
    {
        PayzenEmbedded::setConfigValue('validation_mode', 'NO');
        $order = $this->order();

        $this->report($order, ['uuid' => 'tx1', 'status' => 'RUNNING', 'detailedStatus' => 'AUTHORISED', 'amount' => 12000]);

        self::assertCount(0, OrderPaymentTransactionQuery::create()->findJournal((int) $order->getId()));
    }

    public function testARefundThePlatformWasStillProcessingIsSettledByItsNotification(): void
    {
        $order = $this->authorizedOrder();
        $this->report($order, ['uuid' => 'tx1', 'status' => 'PAID', 'detailedStatus' => 'CAPTURED', 'amount' => 12000]);
        // What Thelia wrote when PayZen answered the refund as still running.
        $pending = $this->recorder->recordRefund($order, 30, null, PaymentTransactionState::PENDING);
        $this->recorder->attachReference($pending, 'credit-9');

        $this->report($order, ['uuid' => 'credit-9', 'status' => 'PAID', 'amount' => 3000, 'operationType' => 'CREDIT']);

        $pending->reload();
        self::assertTrue($pending->isSucceeded());
        self::assertSame('30.000000', $this->totals->forOrder((int) $order->getId())->refunded);
    }

    public function testARefundMadeFromThePayZenBackOfficeIsWrittenEvenTakingThePaymentAtOnce(): void
    {
        PayzenEmbedded::setConfigValue('validation_mode', 'NO');
        $order = $this->order();
        $this->recorder->recordCapture($order, 120, 'tx1', moduleCode: PayzenEmbedded::getModuleCode());

        $this->report($order, ['uuid' => 'credit-7', 'status' => 'PAID', 'amount' => 2000, 'operationType' => 'CREDIT']);

        self::assertSame('20.000000', $this->totals->forOrder((int) $order->getId())->refunded);
    }

    public function testTheModuleReachesItsCaptureServiceFromTheContainer(): void
    {
        // The module class fetches it from the container, which only hands out public services.
        self::assertTrue(static::$kernel->getContainer()->has(DeferredCapture::class));
    }

    private function authorizedOrder(): Order
    {
        $order = $this->order();
        $this->report($order, ['uuid' => 'tx1', 'status' => 'RUNNING', 'detailedStatus' => 'AUTHORISED_TO_VALIDATE', 'amount' => 12000]);

        return $order;
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function report(Order $order, array $answer): void
    {
        $this->listener->onTransactionReported(new TransactionReportedEvent($order, $answer));
    }

    private function order(): Order
    {
        return $this->factory->order(null, ['postage' => 120, 'paymentModuleCode' => PayzenEmbedded::getModuleCode()]);
    }

    private function statusOf(Order $order): string
    {
        $order->reload();

        return (string) $order->getOrderStatus()->getCode();
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
