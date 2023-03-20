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
 * The configuration controller, tu update module's configuration..
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 23/05/2019 17:12
 */
namespace PayzenEmbedded\Controller;

use PayzenEmbedded\Event\TransactionUpdateEvent;
use PayzenEmbedded\LyraClient\LyraTransactionGetWrapper;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\OrderQuery;
use Thelia\Tools\URL;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
#[Route('/admin/module/payzen-embedded', name: 'payzen_embedded_order_edit_')]
class OrderEditController extends BaseAdminController
{
    #[Route('/update-transaction/{orderId}', name: 'update_transaction', methods: 'POST')]
    public function updateTransaction(EventDispatcher $dispatcher, Translator $translator, $orderId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        $errorMsg = $ex = false;

        // Create the Form from the request
        $updateForm = $this->createForm('payzen_embedded.order-edit.form');

        try {
            // Check the form against constraints violations
            $form = $this->validateForm($updateForm, "POST");

            // Get the form field values
            $data = $form->getData();

            if (null !== $order = OrderQuery::create()->findPk($orderId)) {
                $dispatcher->dispatch(
                    (new TransactionUpdateEvent($orderId))
                        ->setAmount($data['amount'])
                        ->setExpectedCaptureDate($data['capture_date'])
                        ->setManualValidation(! $data['automatic_validation']),
                PayzenEmbedded::TRANSACTION_UPDATE_EVENT);

                // Log order modification
                $this->adminLogAppend(
                    "payzen-embedded.order-update",
                    AccessManager::UPDATE,
                    sprintf("Order %d updated", $order->getId())
                );
            }
        } catch (FormValidationException $ex) {
            // Form cannot be validated. Create the error message using the BaseAdminController helper method.
            $errorMsg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            // Any other error
             $errorMsg = $ex->getMessage();
        }

        if ($errorMsg) {
            $this->setupFormErrorContext(
                $translator->trans("PayzenEmbedded update transaction", [], PayzenEmbedded::DOMAIN_NAME),
                $errorMsg,
                $updateForm,
                $ex
            );
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl("admin/order/update/$orderId") . '#payzen-embedded');
    }

    #[Route('/refresh-transaction/{orderId}', name: 'refresh_transaction', methods: 'POST')]
    public function refreshTransaction(EventDispatcher $dispatcher, Translator $translator, $orderId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        $errorMsg = $ex = false;

        // Create the Form from the request
        $getForm = $this->createForm('payzen_embedded.get.form');

        try {
            // Check the form against constraints violations
            $this->validateForm($getForm, "POST");

            if (null !== $order = OrderQuery::create()->findPk($orderId)) {
                // Call the get service
                $lyraClient = new LyraTransactionGetWrapper($dispatcher);
                $lyraClient->getTransaction($order);

                // Log order modification
                $this->adminLogAppend(
                    "payzen-embedded.order-update",
                    AccessManager::UPDATE,
                    sprintf("Order %d refreshed", $order->getId())
                );
            }
        } catch (\Exception $ex) {
            // Any other error
            $errorMsg = $ex->getMessage();
        }

        if ($errorMsg) {
            $this->setupFormErrorContext(
                $translator->trans("PayzenEmbedded refresh transaction", [], PayzenEmbedded::DOMAIN_NAME),
                $errorMsg,
                $getForm,
                $ex
            );
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl("admin/order/update/$orderId") . '#payzen-embedded');
    }
}
