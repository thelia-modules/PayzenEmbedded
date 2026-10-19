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

use PHPUnit\Framework\TestCase;

/**
 * The templates are read as text: what the browser does with their markup does not depend on the shop.
 */
final class FrontTemplatesTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/../templates/frontOffice/default/payzen-embedded/';

    /**
     * The hook order-invoice.payment-extra is rendered inside the form that pays the order: a form of its own is
     * dropped by the browser and its button would pay the order instead of forgetting the cards.
     */
    public function testTheNoticeOfTheCheckoutHasNoFormOfItsOwn(): void
    {
        $template = (string) file_get_contents(self::DIRECTORY . 'one_click-token-info.html');

        self::assertStringNotContainsString('<form', $template);
        self::assertStringNotContainsString('<button', $template);
        self::assertStringContainsString("{url path='/account'}", $template, 'The customer is sent to their account, where the cards are managed.');
    }

    /**
     * The account page renders account.additional outside of any form: the cards are forgotten by a POST with the
     * token of the session.
     */
    public function testTheAccountPageForgetsTheCardsByAPostThatCarriesTheToken(): void
    {
        $template = (string) file_get_contents(self::DIRECTORY . 'customer-account.html');

        self::assertMatchesRegularExpression('/<form method="post" action="\{url path=\'\/payzen-embedded\/alias-clear\'\}">/', $template);
        self::assertStringContainsString('name="_token" value="{$csrf_token}"', $template);
    }
}
