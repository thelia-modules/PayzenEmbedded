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

namespace PayzenEmbedded\Tests;

use PayzenEmbedded\PayzenEmbedded;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope;
use Thelia\Model\Order;

/**
 * What is about an order runs with the configuration of the sale unit the order was placed on, so
 * that its payment, its notification and its refund use that unit's contract. A Thelia that does not
 * know the sale units runs it as it always did.
 */
final class SaleUnitScopeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Thelia\Model\Base\Order::class)) {
            self::markTestSkipped('The ORM classes of the shop are not generated yet: build its cache, then run the tests again.');
        }

        if (!PayzenEmbedded::supportsSaleUnits()) {
            self::markTestSkipped('This Thelia has no sale units.');
        }

        SaleUnitConfigScope::reset();
    }

    protected function tearDown(): void
    {
        if (class_exists(SaleUnitConfigScope::class)) {
            SaleUnitConfigScope::reset();
        }
    }

    public function testAnOrderRunsWithTheConfigurationOfItsSaleUnit(): void
    {
        self::assertSame(7, PayzenEmbedded::runForOrder($this->orderOf(7), static fn (): ?int => SaleUnitConfigScope::current()));
        self::assertSame(9, PayzenEmbedded::runForOrder($this->orderOf(9), static fn (): ?int => SaleUnitConfigScope::current()));
    }

    public function testANationalOrderRunsWithTheNationalConfigurationWhateverTheRequestResolved(): void
    {
        SaleUnitConfigScope::set(7);

        self::assertNull(PayzenEmbedded::runForOrder($this->orderOf(null), static fn (): ?int => SaleUnitConfigScope::current()));
    }

    public function testTheOrderOfAnotherSaleUnitIsNotRunWithTheOneOfTheRequest(): void
    {
        SaleUnitConfigScope::set(7);

        self::assertSame(9, PayzenEmbedded::runForOrder($this->orderOf(9), static fn (): ?int => SaleUnitConfigScope::current()));
        self::assertSame(7, SaleUnitConfigScope::current(), 'the scope of the request is given back');
    }

    public function testTheScopeIsGivenBackWhenTheOperationFails(): void
    {
        SaleUnitConfigScope::set(7);

        try {
            PayzenEmbedded::runForOrder($this->orderOf(9), static function (): never {
                throw new \RuntimeException('platform down');
            });
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException) {
            self::assertSame(7, SaleUnitConfigScope::current());
        }
    }

    private function orderOf(?int $saleUnitId): Order
    {
        $order = new Order();
        $order->setSaleUnitId($saleUnitId);

        return $order;
    }
}
