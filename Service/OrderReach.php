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

namespace PayzenEmbedded\Service;

use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;

/**
 * Whether the administrator of the request reaches an order, for the routes the module adds to
 * the order sheet: an administrator restricted to some sale units acts on the orders of those
 * units only, as on the rest of the back office. A Thelia that knows no sale unit restricts nobody.
 */
final readonly class OrderReach
{
    public function __construct(
        private ?AdminSaleUnitScope $adminSaleUnitScope = null,
    ) {
    }

    /**
     * The order, null when it does not exist or is out of reach: the two answer alike.
     */
    public function orderOf(int $orderId): ?Order
    {
        $order = OrderQuery::create()->findPk($orderId);

        if (null === $order) {
            return null;
        }

        return null === $this->adminSaleUnitScope || $this->adminSaleUnitScope->allows($order->getSaleUnitId()) ? $order : null;
    }
}
