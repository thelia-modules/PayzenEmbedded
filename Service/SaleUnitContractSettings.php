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

use PayzenEmbedded\LyraClient\IncompleteSaleUnitContractException;
use PayzenEmbedded\LyraClient\PaymentContract;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Domain\SaleUnit\Access\AdminSaleUnitScope;
use Thelia\Model\SaleUnit;
use Thelia\Model\SaleUnitModuleConfigQuery;
use Thelia\Model\SaleUnitQuery;

/**
 * The PayZen contract of each sale unit, as the back-office edits it.
 *
 * A sale unit is paid on a contract of its own, whole: the settings PaymentContract::missingSettings()
 * names are read from the unit and from nowhere else, and a unit that lacks one of them is paid on no
 * contract, never on the national one (see contractOf()). The passwords and the keys are stored as
 * secrets and never shown back: leaving one empty keeps what is stored, and only a removal asked for
 * drops it.
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
     * @return list<array{id: int, label: string, active: bool, hasContract: bool, missingSettings: list<string>}>
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
                'missingSettings' => self::missingSettingsOf($id),
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
     * The settings the contract of the sale unit lacks, by name: none when it can be paid on.
     *
     * @return list<string>
     */
    public static function missingSettingsOf(int $saleUnitId): array
    {
        $own = self::ownValues($saleUnitId);

        return PaymentContract::missingSettings(static fn (string $name): ?string => $own[$name] ?? null);
    }

    /**
     * The contract of the sale unit, read from the unit alone: a setting it does not hold is not
     * taken from the national configuration, the web service URL aside (the address of the platform,
     * which the unit may leave to the national value).
     *
     * @throws IncompleteSaleUnitContractException when the unit lacks a setting of its contract
     */
    public static function contractOf(int $saleUnitId): PaymentContract
    {
        $own = self::ownValues($saleUnitId);
        $missing = PaymentContract::missingSettings(static fn (string $name): ?string => $own[$name] ?? null);

        if ([] !== $missing) {
            throw new IncompleteSaleUnitContractException($saleUnitId, $missing);
        }

        return PaymentContract::read(static fn (string $name): ?string => 'webservice_endpoint' === $name
            ? PayzenEmbedded::getConfigValueForSaleUnit($saleUnitId, $name)
            : $own[$name] ?? null);
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
                // setting left empty drops the value of the unit.
                if (!$secret) {
                    PayzenEmbedded::deleteConfigValueForSaleUnit($saleUnitId, $setting);
                }

                continue;
            }

            PayzenEmbedded::setConfigValueForSaleUnit($saleUnitId, $setting, $value, $secret);
        }
    }
}
