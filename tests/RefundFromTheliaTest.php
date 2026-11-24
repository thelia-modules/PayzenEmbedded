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
use PayzenEmbedded\LyraClient\OrderStatusNotUpdatedException;
use PayzenEmbedded\LyraClient\PlatformRefusalException;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\RefundOutcomeUnknownException;
use PayzenEmbedded\LyraClient\RefundRecord;
use PayzenEmbedded\Service\RefundFromThelia;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Thelia\Domain\Payment\Enum\PaymentTransactionState;
use Thelia\Domain\Payment\Enum\RefundReason;
use Thelia\Domain\Payment\Exception\PaymentRefusedException;
use Thelia\Exception\TheliaProcessException;
use Thelia\Model\Currency;
use Thelia\Model\Order;

/**
 * The refund Thelia asks of the module: a refusal of the platform is a refusal for Thelia, which
 * settles the line as failed, with the code the platform answered; a call that got no answer is
 * left to surface, and Thelia keeps the line pending until the platform says.
 */
final class RefundFromTheliaTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(LyraException::class)) {
            self::markTestSkipped('The Lyra SDK is not installed in the shop running the tests.');
        }
    }

    public function testAPartialRefundIsSentInMinorUnitsAndGivenBackUnderTheReferenceOfThePlatformsRefund(): void
    {
        $order = $this->order();
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->expects(self::once())->method('refundRecorded')
            ->with($order, 3000, self::stringContains('Goods returned'))
            ->willReturn(new RefundRecord(RefundOutcome::PartiallyRefunded, 'credit-1'));

        $result = $this->service($refund)->refund($order, 30.0, RefundReason::Returned, 'Parcel came back');

        self::assertSame(PaymentTransactionState::SUCCEEDED, $result->state);
        self::assertSame('credit-1', $result->pspReference);
    }

    public function testTheReasonThenTheCommentOfTheMerchantAreWrittenBesideTheRefund(): void
    {
        $order = $this->order();
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->expects(self::once())->method('refundRecorded')
            ->with($order, self::anything(), 'Goods returned: Parcel came back')
            ->willReturn(new RefundRecord(RefundOutcome::PartiallyRefunded, 'credit-1'));

        $this->service($refund)->refund($order, 30.0, RefundReason::Returned, 'Parcel came back');
    }

    public function testAFullRefundIsSentForTheWholeAmountInMinorUnits(): void
    {
        $order = $this->order();
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->expects(self::once())->method('refundRecorded')
            ->with($order, 10000, 'Goods returned')
            ->willReturn(new RefundRecord(RefundOutcome::Refunded, 'credit-3'));

        $result = $this->service($refund)->refund($order, 100.0, RefundReason::Returned, null);

        self::assertSame(PaymentTransactionState::SUCCEEDED, $result->state);
        self::assertSame('credit-3', $result->pspReference);
    }

    public function testAPaymentCancelledBeforeItsRemittanceIsGivenBackUnderTheReferenceOfTheCancelledTransaction(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willReturn(new RefundRecord(RefundOutcome::Cancelled, 'tx1'));

        $result = $this->service($refund)->refund($this->order(), 100.0, RefundReason::Cancellation, null);

        self::assertSame(PaymentTransactionState::SUCCEEDED, $result->state);
        self::assertSame('tx1', $result->pspReference);
    }

    public function testARefundSentWithoutAnAnswerIsLeftToSurfaceNotARefusal(): void
    {
        // The platform may have given the money back: Thelia keeps the line pending.
        $unknown = new RefundOutcomeUnknownException('PayZen did not answer.');
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException($unknown);

        $thrown = null;

        try {
            $this->service($refund)->refund($this->order(), 30.0, RefundReason::Other, null);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertSame($unknown, $thrown);
        self::assertNotInstanceOf(PaymentRefusedException::class, $thrown);
    }

    public function testARefundThePlatformIsStillProcessingWaitsForItsNotification(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willReturn(new RefundRecord(RefundOutcome::Pending, 'credit-2'));

        $result = $this->service($refund)->refund($this->order(), 30.0, RefundReason::Other, null);

        self::assertSame(PaymentTransactionState::PENDING, $result->state);
        self::assertSame('credit-2', $result->pspReference);
    }

    public function testARefundWhoseMoneyMovedButWhoseOrderCouldNotFollowIsStillARefund(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException(new OrderStatusNotUpdatedException(RefundOutcome::Refunded, 'The order status could not be updated.', null, 'credit-4'));

        $result = $this->service($refund)->refund($this->order(), 100.0, RefundReason::Other, null);

        self::assertSame(PaymentTransactionState::SUCCEEDED, $result->state);
        self::assertSame('credit-4', $result->pspReference);
    }

    public function testARefundThePlatformRefusesIsARefusal(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException(new TheliaProcessException('Cannot refund the transaction. Error is : too late (code PSP_999)'));

        $this->expectException(PaymentRefusedException::class);

        $this->service($refund)->refund($this->order(), 30.0, RefundReason::Other, null);
    }

    public function testARefundThePlatformRefusesCarriesTheCodeOfThePlatform(): void
    {
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException(new PlatformRefusalException('The refund was refused: too late', 'PSP_096'));

        try {
            $this->service($refund)->refund($this->order(), 30.0, RefundReason::Other, null);
            self::fail('The refusal must reach Thelia.');
        } catch (PaymentRefusedException $refusal) {
            self::assertSame('PSP_096', $refusal->getProviderCode(), 'The code of the platform is written on the journal line, apart from the message.');
            self::assertSame('The refund was refused: too late', $refusal->getMessage());
        }
    }

    public function testAPlatformThatCouldNotBeAskedLeavesItsAnswerToTheLog(): void
    {
        // The SDK words its failure with the URL it called and the raw answer: that goes to the
        // log, the journal and the administrator read a sentence of their own.
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException(new LyraException('Error: call to URL https://api.payzen.eu/api-payment/V4/Order/Get failed with status 502, response <html>Bad gateway</html>'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('Bad gateway'));

        try {
            (new RefundFromThelia($refund, $logger))->refund($this->order(), 30.0, RefundReason::Other, null);
            self::fail('Nothing was refunded: the merchant must know.');
        } catch (PaymentRefusedException $refusal) {
            self::assertStringNotContainsString('https://', $refusal->getMessage());
            self::assertStringNotContainsString('<html>', $refusal->getMessage());
            self::assertStringContainsString('nothing was sent', $refusal->getMessage());
        }
    }

    public function testAPlatformThatCouldNotBeAskedIsARefusalNotAnUnknownOutcome(): void
    {
        // Listing the order's transactions comes before the refund is sent: nothing moved.
        $refund = $this->createMock(LyraTransactionRefundWrapper::class);
        $refund->method('refundRecorded')->willThrowException(new LyraException('Connection timed out'));

        $this->expectException(PaymentRefusedException::class);

        $this->service($refund)->refund($this->order(), 30.0, RefundReason::Other, null);
    }

    private function service(LyraTransactionRefundWrapper $refund): RefundFromThelia
    {
        return new RefundFromThelia($refund, $this->createMock(LoggerInterface::class));
    }

    private function order(): Order
    {
        $currency = (new Currency())->setCode('EUR');
        $order = $this->createMock(Order::class);
        $order->method('getTransactionRef')->willReturn('tx1');
        $order->method('getCurrency')->willReturn($currency);

        return $order;
    }
}
