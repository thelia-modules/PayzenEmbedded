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
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\RefundFromThelia;
use PayzenEmbedded\Service\StoredMethodRecorder;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Enum\PaymentTransactionType;
use Thelia\Model\Map\OrderPaymentTransactionTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderPaymentTransaction;
use Thelia\Model\OrderPaymentTransactionQuery;
use Thelia\Model\OrderQuery;

/**
 * An order paid with PayZen, the way its notification pays it, and a platform that answers what the
 * test scripts: the refund is then given back through Thelia's refund service, or reported by a
 * notification, and the payment journal of Thelia is read.
 */
abstract class RefundIntegrationTestCase extends PayzenIntegrationTestCase
{
    protected RecordingRefundClient $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setModuleConfig('mode', 'TEST');
        $this->platform = new RecordingRefundClient($this->dispatcher(), $this->getService(LockFactory::class), null);
        // The module reaches the service by its container: the one that talks to the scripted platform.
        static::getContainer()->set(RefundFromThelia::class, new RefundFromThelia($this->platform, new NullLogger()));
    }

    protected function dispatcher(): EventDispatcherInterface
    {
        return static::getContainer()->get('event_dispatcher');
    }

    /**
     * An order of 100.00 PayZen took, through the notification of its payment: it is paid, the core
     * wrote the capture of its total, and PayZen lists the debit.
     *
     * @return array{Order, string} the order and the uuid of its debit
     */
    protected function paidOrder(string $detailedStatus = 'CAPTURED', string $total = '100.000000'): array
    {
        $order = $this->orderWorth($total, $this->customer());
        $minorUnits = (int) round((float) $total * 100);
        $debit = bin2hex(random_bytes(16));
        $notification = $this->notificationOfAPaymentOf($order, $minorUnits, ['uuid' => $debit, 'detailedStatus' => $detailedStatus]);

        $this->wrapper()->processPaymentResponse($notification);

        self::assertTrue($this->reread($order)->isPaid(false), 'The notification paid the order.');
        $this->platform->listed = [$notification['transactions'][0] + ['orderDetails' => ['orderId' => $order->getRef(), 'mode' => PayzenEmbedded::platformMode()]]];

        return [$this->reread($order), $debit];
    }

    protected function wrapper(): LyraPaymentManagementWrapper
    {
        return new LyraPaymentManagementWrapper(
            $this->dispatcher(),
            $this->getService(LockFactory::class),
            new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class)),
        );
    }

    /**
     * A refund transaction as PayZen describes it.
     *
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    protected function credit(Order $order, string $debit, string $uuid, int $minorUnits, string $status = TransactionOutcome::STATUS_PAID, array $more = []): array
    {
        return $more + [
            'uuid' => $uuid,
            'status' => $status,
            'operationType' => TransactionOutcome::OPERATION_CREDIT,
            'amount' => $minorUnits,
            'currency' => 'EUR',
            'creationDate' => (new \DateTimeImmutable('-1 minute'))->format(\DATE_ATOM),
            'orderDetails' => ['orderId' => $order->getRef(), 'mode' => PayzenEmbedded::platformMode()],
            'transactionDetails' => ['parentTransactionUuid' => $debit],
            'metadata' => [TransactionOutcome::SHOP_MARKER_KEY => PayzenEmbedded::shopMarker()],
        ];
    }

    /**
     * What the platform notifies of a transaction.
     *
     * @param array<string, mixed> $transaction
     *
     * @return array<string, mixed>
     */
    protected function notificationOf(Order $order, array $transaction): array
    {
        return [
            'orderDetails' => ['orderId' => $order->getRef(), 'mode' => PayzenEmbedded::platformMode()],
            'transactions' => [$transaction],
        ];
    }

    /**
     * @return list<OrderPaymentTransaction> the lines of the journal of the type, in the order they were written
     */
    protected function journal(Order $order, PaymentTransactionType $type): array
    {
        OrderPaymentTransactionTableMap::clearInstancePool();

        return array_values(array_filter(
            array_reverse(iterator_to_array(OrderPaymentTransactionQuery::create()->findJournal($order->getId()))),
            static fn (OrderPaymentTransaction $line): bool => $type->value === $line->getType(),
        ));
    }

    protected function reread(Order $order): Order
    {
        OrderTableMap::clearInstancePool();

        return OrderQuery::create()->findPk($order->getId()) ?? self::fail('The order is gone.');
    }
}
