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

namespace PayzenEmbedded\LyraClient;

/**
 * The PayZen contract a payment is made on: the shop identifier, the platform it is filed on and
 * the keys of the space (TEST or PRODUCTION) in use.
 *
 * A shop with several sale units may hold one contract per unit. The contract is read through a
 * reader that answers a setting by name, so that the rule (a mode picks its own keys) lives in one
 * place whatever the unit the reader answers for.
 */
final readonly class PaymentContract
{
    /**
     * The settings that make up a contract. A sale unit gives its own value to each of them, see
     * missingSettings() for the ones it cannot leave empty.
     */
    public const SETTINGS = [
        'mode',
        'site_id',
        'webservice_endpoint',
        'test_password',
        'javascript_test_key',
        'signature_test_key',
        'production_password',
        'javascript_production_key',
        'signature_production_key',
    ];

    /** The settings a sale unit's contract never shows back: the passwords and the keys. */
    public const SECRET_SETTINGS = [
        'test_password',
        'javascript_test_key',
        'signature_test_key',
        'production_password',
        'javascript_production_key',
        'signature_production_key',
    ];

    public function __construct(
        public bool $test,
        public ?string $siteId,
        public ?string $endpoint,
        public ?string $password,
        public ?string $publicKey,
        public ?string $signatureKey,
    ) {
    }

    /**
     * @param callable(string): (string|null) $setting answers a setting of the module by name
     */
    public static function read(callable $setting): self
    {
        $space = 'TEST' === $setting('mode') ? 'test' : 'production';

        return new self(
            'test' === $space,
            $setting('site_id'),
            $setting('webservice_endpoint'),
            $setting($space . '_password'),
            $setting('javascript_' . $space . '_key'),
            $setting('signature_' . $space . '_key'),
        );
    }

    /**
     * The settings a contract needs and does not have: the mode, the shop identifier, and the
     * password and the keys of the space the mode picks. The web service URL is the address of the
     * platform, not a part of the contract, and may be left to the national value. The keys of the
     * other space are never read.
     *
     * @param callable(string): (string|null) $setting answers a setting by name, null or an empty
     *                                                 string when it has no value
     *
     * @return list<string>
     */
    public static function missingSettings(callable $setting): array
    {
        $space = 'TEST' === $setting('mode') ? 'test' : 'production';

        return array_values(array_filter(
            ['mode', 'site_id', $space . '_password', 'javascript_' . $space . '_key', 'signature_' . $space . '_key'],
            static fn (string $name): bool => '' === trim((string) $setting($name)),
        ));
    }
}
