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
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Thelia\Model\NewsletterQuery;
use Thelia\Model\OrderQuery;

/**
 * PayZen Embedded payment module get transaction info form
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class TransactionGetForm extends BaseForm
{
    protected function buildForm()
    {
        $this->formBuilder
            ->add(
                'order_id',
                'text',
                [
                    'constraints' => [
                        new NotBlank(),
                        new GreaterThan(['value' => 0])
                    ],
                    'required' => true,
                    'label' => $this->trans('Order ID'),
                ]
            );
    }


    public function getName()
    {
        return 'payzen_embedded_get_form';
    }

    protected function trans($string, $args = [])
    {
        return $this->translator->trans($string, $args, PayzenEmbedded::DOMAIN_NAME);
    }
}
