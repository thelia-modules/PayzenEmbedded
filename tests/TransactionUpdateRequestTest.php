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

namespace PayzenEmbedded\Tests;

use PayzenEmbedded\LyraClient\TransactionUpdateRequest;
use PHPUnit\Framework\TestCase;

/**
 * What the update form of the order page may ask of the platform. Authorizing first, the capture is
 * Thelia's: with its right, its checks and its journal. The form then only moves the capture date.
 */
final class TransactionUpdateRequestTest extends TestCase
{
    public function testTakingThePaymentAtOnceTheFormAsksWhatWasTyped(): void
    {
        $request = TransactionUpdateRequest::from(['amount' => '80.00', 'capture_date' => null, 'automatic_validation' => true], false, '120.00');

        self::assertSame('80.00', $request->amount);
        self::assertFalse($request->manualValidation);
    }

    public function testAuthorizingFirstTheFormNeitherCapturesNorLowersTheAmount(): void
    {
        // Even a request built by hand: the fields are not shown, and what they carry is not used.
        $request = TransactionUpdateRequest::from(['amount' => '80.00', 'capture_date' => null, 'automatic_validation' => true], true, '120.00');

        self::assertSame('120.00', $request->amount, 'The amount the platform holds, unchanged.');
        self::assertTrue($request->manualValidation, 'Left to the validation mode: no capture.');
    }

    public function testOnceTheJournalHoldsTheCaptureTheAmountIsNoLongerLowered(): void
    {
        // The journal would keep the total: the difference is given back with Thelia's refund.
        $request = TransactionUpdateRequest::from(['amount' => '80.00', 'capture_date' => null, 'automatic_validation' => true], false, '120.00', true);

        self::assertSame('120.00', $request->amount);
        self::assertFalse($request->manualValidation, 'The validation is still the form\'s.');
    }

    public function testTheCaptureDateIsAlwaysTheOneTyped(): void
    {
        $date = new \DateTime('2026-11-02');

        self::assertSame($date, TransactionUpdateRequest::from(['amount' => '80.00', 'capture_date' => $date, 'automatic_validation' => false], true, '120.00')->captureDate);
    }
}
