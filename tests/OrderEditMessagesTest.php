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

use PayzenEmbedded\Service\OrderEditMessages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The outcome of an operation of the order page is shown once, above the form that asked for it,
 * on the page of that order only.
 */
final class OrderEditMessagesTest extends TestCase
{
    public function testMessagesAreGivenBackByFormInTheOrderTheyWereAdded(): void
    {
        $session = new Session(new MockArraySessionStorage());

        OrderEditMessages::add($session, 12, OrderEditMessages::REFUND, 'danger', 'Refused.');
        OrderEditMessages::add($session, 12, OrderEditMessages::REFRESH, 'success', 'Refreshed.');
        OrderEditMessages::add($session, 12, OrderEditMessages::REFUND, 'warning', 'Check the order.');

        self::assertSame(
            [
                OrderEditMessages::REFUND => [
                    ['type' => 'danger', 'message' => 'Refused.'],
                    ['type' => 'warning', 'message' => 'Check the order.'],
                ],
                OrderEditMessages::REFRESH => [
                    ['type' => 'success', 'message' => 'Refreshed.'],
                ],
            ],
            OrderEditMessages::take($session, 12)
        );
    }

    public function testMessagesAreShownOnce(): void
    {
        $session = new Session(new MockArraySessionStorage());

        OrderEditMessages::add($session, 12, OrderEditMessages::UPDATE, 'success', 'Updated.');
        OrderEditMessages::take($session, 12);

        self::assertSame([], OrderEditMessages::take($session, 12));
    }

    public function testThePageOfAnotherOrderLeavesTheMessagesWhereTheyAre(): void
    {
        $session = new Session(new MockArraySessionStorage());

        OrderEditMessages::add($session, 12, OrderEditMessages::REFUND, 'danger', 'Refused.');

        self::assertSame([], OrderEditMessages::take($session, 13));
        self::assertSame(
            [OrderEditMessages::REFUND => [['type' => 'danger', 'message' => 'Refused.']]],
            OrderEditMessages::take($session, 12)
        );
    }

    public function testAMessageNotReadInTimeIsNotShownOnALaterVisit(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $sent = 1_000_000;

        OrderEditMessages::add($session, 12, OrderEditMessages::REFUND, 'danger', 'Refused.', $sent);

        self::assertSame([], OrderEditMessages::take($session, 12, $sent + OrderEditMessages::MAXIMUM_AGE_SECONDS));
    }

    public function testAMessageReadRightAfterTheRedirectIsShown(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $sent = 1_000_000;

        OrderEditMessages::add($session, 12, OrderEditMessages::REFUND, 'danger', 'Refused.', $sent);

        self::assertSame(
            [OrderEditMessages::REFUND => [['type' => 'danger', 'message' => 'Refused.']]],
            OrderEditMessages::take($session, 12, $sent + OrderEditMessages::MAXIMUM_AGE_SECONDS - 1)
        );
    }

    public function testTheExpiredMessagesOfAnOrderNeverDisplayedAgainLeaveTheSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $sent = 1_000_000;

        OrderEditMessages::add($session, 12, OrderEditMessages::REFUND, 'danger', 'Refused.', $sent);
        OrderEditMessages::take($session, 13, $sent + OrderEditMessages::MAXIMUM_AGE_SECONDS);

        self::assertSame([], $session->all());
    }

    public function testAnOrderPageWithNothingToShowLeavesTheSessionUnwritten(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturn([]);
        $session->expects(self::never())->method('set');
        $session->expects(self::never())->method('remove');

        self::assertSame([], OrderEditMessages::take($session, 12));
    }

    public function testAMessageWithoutItsDateIsDropped(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('payzen-embedded.order-edit-messages', [12 => [OrderEditMessages::REFUND => [['type' => 'danger', 'message' => 'Refused.']]]]);

        self::assertSame([], OrderEditMessages::take($session, 12));
        self::assertSame([], $session->all());
    }

    public function testAClockSetBackDoesNotKeepAMessagePastTheDelay(): void
    {
        $sent = 1_000_000;

        $setBackPastTheDelay = new Session(new MockArraySessionStorage());
        OrderEditMessages::add($setBackPastTheDelay, 12, OrderEditMessages::REFUND, 'danger', 'Refused.', $sent);

        self::assertSame([], OrderEditMessages::take($setBackPastTheDelay, 12, $sent - OrderEditMessages::MAXIMUM_AGE_SECONDS));

        $setBackALittle = new Session(new MockArraySessionStorage());
        OrderEditMessages::add($setBackALittle, 12, OrderEditMessages::REFUND, 'danger', 'Refused.', $sent);

        self::assertSame(
            [OrderEditMessages::REFUND => [['type' => 'danger', 'message' => 'Refused.']]],
            OrderEditMessages::take($setBackALittle, 12, $sent - 10)
        );
    }
}
