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

use PayzenEmbedded\Form\TransactionUpdateForm;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The update form of the order page moves the capture date forward only: a date already gone
 * would let the authorization lapse without its capture. Boots Thelia, see
 * phpunit.integration.xml.dist.
 */
final class TransactionUpdateFormTest extends IntegrationTestCase
{
    public function testACaptureDateAlreadyGoneIsRefused(): void
    {
        self::assertCount(1, $this->errorsOfTheCaptureDate((new \DateTimeImmutable('yesterday'))->format('Y-m-d')));
    }

    public function testTodayIsACaptureDate(): void
    {
        self::assertCount(0, $this->errorsOfTheCaptureDate((new \DateTimeImmutable('today'))->format('Y-m-d')));
    }

    private function errorsOfTheCaptureDate(string $date): iterable
    {
        $form = $this->getService(TheliaFormFactory::class)
            ->createForm(TransactionUpdateForm::class, FormType::class, [], ['csrf_protection' => false])
            ->getForm();

        $form->submit(['order_id' => '1', 'amount' => '1', 'capture_date' => $date]);

        return $form->get('capture_date')->getErrors();
    }
}
