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

namespace PayzenEmbedded\Form;

use PayzenEmbedded\LyraClient\TransactionHistoryReader;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Form\BaseForm;
use Thelia\Model\OrderQuery;

/**
 * Asks for a refund of an order, from the order page of the back-office.
 */
class TransactionRefundForm extends BaseForm
{
    protected function buildForm(): void
    {
        $this->formBuilder
            ->add(
                'order_id',
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new GreaterThan(['value' => 0]),
                    ],
                    'required' => true,
                    'label' => $this->trans('Order ID'),
                ]
            )
            ->add(
                'amount',
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new Callback([$this, 'checkRefundAmount']),
                    ],
                    'required' => true,
                    'label' => $this->trans('Amount to refund'),
                    'label_attr' => [
                        'help' => $this->trans('Up to what the customer paid, less what was already refunded. A transaction not captured yet is cancelled in full.'),
                    ],
                ]
            )
            ->add(
                'comment',
                TextType::class,
                [
                    'constraints' => [
                        new Length(['max' => 255]),
                    ],
                    'required' => false,
                    'label' => $this->trans('Reason'),
                    'label_attr' => [
                        'help' => $this->trans('Written on the refund in the PayZen back-office.'),
                    ],
                ]
            );
    }

    public function checkRefundAmount($value, ExecutionContextInterface $context): void
    {
        $amount = (float) str_replace(',', '.', (string) $value);

        if ($amount <= 0) {
            $context->addViolation($this->trans('The amount to refund should be greater than 0.'));

            return;
        }

        $orderId = (int) ($context->getRoot()->getData()['order_id'] ?? 0);

        if (null === $order = OrderQuery::create()->findPk($orderId)) {
            return;
        }

        $ledger = (new TransactionHistoryReader())->ledgerOf($order);

        if (!$ledger->covers((int) round($amount * 100))) {
            $context->addViolation(
                $this->trans(
                    'The amount to refund should be between 0 and %amount.',
                    ['%amount' => number_format($ledger->refundableAmount() / 100, 2, '.', '')]
                )
            );
        }
    }

    public static function getName(): string
    {
        return 'payzen_embedded_order_refund_form';
    }

    protected function trans($string, $args = [])
    {
        return $this->translator->trans($string, $args, PayzenEmbedded::DOMAIN_NAME);
    }
}
