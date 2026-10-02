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
}
