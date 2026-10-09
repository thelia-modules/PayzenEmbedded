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

namespace PayzenEmbedded\Tests;

use Lyra\Exceptions\LyraException;
use PayzenEmbedded\LyraClient\LyraTransactionRefundWrapper;
use PayzenEmbedded\LyraClient\LyraTransactionUpdateWrapper;
use PayzenEmbedded\LyraClient\OrderStatusNotUpdatedException;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\RefundOutcomeUnknownException;
use PayzenEmbedded\LyraClient\RefundRecord;
use PayzenEmbedded\Service\DeferredCapture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Enum\RefundReason;
use Thelia\Domain\Payment\Exception\PaymentRefusedException;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\Currency;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransaction;

/**
 * The capture and the release Thelia asks of the module: a refusal of the platform is a refusal for
 * Thelia, which settles the line as failed; a call that got no answer is left to surface, and Thelia
 * keeps the line pending until the platform says.
 */
final class DeferredCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(LyraException::class)) {
            self::markTestSkipped('The Lyra SDK is not installed in the shop running the tests.');
        }
    }

    public function testTheCaptureValidatesTheAuthorizationAtTheAmountAskedAndWaitsForTheNotification(): void
    {
        $order = $this->order();
        $update = $this->updateWrapper(['status' => 'SUCCESS', 'answer' => ['uuid' => 'tx1', 'status' => 'PAID', 'detailedStatus' => 'AUTHORISED']]);
        $update->expects(self::once())->method('sendTransactionUpdateRequest')->with($order, 40.0, null, false);

        $result = (new DeferredCapture($update, $this->createMock(LyraTransactionRefundWrapper::class), $this->createMock(LoggerInterface::class)))->capture($order, 40.0);

        self::assertSame(PaymentTransactionState::PENDING, $result->state);
        self::assertSame('tx1', $result->pspReference);
    }

    public function testACaptureThePlatformRefusesIsARefusal(): void
    {
        $update = $this->updateWrapper(['status' => 'ERROR', 'answer' => ['errorCode' => 'PSP_100', 'errorMessage' => 'amount too high']]);

        $this->expectException(PaymentRefusedException::class);
        $this->expectExceptionMessage('amount too high');

        (new DeferredCapture($update, $this->createMock(LyraTransactionRefundWrapper::class), $this->createMock(LoggerInterface::class)))->capture($this->order(), 40.0);
    }

    public function testACaptureThePlatformAcceptedStaysAcceptedWhenRecordingItFails(): void
    {
        // The platform validated the authorization: the money will be taken, whatever failed after.
        $update = $this->updateWrapper(['status' => 'SUCCESS', 'answer' => ['uuid' => 'tx1']]);
        $update->method('processTransactionUpdateResponse')->willThrowException(new TheliaProcessException('The order could not be moved.'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('validated the capture'));

        $result = (new DeferredCapture($update, $this->createMock(LyraTransactionRefundWrapper::class), $logger))->capture($this->order(), 40.0);

        self::assertSame(PaymentTransactionState::PENDING, $result->state);
    }

    public function testACaptureThatGotNoAnswerIsLeftToSurface(): void
    {
        $update = $this->updateWrapper([]);
        $update->method('sendTransactionUpdateRequest')->willThrowException(new LyraException('timeout'));

        $this->expectException(LyraException::class);

        (new DeferredCapture($update, $this->createMock(LyraTransactionRefundWrapper::class), $this->createMock(LoggerInterface::class)))->capture($this->order(), 40.0);
    }

    public function testTheReleaseCancelsTheWholeAuthorization(): void
    {
        $order = $this->order();
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->expects(self::once())->method('refundTransaction')->with($order, 12000)->willReturn(RefundOutcome::Cancelled);

        $result = (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))->release($order, $this->line('120.000000'));

        self::assertSame(PaymentTransactionState::SUCCEEDED, $result->state);
        self::assertSame('tx1', $result->pspReference);
    }

    public function testAReleaseThePlatformRefusesIsARefusal(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundTransaction')->willThrowException(new TheliaProcessException('This order carries no PayZen transaction yet.'));

        $this->expectException(PaymentRefusedException::class);

        (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))->release($this->order(), $this->line('120.000000'));
    }

    public function testAReleaseWhoseOrderCouldNotFollowIsStillARelease(): void
    {
        // The platform cancelled the authorization; only moving the order failed.
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundTransaction')->willThrowException(new OrderStatusNotUpdatedException(RefundOutcome::Cancelled, 'The order could not be cancelled.'));

        $result = (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))->release($this->order(), $this->line('120.000000'));

        self::assertSame(PaymentTransactionState::SUCCEEDED, $result->state);
    }

    public function testAReleaseWithoutAnAnswerIsLeftToSurfaceNotARefusal(): void
    {
        // The platform may have cancelled the authorization: Thelia keeps the line pending.
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundTransaction')->willThrowException(new RefundOutcomeUnknownException('PayZen did not answer.'));

        $this->expectException(RefundOutcomeUnknownException::class);

        (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))->release($this->order(), $this->line('120.000000'));
    }

    public function testARefundGivesBackUnderTheReferenceOfThePlatformsRefund(): void
    {
        $order = $this->order();
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->expects(self::once())->method('refundRecorded')
            ->with($order, 3000, self::stringContains('Goods returned'))
            ->willReturn(new RefundRecord(RefundOutcome::PartiallyRefunded, 'credit-1'));

        $result = (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))
            ->refund($order, 30.0, RefundReason::Returned, 'Parcel came back');

        self::assertSame(PaymentTransactionState::SUCCEEDED, $result->state);
        self::assertSame('credit-1', $result->pspReference);
    }

    public function testARefundThePlatformIsStillProcessingWaitsForItsNotification(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willReturn(new RefundRecord(RefundOutcome::Pending, 'credit-2'));

        $result = (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))
            ->refund($this->order(), 30.0, RefundReason::Other, null);

        self::assertSame(PaymentTransactionState::PENDING, $result->state);
        self::assertSame('credit-2', $result->pspReference);
    }

    public function testARefundThePlatformRefusesIsARefusal(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException(new TheliaProcessException('Cannot refund the transaction. Error is : too late (code PSP_999)'));

        $this->expectException(PaymentRefusedException::class);

        (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))
            ->refund($this->order(), 30.0, RefundReason::Other, null);
    }

    public function testARefundThePlatformCouldNotBeAskedAboutIsARefusal(): void
    {
        // Listing the order's transactions comes before the refund is sent: nothing moved.
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException(new LyraException('Connection timed out'));

        $this->expectException(PaymentRefusedException::class);

        (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))
            ->refund($this->order(), 30.0, RefundReason::Other, null);
    }

    public function testAReleaseThePlatformCouldNotBeAskedAboutIsARefusal(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundTransaction')->willThrowException(new LyraException('Connection timed out'));

        $this->expectException(PaymentRefusedException::class);

        (new DeferredCapture($this->createMock(LyraTransactionUpdateWrapper::class), $refund, $this->createMock(LoggerInterface::class)))->release($this->order(), $this->line('120.000000'));
    }

    /**
     * @param array<string, mixed> $response what the platform answers the validation
     */
    private function updateWrapper(array $response): LyraTransactionUpdateWrapper&\PHPUnit\Framework\MockObject\MockObject
    {
        $update = $this->createMock(LyraTransactionUpdateWrapper::class);
        $update->method('withOrderLock')->willReturnCallback(static fn (Order $order, callable $work): mixed => $work());
        $update->method('sendTransactionUpdateRequest')->willReturn($response);

        return $update;
    }

    private function order(): Order
    {
        $currency = (new Currency())->setCode('EUR');
        $order = $this->createMock(Order::class);
        $order->method('getTransactionRef')->willReturn('tx1');
        $order->method('getCurrency')->willReturn($currency);

        return $order;
    }

    private function line(string $amount): OrderPaymentTransaction
    {
        return (new OrderPaymentTransaction())->setAmount($amount);
    }
}
