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

namespace PayzenEmbedded\Service;

use PayzenEmbedded\LyraClient\StoredMethodLabel;
use PayzenEmbedded\LyraClient\TransactionOutcome;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Domain\Payment\CustomerPaymentMethodService;
use Thelia\Domain\Payment\Exception\GuestCannotStorePaymentMethodException;
use Thelia\Domain\Payment\Exception\StoredMethodsNotSupportedException;
use Thelia\Log\Tlog;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;

/**
 * Keeps the card PayZen registered while a customer paid, in the payment methods of the shop.
 *
 * The token comes back on the transaction the shopper registered the card with. It goes to the
 * core's service and nowhere else: never into a log, a message or an exception of the module.
 */
class StoredMethodRecorder
{
    public function __construct(
        private readonly CustomerPaymentMethodService $storedMethods,
    ) {
    }

    /**
     * A payment the platform refused registers nothing: the card was never accepted. Nor does a credit.
     * A guest, and a shop that stopped offering stored methods, keep nothing either, and the
     * payment this answer is about is not affected.
     *
     * @param array<mixed> $answer a transaction as the platform answers it
     */
    public function recordFrom(Order $order, array $answer): void
    {
        $token = $answer['paymentMethodToken'] ?? null;

        if (!\is_string($token) || '' === $token) {
            return;
        }

        // Only a debit the platform took (or is taking): the card of a refused attempt was never accepted, and a
        // credit is the giving back of a payment. The answer does not say whether the shopper asked to register
        // the card (PayZen answers the token of a payment made with a stored card too, and it is kept once
        // whatever the number of times it comes back), so the caller restricts this to the answer of a payment.
        $transaction = TransactionOutcome::fromAnswer($answer);

        if ($transaction->isCredit() || !\in_array($transaction->status, [TransactionOutcome::STATUS_PAID, TransactionOutcome::STATUS_RUNNING], true)) {
            return;
        }

        $module = ModuleQuery::create()->findPk(PayzenEmbedded::getModuleId());
        $customer = $order->getCustomer();

        if (null === $module || null === $customer) {
            return;
        }

        $card = StoredMethodLabel::fromAnswer($answer);

        try {
            $this->storedMethods->register($customer, $module, $token, $card->label, $card->expiresAt);
        } catch (GuestCannotStorePaymentMethodException | StoredMethodsNotSupportedException $notKept) {
            Tlog::getInstance()->addInfo(sprintf('PayZen card of order %s not kept as a payment method: %s.', $order->getRef(), $notKept::class));
        }
    }
}
