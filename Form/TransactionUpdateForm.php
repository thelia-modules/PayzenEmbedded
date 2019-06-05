<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia 2 PayZen Embedded payment module                                      */
/*                                                                                   */
/*      Copyright (c) Lyra Networks                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*                                                                                   */
/*************************************************************************************/

namespace PayzenEmbedded\Form;

use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Form\BaseForm;
use Thelia\Model\OrderQuery;

/**
 * PayZen Embedded payment module configuration form
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class TransactionUpdateForm extends BaseForm
{
    protected function buildForm()
    {
        switch (PayzenEmbedded::getConfigValue('validation_mode')) {
            case 'NO' :
                $defaultValidationMode = $this->trans('Automatic');
                break;
            case 'YES' :
                $defaultValidationMode = $this->trans('Manual');
                break;
            default :
                $defaultValidationMode = $this->trans('Default');
                break;
        }

        $this->formBuilder
            ->add(
                'order_id',
                'text',
                [
                    'constraints' => [
                        new NotBlank(),
                        new GreaterThan(['value' => 0]),
                        new Callback([
                            "methods" => [
                                [ $this, "checkOrderAmount" ],
                            ],
                        ])
                    ],
                    'required' => true,
                    'label' => $this->trans('Order ID'),
                ]
            )
            ->add(
                'amount',
                'text',
                [
                    'constraints' => [
                        new NotBlank()
                    ],
                    'required' => true,
                    'label' => $this->trans('New order total amount'),
                    'label_attr' => [
                        'help' => $this->trans('This amount should be greater or equal to the current transaction amount')
                    ]
                ]
            )
            ->add(
                'capture_date',
                'date',
                [
                    'constraints' => [new NotBlank()],
                    'required' => true,
                    'data' => new \DateTime(),
                    'label' => $this->trans('Transaction capture date'),
                    'label_attr' => [
                        'help' => $this->trans(
                            'The date  the transaction will be captured. Leave empty to use the transaction capture delay (currently %days days)',
                            ['%days' => PayzenEmbedded::getConfigValue('capture_delay')]
                        )
                    ]
                ]
            )
            ->add(
                'automatic_validation',
                'checkbox',
                [
                    'constraints' => [],
                    'required' => false,
                    'data' => true,
                    'label' => $this->trans('Automatic validation of the transaction'),
                    'label_attr' => [
                        'help' => $this->trans(
                            'If this box is checked, the transaction will be automatically validated. Otherwise, the transaction validation mode will be used (currently "%mode").',
                            ['%mode' => $defaultValidationMode]
                        )
                    ]
                ]
            );
    }

    public function checkOrderAmount($value, ExecutionContextInterface $context)
    {
        $orderId = \intval($context->getRoot()->getData()['order_id']);

        if (null !== $order = OrderQuery::create()->findPk($orderId)) {
            if (\floatval($value> $order->getTotalAmount())) {
                $context->addViolation(
                    $this->trans("The amount should be less or equal to the order current amount.")
                );
            }
        }
    }

    public function getName()
    {
        return 'payzen_embedded_order_edit_form';
    }

    protected function trans($string, $args = [])
    {
        return $this->translator->trans($string, $args, PayzenEmbedded::DOMAIN_NAME);
    }
}
