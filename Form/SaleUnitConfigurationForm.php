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

namespace PayzenEmbedded\Form;

use PayzenEmbedded\LyraClient\PaymentContract;
use PayzenEmbedded\PayzenEmbedded;
use PayzenEmbedded\Service\SaleUnitContractSettings;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Thelia\Form\BaseForm;

/**
 * The PayZen contract of one sale unit: the settings it gives itself, the others being left to the
 * national configuration. Every field may be empty. A password or a key is never shown back.
 */
class SaleUnitConfigurationForm extends BaseForm
{
    protected function buildForm(): void
    {
        $saleUnitId = (int) $this->request->query->get('sale_unit_id', 0);
        $own = $saleUnitId > 0 ? SaleUnitContractSettings::ownValues($saleUnitId) : [];

        $this->formBuilder
            ->add('sale_unit_id', HiddenType::class, [
                'constraints' => [new NotBlank()],
                'data' => $saleUnitId > 0 ? $saleUnitId : null,
            ])
            ->add('mode', ChoiceType::class, [
                'required' => false,
                'placeholder' => $this->trans('National value'),
                'constraints' => [new Choice(choices: ['TEST', 'PRODUCTION_RESTRICTED', 'PRODUCTION'])],
                'choices' => [
                    $this->trans('Test') => 'TEST',
                    $this->trans('Restricted production') => 'PRODUCTION_RESTRICTED',
                    $this->trans('Production') => 'PRODUCTION',
                ],
                'label' => $this->trans('Operation Mode'),
                'data' => $own['mode'] ?? null,
                'label_attr' => ['help' => $this->trans('Empty: the national value is used')],
            ])
            ->add('site_id', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'label' => $this->trans('Username'),
                'data' => $own['site_id'] ?? null,
                'label_attr' => ['help' => $this->trans('Empty: the national value is used')],
            ])
            ->add('webservice_endpoint', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255), new Url(requireTld: false)],
                'label' => $this->trans('Web Services end point'),
                'data' => $own['webservice_endpoint'] ?? null,
                'label_attr' => ['help' => $this->trans('Empty: the national value is used')],
            ]);

        $labels = [
            'test_password' => $this->trans('Test password'),
            'javascript_test_key' => $this->trans('Public test key'),
            'signature_test_key' => $this->trans('Server to server test key'),
            'production_password' => $this->trans('Production password'),
            'javascript_production_key' => $this->trans('Public production key'),
            'signature_production_key' => $this->trans('Server to server production key'),
        ];

        foreach (PaymentContract::SECRET_SETTINGS as $setting) {
            $this->formBuilder
                ->add($setting, PasswordType::class, [
                    'required' => false,
                    'always_empty' => true,
                    'constraints' => [new Length(max: 255)],
                    'label' => $labels[$setting],
                    'attr' => ['autocomplete' => 'new-password'],
                    'label_attr' => [
                        'help' => isset($own[$setting]) && '' !== $own[$setting]
                            ? $this->trans('Set for this sale unit. Leave empty to keep it, or type a new value.')
                            : $this->trans('Empty: the national value is used'),
                    ],
                ])
                ->add(SaleUnitContractSettings::removalFieldOf($setting), CheckboxType::class, [
                    'required' => false,
                    'label' => $this->trans('Use the national value'),
                ]);
        }
    }

    protected function trans($string, $args = []): string
    {
        return $this->translator->trans($string, $args, PayzenEmbedded::DOMAIN_NAME);
    }
}
