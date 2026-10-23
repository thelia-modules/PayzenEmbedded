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

use PayzenEmbedded\Form\TransactionUpdateForm;
use PayzenEmbedded\LyraClient\LyraPaymentManagementWrapper;
use PayzenEmbedded\Service\StoredMethodRecorder;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Enum\OrderPaymentPartState;
use Thelia\Model\Order;

/**
 * The amount an administrator may lower a transaction to is bounded by what PayZen holds, not by the total of the
 * order: an order paid in several parts holds less at PayZen than it is worth.
 */
final class CaptureBoundTest extends PayzenIntegrationTestCase
{
    public function testTheAmountIsBoundedByTheTransactionPayZenHoldsOnAnOrderPaidInParts(): void
    {
        $order = $this->orderPaidInParts();
        $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 7000));

        self::assertSame([], $this->violationsOf($order, '70.00'));
        self::assertSame([], $this->violationsOf($order, '12.5'));
        self::assertNotSame([], $this->violationsOf($order, '70.01'), 'More than PayZen holds.');
        self::assertNotSame([], $this->violationsOf($order, '100.00'), 'The total of the order is not what PayZen holds.');
    }

    public function testWhatThePlatformHoldsComesBeforeWhatThePartSays(): void
    {
        $order = $this->orderPaidInParts();
        $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 5000));

        self::assertSame([], $this->violationsOf($order, '50.00'));
        self::assertNotSame([], $this->violationsOf($order, '60.00'), 'The platform holds 50.00, whatever the part says.');
    }

    public function testWithNoTransactionRecordedTheAmountIsBoundedByThePartPayZenHolds(): void
    {
        $order = $this->orderPaidInParts();

        self::assertSame([], $this->violationsOf($order, '70.00'));
        self::assertNotSame([], $this->violationsOf($order, '70.01'));
    }

    public function testTheBoundFallsBackOnThePrimaryPartOfTheModuleOnly(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        // A part of the module that is not the one PayZen charges, first in the order of the rows.
        $this->part($order, $this->module, '20.000000', OrderPaymentPartState::SETTLED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::PENDING, true, 2);

        self::assertSame([], $this->violationsOf($order, '70.00'), 'The bound is the primary part, not the first part of the module.');
        self::assertNotSame([], $this->violationsOf($order, '70.01'));
    }

    public function testTheMessageNamesTheAmountPayZenHolds(): void
    {
        $order = $this->orderPaidInParts();

        $violations = $this->violationsOf($order, '70.01');

        self::assertCount(1, $violations);
        self::assertStringContainsString('PayZen holds', $violations[0]);
        self::assertStringNotContainsString('order current amount', $violations[0]);
    }

    public function testAnOrderPaidWholeAtPayZenKeepsItsTotalAsTheBound(): void
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $this->wrapper()->processPaymentResponse($this->notificationOfAPaymentOf($order, 10000));

        self::assertSame([], $this->violationsOf($order, '100.00'));
        self::assertNotSame([], $this->violationsOf($order, '100.01'));
    }

    private function orderPaidInParts(): Order
    {
        $order = $this->orderWorth('100.000000', $this->customer());
        $this->part($order, \Thelia\Model\ModuleQuery::create()->filterByCode('PayzenEmbedded', \Propel\Runtime\ActiveQuery\Criteria::NOT_EQUAL)->findOne(), '30.000000', OrderPaymentPartState::SETTLED, false, 1);
        $this->part($order, $this->module, '70.000000', OrderPaymentPartState::PENDING, true, 2);

        return $order;
    }

    /**
     * @return list<string>
     */
    private function violationsOf(Order $order, string $typed): array
    {
        $violations = [];
        $root = new class($order->getId()) {
            public function __construct(private readonly int $orderId)
            {
            }

            /** @return array<string, int> */
            public function getData(): array
            {
                return ['order_id' => $this->orderId];
            }
        };
        $context = $this->createStub(ExecutionContextInterface::class);
        $context->method('getRoot')->willReturn($root);
        $context->method('addViolation')->willReturnCallback(static function (string $message) use (&$violations): void {
            $violations[] = $message;
        });

        $form = (new \ReflectionClass(TransactionUpdateForm::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Thelia\Form\BaseForm::class, 'translator'))->setValue($form, Translator::getInstance() ?? $this->getService(Translator::class));
        $form->checkOrderAmount($typed, $context);

        return $violations;
    }

    private function wrapper(): LyraPaymentManagementWrapper
    {
        return new LyraPaymentManagementWrapper(
            static::getContainer()->get('event_dispatcher'),
            null,
            new StoredMethodRecorder($this->getService(CustomerPaymentMethodService::class)),
        );
    }
}
