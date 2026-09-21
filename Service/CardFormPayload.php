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

/**
 * What a template needs to mount the PayZen card fields, and nothing else.
 *
 * The form token and the public key are meant for the browser. The private key and the notification
 * password are not in here and must never be: they stay on the server, in the module configuration.
 */
final readonly class CardFormPayload
{
    public function __construct(
        public bool $available,
        public int $orderId,
        public string $formToken = '',
        public string $publicKey = '',
        public string $clientResourcesBaseUrl = '',
        public bool $smartForm = false,
        public bool $cardFormExpanded = true,
        public string $errorCode = '',
        public string $errorMessage = '',
        public string $detailedErrorCode = '',
        public string $detailedErrorMessage = '',
    ) {
    }

    public static function ready(
        int $orderId,
        string $formToken,
        string $publicKey,
        string $clientResourcesBaseUrl,
        bool $smartForm,
        bool $cardFormExpanded,
    ): self {
        return new self(
            available: true,
            orderId: $orderId,
            formToken: $formToken,
            publicKey: $publicKey,
            clientResourcesBaseUrl: $clientResourcesBaseUrl,
            smartForm: $smartForm,
            cardFormExpanded: $cardFormExpanded,
        );
    }

    public static function unavailable(
        int $orderId,
        string $errorCode,
        string $errorMessage,
        string $detailedErrorCode = '',
        string $detailedErrorMessage = '',
    ): self {
        return new self(
            available: false,
            orderId: $orderId,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            detailedErrorCode: $detailedErrorCode,
            detailedErrorMessage: $detailedErrorMessage,
        );
    }
}
