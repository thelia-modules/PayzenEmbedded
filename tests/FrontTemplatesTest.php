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
     * The notice of the checkout told the customer that the payment was made at once, with no step of their own: the
     * form now opens on the saved card, the customer confirms there, and the bank may ask for a 3-D Secure challenge.
     */
    public function testTheNoticeOfTheCheckoutDescribesTheFormThatOpensOnTheSavedCard(): void
    {
        $template = (string) file_get_contents(self::DIRECTORY . 'one_click-token-info.html');

        self::assertStringNotContainsString('immediately', $template);
        self::assertStringNotContainsString('Next Step', $template);
        self::assertStringContainsString('the payment form opens on that card', $template);
        self::assertStringContainsString('you confirm the payment there', $template);
        self::assertStringContainsString('authenticate', $template, 'The 3-D Secure challenge is announced.');
    }

    public function testEveryTextOfTheNoticeIsTranslatedIntoFrench(): void
    {
        $template = (string) file_get_contents(self::DIRECTORY . 'one_click-token-info.html');
        $french = require __DIR__ . '/../I18n/frontOffice/default/fr_FR.php';

        self::assertSame(3, preg_match_all("/\\{intl l='((?:[^'\\\\]|\\\\.)*)'/", $template, $texts), 'The three texts of the notice.');

        foreach ($texts[1] as $text) {
            self::assertArrayHasKey($text, $french, 'Not translated: ' . $text);
            self::assertNotSame('', $french[$text]);
        }

        self::assertStringNotContainsString('immédiatement', implode(' ', $french), 'The French no longer promises an immediate payment.');
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

    /**
     * Cancelling the payment cancels the order: the payment page asks for it by POST forms carrying the token of the
     * session, never by a link, and the payment form sends a refused payment to the page that changes nothing.
     */
    public function testThePaymentPageCancelsTheOrderByAPostThatCarriesTheToken(): void
    {
        $template = (string) file_get_contents(self::DIRECTORY . 'embedded-payment-page.html.twig');

        self::assertStringNotContainsString('<a href="{{ path(\'payzen_embedded_front_abort_payment\'', $template);
        self::assertSame(2, substr_count($template, '<form method="post" action="{{ path(\'payzen_embedded_front_abort_payment\', {orderId: order_id}) }}"'), 'Cancel payment and Try again.');
        self::assertSame(2, substr_count($template, '<input type="hidden" name="_token" value="{{ csrf_token(\'payzen_embedded_abort_payment\') }}">'));
        self::assertStringContainsString('kr-post-url-refused="{{ url(\'payzen_embedded_front_payment_refused\', {orderId: order_id}) }}"', $template);
    }

    /**
     * The Smarty pages of the module say the same: no link cancels an order, and a refused payment lands on the page
     * that changes nothing.
     */
    public function testNoSmartyPageCancelsTheOrderByALink(): void
    {
        foreach (['embedded-payment-page.html', 'order-invoice.js.html'] as $file) {
            $template = (string) file_get_contents(self::DIRECTORY . $file);

            self::assertDoesNotMatchRegularExpression('/(href|kr-post-url-refused)="\{url path="\/payzen-embedded\/cancel-payment/', $template, $file);
            self::assertStringContainsString('kr-post-url-refused="{url path="/payzen-embedded/payment-refused/', $template, $file);
        }

        $page = (string) file_get_contents(self::DIRECTORY . 'embedded-payment-page.html');
        self::assertSame(2, substr_count($page, '<form method="post" action="{url path="/payzen-embedded/cancel-payment/%order_id" order_id=$order_id}"'));
    }
}
