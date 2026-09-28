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

use PayzenEmbedded\Event\TransactionRefundEvent;
use PayzenEmbedded\Event\TransactionUpdateEvent;
use PayzenEmbedded\Form\TransactionGetForm;
use PayzenEmbedded\Form\TransactionRefundForm;
use PayzenEmbedded\Form\TransactionUpdateForm;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\LyraTransactionGetWrapper;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\OrderQuery;
use Thelia\Tools\URL;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Payzen payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
#[Route('/admin/module/payzen-embedded', name: 'payzen_embedded_order_edit_')]
class OrderEditController extends BaseAdminController
{
    #[Route('/update-transaction/{orderId}', name: 'update_transaction', methods: 'POST')]
    public function updateTransaction(EventDispatcherInterface $dispatcher, Translator $translator, $orderId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        $errorMsg = $ex = false;

        // Create the Form from the request
        $updateForm = $this->createForm(TransactionUpdateForm::getName());

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

                $this->addFlash('success', $translator->trans('The transaction was updated.', [], PayzenEmbedded::DOMAIN_NAME));
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

            $this->addFlash('danger', $errorMsg);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl("admin/order/update/$orderId") . '#payzen-embedded');
    }

    #[Route('/refund-transaction/{orderId}', name: 'refund_transaction', methods: 'POST')]
    public function refundTransaction(EventDispatcherInterface $dispatcher, Translator $translator, $orderId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        $errorMsg = $ex = false;

        $refundForm = $this->createForm(TransactionRefundForm::getName());

        try {
            $form = $this->validateForm($refundForm, "POST");

            $data = $form->getData();

            if (null !== $order = OrderQuery::create()->findPk($orderId)) {
                $event = new TransactionRefundEvent(
                    (int) $order->getId(),
                    (float) str_replace(',', '.', (string) $data['amount']),
                    $data['comment'] ?? null
                );

                $dispatcher->dispatch($event, PayzenEmbedded::TRANSACTION_REFUND_EVENT);

                $this->addFlash('success', $this->refundOutcomeMessage($translator, $event));

                $this->adminLogAppend(
                    "payzen-embedded.order-refund",
                    AccessManager::UPDATE,
                    sprintf(
                        "Order %d: %s of %s",
                        $order->getId(),
                        $event->getOutcome()?->value ?? 'no outcome',
                        number_format($event->getAmount(), 2, '.', '')
                    )
                );
            }
        } catch (FormValidationException $ex) {
            $errorMsg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            $errorMsg = $ex->getMessage();
        }

        if ($errorMsg) {
            // The Smarty back-office reads the parser context, the Twig one reads the flashes.
            $this->setupFormErrorContext(
                $translator->trans("PayzenEmbedded refund transaction", [], PayzenEmbedded::DOMAIN_NAME),
                $errorMsg,
                $refundForm,
                $ex
            );

            $this->addFlash('danger', $errorMsg);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl("admin/order/update/$orderId") . '#payzen-embedded');
    }

    private function refundOutcomeMessage(Translator $translator, TransactionRefundEvent $event): string
    {
        $amount = number_format($event->getAmount(), 2, '.', ' ');

        return match ($event->getOutcome()) {
            RefundOutcome::Cancelled => $translator->trans('The transaction was cancelled before its capture, the order is cancelled.', [], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::Refunded => $translator->trans('The order was refunded in full.', [], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::PartiallyRefunded => $translator->trans('%amount was refunded, the rest can still be refunded.', ['%amount' => $amount], PayzenEmbedded::DOMAIN_NAME),
            null => $translator->trans('The refund request was sent.', [], PayzenEmbedded::DOMAIN_NAME),
        };
    }

    #[Route('/refresh-transaction/{orderId}', name: 'refresh_transaction', methods: 'POST')]
    public function refreshTransaction(EventDispatcherInterface $dispatcher, Translator $translator, $orderId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        $errorMsg = $ex = false;

        // Create the Form from the request
        $getForm = $this->createForm(TransactionGetForm::getName());

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

                $this->addFlash('success', $translator->trans('The transaction history was refreshed.', [], PayzenEmbedded::DOMAIN_NAME));
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

            $this->addFlash('danger', $errorMsg);
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl("admin/order/update/$orderId") . '#payzen-embedded');
    }
}
