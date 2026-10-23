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

namespace PayzenEmbedded\EventListener;

use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\StoredMethodContract;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Api\Bridge\Propel\Event\PaymentModuleOptionEvent;
use Thelia\Api\Resource\PaymentModuleOptionGroup;
use Thelia\Api\Resource\PaymentModuleStoredMethodOption;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Payment\EventListener\StoredPaymentMethodOptionsListener;
use Thelia\Domain\SaleUnit\Config\SaleUnitConfigScope;

/**
 * Keeps the cards of the other sale units out of what the checkout offers for PayZen.
 *
 * The core lists every card a customer kept through a payment module (StoredPaymentMethodOptionsListener, at the default
 * priority). A PayZen card is good on the contract of the sale unit it was registered on only: this listener runs after
 * the core's and drops the cards that cannot pay the cart. The group goes away when no card is left, as the core does
 * for a buyer with nothing to choose between, so that the buyer is only offered a new card.
 *
 * The sale unit is the one of the cart, the one of the request when the listing has no cart.
 */
final class StoredMethodsOfTheSaleUnitListener implements EventSubscriberInterface
{
    /** After the listener of the core, which writes the group this one filters. */
    private const PRIORITY = -100;

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::MODULE_PAYMENT_GET_OPTIONS => ['keepTheCardsOfTheSaleUnit', self::PRIORITY],
        ];
    }

    public function keepTheCardsOfTheSaleUnit(PaymentModuleOptionEvent $event): void
    {
        if ((int) $event->getModule()->getId() !== PayzenEmbedded::getModuleId()) {
            return;
        }

        $saleUnitId = $this->saleUnitOf($event);
        $groups = [];

        foreach ($event->getPaymentModuleOptionGroups() as $group) {
            if (StoredPaymentMethodOptionsListener::GROUP_CODE === $group->getCode()) {
                $group = $this->withTheCardsUsableOn($group, $saleUnitId);
            }

            if (null !== $group) {
                $groups[] = $group;
            }
        }

        $event->setPaymentModuleOptionGroups($groups);
    }

    private function saleUnitOf(PaymentModuleOptionEvent $event): ?int
    {
        if (!PayzenEmbedded::supportsSaleUnits()) {
            return null;
        }

        $cart = $event->getCart();

        if (null === $cart) {
            return SaleUnitConfigScope::current();
        }

        $saleUnitId = $cart->getSaleUnitId();

        return null === $saleUnitId ? null : (int) $saleUnitId;
    }

    /**
     * @return PaymentModuleOptionGroup|null null when no card is left
     */
    private function withTheCardsUsableOn(PaymentModuleOptionGroup $group, ?int $saleUnitId): ?PaymentModuleOptionGroup
    {
        $cardIds = [];

        foreach ($group->getOptions() as $option) {
            if ($option instanceof PaymentModuleStoredMethodOption && null !== $option->getCustomerPaymentMethodId()) {
                $cardIds[] = (int) $option->getCustomerPaymentMethodId();
            }
        }

        $usable = StoredMethodContract::usableOn($cardIds, $saleUnitId);
        $options = array_values(array_filter(
            $group->getOptions(),
            static fn ($option): bool => !$option instanceof PaymentModuleStoredMethodOption
                || null === $option->getCustomerPaymentMethodId()
                || \in_array((int) $option->getCustomerPaymentMethodId(), $usable, true)
        ));

        if ([] === array_filter($options, static fn ($option): bool => $option instanceof PaymentModuleStoredMethodOption && null !== $option->getCustomerPaymentMethodId())) {
            return null;
        }

        return $group->setOptions($options);
    }
}
