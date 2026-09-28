<?php
/*************************************************************************************/
/*      Copyright (c) Franck Allimant, CQFDev                                        */
/*      email : thelia@cqfdev.fr                                                     */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE      */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded\LyraClient;

use Lyra\Client;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use Propel\Runtime\Exception\PropelException;
use PayzenEmbedded\PayzenEmbedded;
use Thelia\Log\Tlog;
use Thelia\Model\Admin;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Order;

/**
 * A simple wrapper to provide a properly initialized Lyra Client instance
 *
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 27/05/2019 17:33
 */

class LyraClientWrapper extends Client
{
    const PAYMENT_STATUS_PAID = 1;
    const PAYMENT_STATUS_NOT_PAID = 2;
    const PAYMENT_STATUS_IN_PROGRESS = 3;
    const PAYMENT_STATUS_ERROR = 4;

    public function __construct()
    {
        parent::__construct();

        $mode = PayzenEmbedded::getConfigValue('mode', false);

        if ('TEST' == $mode) {
            $varMode = 'test';
        } else {
            $varMode = 'production';
        }

        $publicKey = PayzenEmbedded::getConfigValue('javascript_' . $varMode . '_key');

        // Inilialize PayZen client
        $this->setUsername(PayzenEmbedded::getConfigValue('site_id'));
        $this->setEndpoint(PayzenEmbedded::getConfigValue('webservice_endpoint'));

        // Test / Productiuon variable
        $this->setPassword(PayzenEmbedded::getConfigValue($varMode . '_password'));
        $this->setPublicKey($publicKey);
        $this->setSHA256Key(PayzenEmbedded::getConfigValue('signature_' . $varMode . '_key'));
    }


    /**
     * Record a transaction, or bring the record of a transaction already known up to date.
     *
     * One row per transaction, keyed on the identifier the platform gives it: an order that was
     * paid for on its second attempt has two rows, and a transaction the platform notifies twice
     * keeps the one row it already has. Without that, a shop reading its own history could not tell
     * a retry from a duplicate notification.
     *
     * @throws \Exception
     */
    /**
     * @param string|null $debitUuid the debit the order stands on, when the caller knows it: a transaction
     *                               of its own that does not say its operation type is then a credit
     */
    protected function updateTransactionHistory($answer, Order $order, ?Admin $admin = null, ?string $debitUuid = null): void
    {
        $outcome = TransactionOutcome::fromAnswer($answer, $debitUuid);
        $currency = isset($answer['currency']) ? CurrencyQuery::create()->findOneByCode($answer['currency']) : null;

        $transaction = PayzenEmbeddedTransactionHistoryQuery::create()
            ->filterByUuid($outcome->uuid)
            ->findOne()
            ?? new PayzenEmbeddedTransactionHistory();

        // A transaction already recorded for another order is not this order's: the platform lists
        // transactions by the merchant's order reference, which two shops on one contract may share.
        if (!$transaction->isNew() && null !== $transaction->getOrderId() && (int) $transaction->getOrderId() !== (int) $order->getId()) {
            Tlog::getInstance()->addWarning(sprintf(
                'PayZen transaction %s belongs to order #%d, not to order %s: ignored.',
                $outcome->uuid,
                (int) $transaction->getOrderId(),
                $order->getRef()
            ));

            return;
        }

        $this->fillTransactionHistory($transaction, $answer, $outcome, $order, $currency?->getId(), $admin);

        try {
            $transaction->save();
        } catch (PropelException $exception) {
            // The platform notifies the same transaction it just answered: the notification may have
            // inserted the row between the read above and this write. The row is then brought up to date.
            $duplicate = $exception->getPrevious() instanceof \PDOException && '23000' === (string) $exception->getPrevious()->getCode();

            if (!$duplicate || !$transaction->isNew() || null === $existing = PayzenEmbeddedTransactionHistoryQuery::create()->filterByUuid($outcome->uuid)->findOne()) {
                throw $exception;
            }

            $this->fillTransactionHistory($existing, $answer, $outcome, $order, $currency?->getId(), $admin);
            $existing->save();
        }
    }

    private function fillTransactionHistory(
        PayzenEmbeddedTransactionHistory $transaction,
        array $answer,
        TransactionOutcome $outcome,
        Order $order,
        ?int $currencyId,
        ?Admin $admin,
    ): void {
        $transaction
            ->setOrderId($order->getId())
            ->setCustomerId($order->getCustomerId())
            ->setUuid($outcome->uuid)
            ->setDetailedstatus('' !== $outcome->detailedStatus ? $outcome->detailedStatus : null)
            ->setStatus($outcome->status)
            ->setOperationtype($outcome->operationType)
            ->setAmount($outcome->amount)
            ->setCurrencyId($currencyId)
            ->setCreationdate($outcome->createdAt !== null ? \DateTime::createFromImmutable($outcome->createdAt) : null)
            ->setErrorcode($answer['errorCode'] ?? null)
            ->setErrormessage($answer['errorMessage'] ?? null)
            ->setDetailederrorcode($answer['detailedErrorCode'] ?? null)
            ->setDetailederrormessage($answer['detailedErrorMessage'] ?? null)
            ->setFinished($outcome->isFinished());

        // The author of a refund is kept; a notification, which has none, never erases it.
        if (null !== $admin) {
            $transaction->setAdmin($admin);
        }
    }

    /**
     * The transaction the order currently stands on.
     *
     * A paid transaction speaks for the order whatever else it carries, since a shop does not take
     * back a payment it has received. Failing one, the latest attempt the platform dated speaks.
     * A refund never speaks for the order: it is a credit the shop asked for, not a payment attempt.
     */
    protected function governingTransaction(Order $order): ?TransactionOutcome
    {
        $governing = null;

        foreach ((new TransactionHistoryReader())->outcomesOf($order) as $outcome) {
            if ($outcome->isCredit()) {
                continue;
            }

            if (null === $governing) {
                $governing = $outcome;
                continue;
            }

            if ($outcome->isPaid() && !$governing->isPaid()) {
                $governing = $outcome;
                continue;
            }

            if (!$governing->isPaid()
                && null !== $outcome->createdAt
                && (null === $governing->createdAt || $outcome->createdAt > $governing->createdAt)) {
                $governing = $outcome;
            }
        }

        return $governing;
    }
}
