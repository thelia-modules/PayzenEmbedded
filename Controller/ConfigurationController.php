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

/**
 * The configuration controller, tu update module's configuration.
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 23/05/2019 17:12
 */
namespace PayzenEmbedded\Controller;

use PayzenEmbedded\Form\ConfigurationForm;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Tools\URL;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 *
 * @Route("/admin/module/payzen-embedded", name="payzen_embedded_")
 */
class ConfigurationController extends BaseAdminController
{

    /**
     * @Route("/configure", name="configure", methods="POST")
     * @return mixed an HTTP response, or
     */
    public function configure(RequestStack $requestStack, Translator $translator)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        // Create the Form from the request
        $configurationForm = $this->createForm(ConfigurationForm::getName());

        try {
            // Check the form against constraints violations
            $form = $this->validateForm($configurationForm, "POST");

            // Get the form field values
            $data = $form->getData();

            // Save them in the module configuration data. We may store some useless data, but we don't care.
            foreach ($data as $name => $value) {
                if (is_array($value)) {
                    $value = implode(';', $value);
                }

                PayzenEmbedded::setConfigValue($name, $value);
            }

            // Log configuration modification
            $this->adminLogAppend(
                "payzen-embedded.configuration.message",
                AccessManager::UPDATE,
                sprintf("PayzenEmbedded configuration updated")
            );

            // Redirect to the success URL,
            if ($requestStack->getCurrentRequest()->get('save_mode') == 'stay') {
                // If we have to stay on the same page, redisplay the configuration page/
                $route = '/admin/module/PayzenEmbedded';
            } else {
                // If we have to close the page, go back to the module back-office page.
                $route = '/admin/modules';
            }

            return $this->generateRedirect(URL::getInstance()->absoluteUrl($route));
        } catch (FormValidationException $ex) {
            // Form cannot be validated. Create the error message using the BaseAdminController helper method.
            $error_msg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            // Any other error
             $error_msg = $ex->getMessage();
        }

        // At this point, the form has errors, and should be redisplayed.
        // Set up the Form error context, to make error information available in the template.
        $this->setupFormErrorContext(
            $translator->trans("PayzenEmbedded configuration", [], PayzenEmbedded::DOMAIN_NAME),
            $error_msg,
            $configurationForm,
            $ex
        );

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/PayzenEmbedded'));
    }
}
