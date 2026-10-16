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

use PayzenEmbedded\LyraClient\PaymentContract;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitModuleConfigQuery;
use Thelia\Model\SaleUnitQuery;

/**
 * The PayZen contract of each sale unit, as the back-office edits it.
 *
 * A sale unit gives its own value to the settings of PaymentContract; a setting it leaves empty
 * reads the national value. The passwords and the keys are stored as secrets and never shown back:
 * leaving one empty keeps what is stored, and only a removal asked for puts the national value back.
 *
 * Nothing here is reachable on a Thelia that has no sale units: see isAvailable().
 */
class SaleUnitContractSettings
{
    public function __construct(
        private readonly ?AdminSaleUnitScope $adminSaleUnitScope = null,
    ) {
    }

    /** The name of the field that asks for the removal of a secret setting. */
    public static function removalFieldOf(string $setting): string
    {
        return 'remove_' . $setting;
    }

    public function isAvailable(): bool
    {
        return PayzenEmbedded::supportsSaleUnits() && class_exists(SaleUnitQuery::class);
    }

    /**
     * The sale units the current administrator may configure.
     *
     * @return list<array{id: int, label: string, active: bool, hasContract: bool}>
     */
    public function saleUnits(string $locale): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $saleUnits = [];

        /** @var SaleUnit $saleUnit */
        foreach (SaleUnitQuery::create()->orderByPosition()->orderById()->find() as $saleUnit) {
            $id = (int) $saleUnit->getId();

            if (!$this->allows($id)) {
                continue;
            }

            $saleUnits[] = [
                'id' => $id,
                'label' => (string) ($saleUnit->setLocale($locale)->getTitle() ?: $saleUnit->getCode()),
                'active' => (bool) $saleUnit->getActive(),
                'hasContract' => [] !== array_filter(self::ownValues($id), static fn (?string $value): bool => null !== $value && '' !== $value),
            ];
        }

        return $saleUnits;
    }

    /**
     * Whether the sale unit exists and the current administrator may configure it: one restricted to
     * some units reaches those only.
     */
    public function allows(int $saleUnitId): bool
    {
        if (!$this->isAvailable() || null === SaleUnitQuery::create()->findPk($saleUnitId)) {
            return false;
        }

        return $this->adminSaleUnitScope?->allows($saleUnitId) ?? true;
    }

    /**
     * What the sale unit sets itself: the contract settings it has a value for, leaving out the
     * national value it would fall back on.
     *
     * @return array<string, string|null>
     */
    public static function ownValues(int $saleUnitId): array
    {
        if (!PayzenEmbedded::supportsSaleUnits() || !class_exists(SaleUnitModuleConfigQuery::class)) {
            return [];
        }

        return array_intersect_key(
            SaleUnitModuleConfigQuery::findValuesOfModule($saleUnitId, PayzenEmbedded::getModuleId()),
            array_flip(PaymentContract::SETTINGS)
        );
    }

    /**
     * @param array<string, mixed> $submitted the contract settings as the form sends them, by name
     */
    public function apply(int $saleUnitId, array $submitted): void
    {
        foreach (PaymentContract::SETTINGS as $setting) {
            $secret = \in_array($setting, PaymentContract::SECRET_SETTINGS, true);
            $value = trim((string) ($submitted[$setting] ?? ''));

            if ($secret && !empty($submitted[self::removalFieldOf($setting)])) {
                PayzenEmbedded::deleteConfigValueForSaleUnit($saleUnitId, $setting);

                continue;
            }

            if ('' === $value) {
                // A secret left empty is not shown back, so it is not asked to change; any other
                // setting left empty hands the unit back to the national value.
                if (!$secret) {
                    PayzenEmbedded::deleteConfigValueForSaleUnit($saleUnitId, $setting);
                }

                continue;
            }

            PayzenEmbedded::setConfigValueForSaleUnit($saleUnitId, $setting, $value, $secret);
        }
    }
}
