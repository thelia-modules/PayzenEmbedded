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
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Form\BaseForm;

/**
 * PayZen Embedded payment module configuration form
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class ConfigurationForm extends BaseForm
{
    protected function buildForm()
    {
        $this->formBuilder
            // -- Username and passwords -------------------------------------------------------------------------------
            ->add(
                'site_id',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Username'),
                    'data' => PayzenEmbedded::getConfigValue('site_id', '69876357'),
                    'label_attr' => array(
                        'help' => $this->trans('This is your shop identifier. You received this information when you subscribed to PayZen')
                    )
                )
            )
            ->add(
                'test_password',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Test password'),
                    'data' => PayzenEmbedded::getConfigValue('test_password', 'testpassword_DEMOPRIVATEKEY23G4475zXZQ2UA5x7M'),
                    'label_attr' => array(
                        'help' => $this->trans('The test password. This is the "Test Password" in the PayZen Expert Back Office')
                    )
                )
            )
            ->add(
                'production_password',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Production password'),
                    'data' => PayzenEmbedded::getConfigValue('production_password', $this->trans('To be generated')),
                    'label_attr' => array(
                        'help' => $this->trans('The password used in production. This is the "Production Password" in the PayZen Expert Back Office')
                    )
                )
            )
            // -- Javascript public keys -------------------------------------------------------------------------------
            ->add(
                'javascript_test_key',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Public test key'),
                    'data' => PayzenEmbedded::getConfigValue('javascript_test_key', '69876357:testpublickey_DEMOPUBLICKEY95me92597fd28tGD4r5'),
                    'label_attr' => array(
                        'help' => $this->trans('This key is the "Public test key" in the PayZen Expert Back Office')
                    )
                )
            )
            ->add(
                'javascript_production_key',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Public production key'),
                    'data' => PayzenEmbedded::getConfigValue('javascript_production_key', $this->trans('To be generated')),
                    'label_attr' => array(
                        'help' => $this->trans('This key is the "Public production key" in the PayZen Expert Back Office')
                    )
                )
            )
            // -- Signature keys ---------------------------------------------------------------------------------------
            ->add(
                'signature_test_key',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Server to server test key'),
                    'data' => PayzenEmbedded::getConfigValue('signature_test_key', '38453613e7f44dc58732bad3dca2bca3'),
                    'label_attr' => array(
                        'help' => $this->trans('This key is the "HMAC-SHA-256 test key" in the PayZen Expert Back Office')
                    )
                )
            )
            ->add(
                'signature_production_key',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Server to server production key'),
                    'data' => PayzenEmbedded::getConfigValue('signature_production_key', $this->trans('To be generated')),
                    'label_attr' => array(
                        'help' => $this->trans('This key is the "HMAC-SHA-256 production key" in the PayZen Expert Back Office')
                    )
                )
            )

            ->add(
                'webservice_endpoint',
                'text',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'label' => $this->trans('Web Services end point'),
                    'data' => PayzenEmbedded::getConfigValue('webservice_endpoint', 'https://api.payzen.eu'),
                    'label_attr' => array(
                        'help' => $this->trans('This is the URL of the web service. You should change this value if you\'re usin a specific Lyra implementation instead of PayZen')
                    )
                )
            )
            ->add(
                'mode',
                'choice',
                array(
                    'constraints' => array(new NotBlank()),
                    'required' => true,
                    'choices' => array(
                        'TEST' => $this->trans('Test'),
                        'PRODUCTION_RESTRICTED' => $this->trans('Restricted production'),
                        'PRODUCTION' => $this->trans('Production'),
                    ),
                    'label' => $this->trans('Operation Mode'),
                    'data' => PayzenEmbedded::getConfigValue('mode', 'TEST'),
                    'label_attr' => array(
                        'help' => $this->trans('Test or production mode')
                    )
                )
            )
            ->add(
                'allowed_ip_list',
                'textarea',
                array(
                    'required' => false,
                    'label' => $this->trans('Allowed IPs in test or restricted production modes'),
                    'data' => PayzenEmbedded::getConfigValue('allowed_ip_list', ''),
                    'label_attr' => array(
                        'help' => $this->trans(
                            'List of IP addresses allowed to use this payment on the front-office when in test or restricted production mode (your current IP is %ip). One address per line',
                            array('%ip' => $this->getRequest()->getClientIp())
                        ),
                        'rows' => 3
                    )
                )
            )
            ->add(
                'payment_source',
                'choice',
                array(
                    'required' => false,
                    'choices' => array(
                        '' => $this->trans('None'),
                        'EC'    => $this->trans('E-Commerce: transaction where the payment method data is directly filled in by the buyer.'),
                        'MOTO'  => $this->trans('MAIL OR TELEPHONE ORDER: payment processed by an operator following a MOTO order.'),
                        'CC'    => $this->trans('Call Center: payment made through a call center.'),
                        'OTHER' => $this->trans('Other: payment made through a different source, e.g. Back Office.'),
                    ),
                    'label' => $this->trans('Payement source'),
                    'data' => PayzenEmbedded::getConfigValue('payment_source', ''),
                    'label_attr' => array(
                        'help' => $this->trans(
                            'This information is passed with the payment request, and will be available in your PayZen back-office'
                        )
                    )
                )
            )
            ->add(
                'allow_one_click_payments',
                'checkbox',
                array(
                    'constraints' => [],
                    'required' => false,
                    'label' => $this->trans('Allow 1-click payments'),
                    'data' => boolval(PayzenEmbedded::getConfigValue('allow_one_click_payments', true)),
                    'label_attr' => array(
                        'help' => $this->trans('If this box is checked, your customers could register they card details, and no longer have to fill in bank details during subsequent payments')
                    )
                )
            )
            ->add(
                'popup_mode',
                'checkbox',
                array(
                    'constraints' => [],
                    'required' => false,
                    'label' => $this->trans('Use popup form'),
                    'data' => boolval(PayzenEmbedded::getConfigValue('popup_mode', true)),
                    'label_attr' => array(
                        'help' => $this->trans('If this box is checked, the credit card details form will be displayed in a popup on the invoice page instead of opening a new page.')
                    )
                )
            )
            ->add(
                'capture_delay',
                'number',
                array(
                    'constraints' => array(
                        new NotBlank(),
                        new GreaterThanOrEqual(array('value' => 0))
                    ),
                    'required' => true,
                    'label' => $this->trans('Capture delay'),
                    'data' => PayzenEmbedded::getConfigValue('capture_delay', '0'),
                    'label_attr' => array(
                        'help' => $this->trans('Delay to be applied to the capture date. (in days)')
                    )
                )
            )
            ->add(
                'validation_mode',
                'choice',
                array(
                    'required' => false,
                    'choices' => array(
                        '' => $this->trans('Default'),
                        'NO' => $this->trans('Automatic'),
                        'YES' => $this->trans('Manual'),
                    ),
                    'label' => $this->trans('Payment validation'),
                    'data' => PayzenEmbedded::getConfigValue('validation_mode', ''),
                    'label_attr' => array(
                        'help' => $this->trans(
                            'If manual is selected, you will have to confirm payments manually in your bank back-office'
                        )
                    )
                )
            )
            ->add(
                'strong_authentication',
                'choice',
                array(
                    'required' => false,
                    'choices' => array(
                        'AUTO' => $this->trans('Default'),
                        'DISABLED' => $this->trans('Disabled'),
                        'ENABLED' => $this->trans('Enabled'),
                    ),
                    'label' => $this->trans('Strong authentication'),
                    'data' => PayzenEmbedded::getConfigValue('strong_authentication', ''),
                    'label_attr' => array(
                        'help' => $this->trans(
                            'Enable or disable strong authentication for the payment method (such as 3D Secure)'
                        )
                    )
                )
            )

            ->add(
                'minimum_amount',
                'number',
                array(
                    'constraints' => array(
                        new NotBlank(),
                        new GreaterThanOrEqual(array('value' => 0))
                    ),
                    'required' => true,
                    'label' => $this->trans('Minimum order total'),
                    'data' => PayzenEmbedded::getConfigValue('minimum_amount', 0),
                    'label_attr' => array(
                        'for' => 'minimum_amount',
                        'help' => $this->trans('Minimum order total in the default currency for which this payment method is available. Enter 0 for no minimum')
                    ),
                    'attr' => [
                        'step' => 'any'
                    ]
                )
            )
            ->add(
                'maximum_amount',
                'number',
                array(
                    'constraints' => array(
                        new NotBlank(),
                        new GreaterThanOrEqual(array('value' => 0))
                    ),
                    'required' => true,
                    'label' => $this->trans('Maximum order total'),
                    'data' => PayzenEmbedded::getConfigValue('maximum_amount', 0),
                    'label_attr' => array(
                        'for' => 'maximum_amount',
                        'help' => $this->trans('Maximum order total in the default currency for which this payment method is available. Enter 0 for no maximum')
                    ),
                    'attr' => [
                        'step' => 'any'
                    ]
                )
            )
            ->add(
                'send_confirmation_message_only_if_paid',
                'checkbox',
                [
                    'value' => 1,
                    'required' => false,
                    'label' => $this->trans('Send order confirmation on payment success'),
                    'data' => boolval(PayzenEmbedded::getConfigValue('send_confirmation_message_only_if_paid', true)),
                    'label_attr' => [
                        'help' => $this->trans(
                            'If checked, the order confirmation message is sent to the customer only when the payment is successful. The order notification is always sent to the shop administrator'
                        )
                    ]
                ]
            )
            ->add(
                'send_payment_confirmation_message',
                'checkbox',
                [
                    'value' => 1,
                    'required' => false,
                    'label' => $this->trans('Send a payment confirmation e-mail'),
                    'data' => boolval(PayzenEmbedded::getConfigValue('send_payment_confirmation_message', true)),
                    'label_attr' => [
                        'help' => $this->trans(
                            'If checked, a payment confirmation e-mail is sent to the customer.'
                        )
                    ]
                ]
            )
        ;
    }

    protected function trans($string, $args = [])
    {
        return $this->translator->trans($string, $args, PayzenEmbedded::DOMAIN_NAME);
    }

    public function getName()
    {
        return 'payzen_embedded_configuration_form';
    }
}
