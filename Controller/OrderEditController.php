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
use PayzenEmbedded\LyraClient\OrderStatusNotUpdatedException;
use PayzenEmbedded\LyraClient\RefundAmount;
use PayzenEmbedded\LyraClient\RefundOutcome;
use PayzenEmbedded\LyraClient\RefundOutcomeUnknownException;
use Thelia\Exception\TheliaProcessException;
use Thelia\Log\Tlog;
use PayzenEmbedded\LyraClient\LyraClientWrapper;
use PayzenEmbedded\LyraClient\LyraTransactionGetWrapper;
use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
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
    #[Route('/update-transaction/{orderId}', name: 'update_transaction', requirements: ['orderId' => '\d+'], methods: 'POST')]
    public function updateTransaction(EventDispatcherInterface $dispatcher, Translator $translator, int $orderId)
    {
        // Lowering or validating a payment is an operation on the order, not only on the module.
        if (null !== $response = $this->checkAuth([AdminResources::MODULE, AdminResources::ORDER], 'PayzenEmbedded', AccessManager::UPDATE)) {
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
                $event = (new TransactionUpdateEvent($orderId))
                    ->setAmount($data['amount'])
                    ->setExpectedCaptureDate($data['capture_date'])
                    ->setManualValidation(! $data['automatic_validation']);

                $dispatcher->dispatch($event, PayzenEmbedded::TRANSACTION_UPDATE_EVENT);

                // Nothing handled the event (a listener of a higher priority stopped it), or the
                // platform answered a status the module does not know: saying "updated" would
                // send the administrator away from a transaction that did not change.
                if (null === $event->getPaymentStatus() || LyraClientWrapper::PAYMENT_STATUS_ERROR === $event->getPaymentStatus()) {
                    throw new TheliaProcessException($translator->trans('The transaction was not updated: nothing handled the request, or PayZen answered an unexpected status.', [], PayzenEmbedded::DOMAIN_NAME));
                }

                $this->addFlash('success', $translator->trans('The transaction was updated.', [], PayzenEmbedded::DOMAIN_NAME));

                // The platform has answered: a failure to write the trace is not a failed update.
                try {
                    $this->adminLogAppend("payzen-embedded.order-update", AccessManager::UPDATE, sprintf("Order %d updated", $order->getId()), (int) $order->getId());
                } catch (\Throwable $logFailure) {
                    Tlog::getInstance()->addError(sprintf('PayZen transaction update of order %d done, admin log failed: %s', $orderId, $logFailure->getMessage()));
                }
            }
        } catch (FormValidationException $ex) {
            // Form cannot be validated. Create the error message using the BaseAdminController helper method.
            $errorMsg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (TheliaProcessException $ex) {
            $errorMsg = mb_substr($ex->getMessage(), 0, 500);
        } catch (\Exception $ex) {
            Tlog::getInstance()->addError(sprintf('PayZen transaction update of order %d failed: %s', $orderId, $ex->getMessage()));
            $errorMsg = $translator->trans('The transaction update could not be sent to PayZen, see the logs.', [], PayzenEmbedded::DOMAIN_NAME);
        }

        if ($errorMsg) {
            // The administrator is told the refusal whatever happens to the trace.
            try {
                $this->adminLogAppend(
                    "payzen-embedded.order-update",
                    AccessManager::UPDATE,
                    sprintf("Order %d: transaction update failed: %s", $orderId, mb_substr($errorMsg, 0, 500)),
                    $orderId
                );
            } catch (\Throwable $logFailure) {
                Tlog::getInstance()->addError(sprintf('PayZen transaction update of order %d refused, admin log failed: %s', $orderId, $logFailure->getMessage()));
            }

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

    #[Route('/refund-transaction/{orderId}', name: 'refund_transaction', requirements: ['orderId' => '\d+'], methods: 'POST')]
    public function refundTransaction(EventDispatcherInterface $dispatcher, Translator $translator, int $orderId)
    {
        // Giving money back is an operation on the order, not only on the module.
        if (null !== $response = $this->checkAuth([AdminResources::MODULE, AdminResources::ORDER], 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        $errorMsg = $ex = false;
        $data = [];

        $refundForm = $this->createForm(TransactionRefundForm::getName());

        try {
            $form = $this->validateForm($refundForm, "POST");

            $data = $form->getData();

            if (null === $order = OrderQuery::create()->findPk($orderId)) {
                throw new TheliaProcessException($translator->trans('Undefined order.', [], PayzenEmbedded::DOMAIN_NAME));
            }

            // The route names the order; the form field only has to agree with it.
            if ((int) $data['order_id'] !== $orderId) {
                throw new TheliaProcessException($translator->trans('The order of the form does not match the order of the page.', [], PayzenEmbedded::DOMAIN_NAME));
            }

            $currencyCode = strtoupper($order->getCurrency()->getCode());
            $amount = RefundAmount::fromInput((string) $data['amount'], $currencyCode);

            if (null === $amount) {
                throw new TheliaProcessException($translator->trans('The amount to refund should be a positive number with at most %decimals decimals, such as %example.', [
                    '%decimals' => RefundAmount::decimals($currencyCode),
                    '%example' => RefundAmount::format(1250, $currencyCode),
                ], PayzenEmbedded::DOMAIN_NAME));
            }

            $admin = $this->getSecurityContext()->getAdminUser();

            // What the page showed as refunded so far: a refund it did not show is refused, see the wrapper.
            $expectedRefundedAmount = isset($data['refunded_amount']) && '' !== (string) $data['refunded_amount']
                ? (int) $data['refunded_amount']
                : null;

            $event = new TransactionRefundEvent(
                (int) $order->getId(),
                $amount,
                $data['comment'] ?? null,
                $admin?->getId(),
                $expectedRefundedAmount
            );

            try {
                $dispatcher->dispatch($event, PayzenEmbedded::TRANSACTION_REFUND_EVENT);

                $this->addFlash('success', $this->refundOutcomeMessage($translator, $event->getOutcome(), $amount, $currencyCode));
            } catch (OrderStatusNotUpdatedException $statusFailure) {
                // The money moved: said as such, with the order to check.
                $event->setOutcome($statusFailure->outcome);
                $this->addFlash('warning', $statusFailure->getMessage());
            } catch (RefundOutcomeUnknownException $unknown) {
                // The platform answered: the next attempt refreshes the order before anything else.
                $this->addFlash('warning', $unknown->getMessage());
            }

            // From here on the platform has answered: a failure to write the trace is not a failed refund.
            try {
                $this->adminLogAppend(
                    "payzen-embedded.order-refund",
                    AccessManager::UPDATE,
                    sprintf(
                        "Order %d: %s of %s %s",
                        $order->getId(),
                        $event->getOutcome()->value ?? 'no outcome',
                        RefundAmount::format($amount, $currencyCode),
                        $currencyCode
                    ),
                    (int) $order->getId()
                );
            } catch (\Throwable $logFailure) {
                Tlog::getInstance()->addError(sprintf('PayZen refund of order %d done, admin log failed: %s', $orderId, $logFailure->getMessage()));
            }
        } catch (FormValidationException $ex) {
            $errorMsg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (TheliaProcessException $ex) {
            // A refusal the administrator can act on: the platform's or the module's own.
            $errorMsg = mb_substr($ex->getMessage(), 0, 500);
        } catch (\Exception $ex) {
            // Anything else stays in the log: a transport or database error is not for the screen.
            Tlog::getInstance()->addError(sprintf('PayZen refund of order %d failed: %s', $orderId, $ex->getMessage()));
            $errorMsg = $translator->trans('The refund could not be sent to PayZen, see the logs.', [], PayzenEmbedded::DOMAIN_NAME);
        }

        if ($errorMsg) {
            // A failed attempt at giving money back is worth a trace too, and the administrator is
            // told the refusal whatever happens to that trace.
            try {
                $this->adminLogAppend(
                    "payzen-embedded.order-refund",
                    AccessManager::UPDATE,
                    sprintf("Order %d: refund of %s failed: %s", $orderId, $this->typedAmount($data), mb_substr($errorMsg, 0, 500)),
                    $orderId
                );
            } catch (\Throwable $logFailure) {
                Tlog::getInstance()->addError(sprintf('PayZen refund of order %d refused, admin log failed: %s', $orderId, $logFailure->getMessage()));
            }

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

    private function refundOutcomeMessage(Translator $translator, ?RefundOutcome $outcome, int $amount, string $currencyCode): string
    {
        $formattedAmount = RefundAmount::format($amount, $currencyCode) . ' ' . $currencyCode;

        return match ($outcome) {
            RefundOutcome::Cancelled => $translator->trans('The transaction was cancelled before its capture, the order is cancelled.', [], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::Refunded => $translator->trans('The order was refunded in full.', [], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::PartiallyRefunded => $translator->trans('%amount was refunded, the rest can still be refunded.', ['%amount' => $formattedAmount], PayzenEmbedded::DOMAIN_NAME),
            RefundOutcome::Pending => $translator->trans('The refund of %amount was accepted by PayZen and is being processed; the order is left as it is.', ['%amount' => $formattedAmount], PayzenEmbedded::DOMAIN_NAME),
            // Nothing handled the event, for instance a listener of a higher priority stopped it:
            // no call was made, and saying "sent" would send the administrator away.
            null => throw new TheliaProcessException($translator->trans('No refund was made: nothing handled the request.', [], PayzenEmbedded::DOMAIN_NAME)),
        };
    }

    /**
     * What the administrator typed as an amount, for the log: digits and separators only, bounded.
     */
    private function typedAmount(array $data): string
    {
        $raw = $data['amount'] ?? null;

        if (null === $raw) {
            $posted = $this->getRequest()->request->all()[TransactionRefundForm::getName()] ?? null;
            $raw = \is_array($posted) ? ($posted['amount'] ?? null) : null;
        }

        if (!\is_scalar($raw)) {
            return '?';
        }

        $clean = mb_substr((string) preg_replace('/[^\d., ]/', '', (string) $raw), 0, 20);

        return '' !== $clean ? $clean : '?';
    }

    #[Route('/refresh-transaction/{orderId}', name: 'refresh_transaction', requirements: ['orderId' => '\d+'], methods: 'POST')]
    public function refreshTransaction(EventDispatcherInterface $dispatcher, Translator $translator, int $orderId, ?LockFactory $lockFactory = null)
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE, AdminResources::ORDER], 'PayzenEmbedded', AccessManager::UPDATE)) {
            return $response;
        }

        $errorMsg = $ex = false;

        // Create the Form from the request
        $getForm = $this->createForm(TransactionGetForm::getName());

        try {
            // Check the form against constraints violations
            $this->validateForm($getForm, "POST");

            if (null !== $order = OrderQuery::create()->findPk($orderId)) {
                // The same lock as the refund: the two never write the history at the same time.
                $lyraClient = new LyraTransactionGetWrapper($dispatcher, $lockFactory);
                $lyraClient->getTransaction($order);

                $this->addFlash('success', $translator->trans('The transaction history was refreshed.', [], PayzenEmbedded::DOMAIN_NAME));

                // The platform's list fills the history, but only a notification ties the order to
                // its transaction: said as such, or the administrator reads a success on an order
                // that is still unpaid and cannot be refunded.
                if ('' === (string) $order->getTransactionRef()) {
                    $this->addFlash('warning', $translator->trans('The order still carries no PayZen transaction: the history was recorded, but the order was not moved. Replay the notification of its payment from the PayZen back-office.', [], PayzenEmbedded::DOMAIN_NAME));
                }

                try {
                    $this->adminLogAppend("payzen-embedded.order-update", AccessManager::UPDATE, sprintf("Order %d refreshed", $order->getId()), (int) $order->getId());
                } catch (\Throwable $logFailure) {
                    Tlog::getInstance()->addError(sprintf('PayZen transaction refresh of order %d done, admin log failed: %s', $orderId, $logFailure->getMessage()));
                }
            }
        } catch (FormValidationException $ex) {
            $errorMsg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (TheliaProcessException $ex) {
            $errorMsg = mb_substr($ex->getMessage(), 0, 500);
        } catch (\Exception $ex) {
            Tlog::getInstance()->addError(sprintf('PayZen transaction refresh of order %d failed: %s', $orderId, $ex->getMessage()));
            $errorMsg = $translator->trans('The transaction could not be read from PayZen, see the logs.', [], PayzenEmbedded::DOMAIN_NAME);
        }

        if ($errorMsg) {
            try {
                $this->adminLogAppend(
                    "payzen-embedded.order-update",
                    AccessManager::UPDATE,
                    sprintf("Order %d: transaction refresh failed: %s", $orderId, mb_substr($errorMsg, 0, 500)),
                    $orderId
                );
            } catch (\Throwable $logFailure) {
                Tlog::getInstance()->addError(sprintf('PayZen transaction refresh of order %d refused, admin log failed: %s', $orderId, $logFailure->getMessage()));
            }

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
