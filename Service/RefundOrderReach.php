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
use Thelia\Model\OrderQuery;

/**
 * Whether the administrator of the request reaches an order, for the refund route of the order
 * sheet: an administrator restricted to some sale units acts on the orders of those units only, as
 * on the rest of the back office. A Thelia that knows no sale unit restricts nobody.
 */
final readonly class RefundOrderReach
{
    public function __construct(
        private ?AdminSaleUnitScope $adminSaleUnitScope = null,
    ) {
    }

    /**
     * False when the order does not exist or is out of reach: the two answer alike.
     */
    public function reaches(int $orderId): bool
    {
        $order = OrderQuery::create()->findPk($orderId);

        if (null === $order) {
            return false;
        }

        return null === $this->adminSaleUnitScope || $this->adminSaleUnitScope->allows($order->getSaleUnitId());
    }
}
