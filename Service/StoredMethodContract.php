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

use PayzenEmbedded\Model\PayzenEmbeddedStoredMethodContractQuery;
use PayzenEmbedded\PayzenEmbedded;
use Propel\Runtime\Propel;
use Thelia\Model\CustomerPaymentMethod;
use Thelia\Model\Map\CustomerPaymentMethodTableMap;
use Thelia\Model\Order;

/**
 * The PayZen contract a stored card was registered on, which is the one of the sale unit of the order that
 * registered it (NULL for the national contract).
 *
 * PayZen creates a token on the contract the payment is made on (the contract of the sale unit of the order, since
 * 3.5.0) and the token is only good on that contract. A card is therefore offered, paid with and cancelled on the sale
 * unit it was registered on, never on another one. A national card is not shared with the sale units either: a unit
 * that leaves its contract empty reads the national one, but it may differ by any of the settings of a contract, and
 * the module does not compare contracts, so it takes the safe side (a customer asked for a new card is only asked to
 * type it, a card sent to the wrong contract fails at PayZen).
 *
 * A card with no row of its own is of an unknown contract: it pays nowhere and is cancelled on the national contract,
 * the best the module can do for it.
 */
final class StoredMethodContract
{
    /**
     * The sale unit the order was placed on, null for a national order, a shop with no sale unit or a Thelia that
     * does not know them: what the module registers cards on and pays them on.
     */
    public static function saleUnitOfOrder(Order $order): ?int
    {
        if (!PayzenEmbedded::supportsSaleUnits()) {
            return null;
        }

        $saleUnitId = $order->getSaleUnitId();

        return null === $saleUnitId ? null : (int) $saleUnitId;
    }

    /**
     * Link the card to the contract of the sale unit, once: a card already linked keeps its link (the platform
     * answers the token of a card again on each payment made with it, and a notification may come twice at once).
     */
    public static function link(CustomerPaymentMethod $method, ?int $saleUnitId): void
    {
        $statement = Propel::getWriteConnection(CustomerPaymentMethodTableMap::DATABASE_NAME)->prepare(
            'INSERT IGNORE INTO `payzen_embedded_stored_method_contract` (`customer_payment_method_id`, `sale_unit_id`) VALUES (?, ?)'
        );
        $statement->execute([(int) $method->getId(), $saleUnitId]);
    }

    /**
     * The sale unit the card is to be cancelled on: its own, null (the national contract) for a card with no link.
     */
    public static function saleUnitToCancelOn(CustomerPaymentMethod $method): ?int
    {
        $link = PayzenEmbeddedStoredMethodContractQuery::create()->findPk((int) $method->getId());

        return null === $link || null === $link->getSaleUnitId() ? null : (int) $link->getSaleUnitId();
    }

    /**
     * Whether the card can pay on the sale unit: it was registered on that very unit (the national contract being the
     * unit null), and is linked.
     */
    public static function isUsableOn(CustomerPaymentMethod $method, ?int $saleUnitId): bool
    {
        $link = PayzenEmbeddedStoredMethodContractQuery::create()->findPk((int) $method->getId());

        return null !== $link && self::sameUnit($link->getSaleUnitId(), $saleUnitId);
    }

    public static function isUsableForOrder(CustomerPaymentMethod $method, Order $order): bool
    {
        return self::isUsableOn($method, self::saleUnitOfOrder($order));
    }

    /**
     * @throws StoredMethodOfAnotherContractException when the card was registered on another sale unit's contract
     */
    public static function refuseUnlessUsableForOrder(CustomerPaymentMethod $method, Order $order): void
    {
        if (!self::isUsableForOrder($method, $order)) {
            throw new StoredMethodOfAnotherContractException();
        }
    }

    /**
     * The ids, among those of the cards, of the ones that can pay on the sale unit: one query for a list.
     *
     * @param list<int> $methodIds
     *
     * @return list<int>
     */
    public static function usableOn(array $methodIds, ?int $saleUnitId): array
    {
        if ([] === $methodIds) {
            return [];
        }

        $usable = [];

        foreach (PayzenEmbeddedStoredMethodContractQuery::create()->filterByCustomerPaymentMethodId($methodIds)->find() as $link) {
            if (self::sameUnit($link->getSaleUnitId(), $saleUnitId)) {
                $usable[] = (int) $link->getCustomerPaymentMethodId();
            }
        }

        return $usable;
    }

    /**
     * Whether the customer holds a card that can pay on the sale unit (the checkout notice is shown for that one).
     */
    public static function customerHasACardFor(int $customerId, int $paymentModuleId, ?int $saleUnitId): bool
    {
        $statement = Propel::getReadConnection(CustomerPaymentMethodTableMap::DATABASE_NAME)->prepare(
            'SELECT COUNT(*) FROM `customer_payment_method` AS `method` '
            . 'INNER JOIN `payzen_embedded_stored_method_contract` AS `contract` ON `contract`.`customer_payment_method_id` = `method`.`id` '
            . 'WHERE `method`.`customer_id` = ? AND `method`.`payment_module_id` = ? AND `contract`.`sale_unit_id` <=> ?'
        );
        $statement->execute([$customerId, $paymentModuleId, $saleUnitId]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function sameUnit(mixed $linked, ?int $saleUnitId): bool
    {
        return (null === $linked ? null : (int) $linked) === $saleUnitId;
    }
}
