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

use PayzenEmbedded\LyraClient\PaymentContract;
use PHPUnit\Framework\TestCase;

/**
 * The contract a payment is made on: the space (TEST or PRODUCTION) picks the password and the keys.
 * What a sale unit must set for its contract to be paid on is missingSettings().
 */
final class PaymentContractTest extends TestCase
{
    private const NATIONAL = [
        'mode' => 'PRODUCTION',
        'site_id' => '11111111',
        'webservice_endpoint' => 'https://api.payzen.eu',
        'test_password' => 'national-test-password',
        'javascript_test_key' => '11111111:national-test-public',
        'signature_test_key' => 'national-test-hmac',
        'production_password' => 'national-production-password',
        'javascript_production_key' => '11111111:national-production-public',
        'signature_production_key' => 'national-production-hmac',
    ];

    public function testTheProductionSpaceReadsTheProductionKeys(): void
    {
        $contract = PaymentContract::read($this->reader(self::NATIONAL));

        self::assertFalse($contract->test);
        self::assertSame('11111111', $contract->siteId);
        self::assertSame('national-production-password', $contract->password);
        self::assertSame('11111111:national-production-public', $contract->publicKey);
        self::assertSame('national-production-hmac', $contract->signatureKey);
    }

    public function testTheTestSpaceReadsTheTestKeys(): void
    {
        $contract = PaymentContract::read($this->reader(['mode' => 'TEST'] + self::NATIONAL));

        self::assertTrue($contract->test);
        self::assertSame('national-test-password', $contract->password);
        self::assertSame('11111111:national-test-public', $contract->publicKey);
        self::assertSame('national-test-hmac', $contract->signatureKey);
    }

    public function testAnyModeButTestIsTheProductionSpace(): void
    {
        self::assertFalse(PaymentContract::read($this->reader(['mode' => 'PRODUCTION_RESTRICTED'] + self::NATIONAL))->test);
        self::assertFalse(PaymentContract::read($this->reader(['mode' => null] + self::NATIONAL))->test);
    }

    public function testAReaderThatMergesTwoConfigurationsReadsWhatItAnswers(): void
    {
        $unit = [
            'site_id' => '22222222',
            'production_password' => 'unit-production-password',
            'javascript_production_key' => '22222222:unit-production-public',
        ];

        $contract = PaymentContract::read($this->reader($unit + self::NATIONAL));

        self::assertSame('22222222', $contract->siteId);
        self::assertSame('unit-production-password', $contract->password);
        self::assertSame('22222222:unit-production-public', $contract->publicKey);
        // read() takes what the reader answers: completing a unit with the national values is the
        // reader's doing, which the module never does for a sale unit (missingSettings()).
        self::assertSame('national-production-hmac', $contract->signatureKey);
        self::assertSame('https://api.payzen.eu', $contract->endpoint);
    }

    public function testAContractNeedsItsModeItsShopAndTheKeysOfItsSpace(): void
    {
        $test = [
            'mode' => 'TEST',
            'site_id' => '22222222',
            'test_password' => 'unit-test-password',
            'javascript_test_key' => '22222222:unit-test-public',
            'signature_test_key' => 'unit-test-hmac',
        ];

        self::assertSame([], PaymentContract::missingSettings($this->reader($test)), 'The keys of the other space and the web service URL are not needed.');
        self::assertSame(['signature_test_key'], PaymentContract::missingSettings($this->reader(['signature_test_key' => null] + $test)));
        self::assertSame(['site_id'], PaymentContract::missingSettings($this->reader(['site_id' => ' '] + $test)), 'A blank value is no value.');
        self::assertSame(
            ['production_password', 'javascript_production_key', 'signature_production_key'],
            PaymentContract::missingSettings($this->reader(['mode' => 'PRODUCTION'] + $test)),
            'A unit in production needs the production keys, the test ones do not stand in for them.',
        );
    }

    public function testAContractWithNoValueLacksEverythingItReads(): void
    {
        self::assertSame(
            ['mode', 'site_id', 'production_password', 'javascript_production_key', 'signature_production_key'],
            PaymentContract::missingSettings($this->reader([])),
        );
    }

    public function testEverySettingOfAContractIsOneASaleUnitMaySet(): void
    {
        $asked = [];
        PaymentContract::read(static function (string $name) use (&$asked): ?string {
            $asked[$name] = true;

            return 'TEST';
        });
        PaymentContract::read(static function (string $name) use (&$asked): ?string {
            $asked[$name] = true;

            return null;
        });

        self::assertEqualsCanonicalizing(PaymentContract::SETTINGS, array_keys($asked));
        self::assertSame([], array_diff(PaymentContract::SECRET_SETTINGS, PaymentContract::SETTINGS));
    }

    /**
     * @param array<string, string|null> $values
     *
     * @return callable(string): (string|null)
     */
    private function reader(array $values): callable
    {
        return static fn (string $name): ?string => $values[$name] ?? null;
    }
}
