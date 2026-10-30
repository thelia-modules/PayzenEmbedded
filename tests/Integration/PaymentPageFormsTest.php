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

namespace PayzenEmbedded\Tests\Integration;

use PayzenEmbedded\Controller\FrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Test\IntegrationTestCase;

/**
 * The payment page, rendered by the active front theme the way the module renders it: whether the form is shown or
 * could not be, the order is cancelled by a POST form whose token the session accepts, and a refused payment is sent
 * to the page that changes nothing.
 */
final class PaymentPageFormsTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function outcomesOfThePaymentForm(): iterable
    {
        yield 'form shown' => [true];
        yield 'form not available' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outcomesOfThePaymentForm')]
    public function testTheOrderIsCancelledByAFormThatCarriesATokenOfTheSession(bool $available): void
    {
        $request = Request::create('http://localhost/order/pay');
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);

        $html = $this->render($available, 42);

        self::assertSame(1, preg_match_all('#<form method="post" action="([^"]+)"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)">#', $html, $forms));
        self::assertSame('/payzen-embedded/cancel-payment/42', $forms[1][0]);
        self::assertTrue(
            static::getContainer()->get('security.csrf.token_manager')->isTokenValid(new CsrfToken(FrontController::ABORT_PAYMENT_TOKEN_ID, html_entity_decode($forms[2][0]))),
            'The session accepts the token of the form.',
        );
        self::assertStringNotContainsString('<a href="/payzen-embedded/cancel-payment/', $html);
        self::assertStringContainsString('kr-post-url-refused="http://localhost/payzen-embedded/payment-refused/42"', $html);
    }

    private function render(bool $available, int $orderId): string
    {
        $template = static::getContainer()->get('thelia.template_helper')->getActiveFrontTemplate();
        $parser = static::getContainer()->get('thelia.parser.resolver')->getParser($template->getAbsolutePath(), null);
        $parser->setTemplateDefinition($template, true);

        return $parser->render('payzen-embedded/embedded-payment-page.html.twig', [
            'order_id' => $orderId,
            'cart_count' => 1,
            'smart_form' => false,
            'card_form_expanded' => true,
            'static_base_url' => null,
            'success' => $available,
            'form_token' => 'form-token',
            'public_key' => 'public-key',
            'errorCode' => null,
            'errorMessage' => null,
            'detailedErrorCode' => null,
            'detailedErrorMessage' => null,
        ]);
    }
}
