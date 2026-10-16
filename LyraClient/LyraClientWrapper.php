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
use PayzenEmbedded\Model\Map\PayzenEmbeddedTransactionHistoryTableMap;
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

        $this->useConfiguration();
    }

    /**
     * Make this client speak for the contract the module is configured with: the one of the sale
     * unit in scope, or the national one.
     *
     * The clients are services shared by everything that runs in a request, and a notification or a
     * back-office operation works for the sale unit of an order, not for the one of the request:
     * what is about an order goes through forOrder(), which sets the client up again for it.
     */
    protected function useConfiguration(): void
    {
        $contract = PaymentContract::read(static fn (string $name): ?string => PayzenEmbedded::getConfigValue($name));

        // Inilialize PayZen client
        $this->setUsername($contract->siteId);
        $this->setEndpoint($contract->endpoint);

        // Test / Productiuon variable
        $this->setPassword($contract->password);
        $this->setPublicKey($contract->publicKey);
        $this->setSHA256Key($contract->signatureKey);
    }

    /**
     * Run what is about an order with the contract of the sale unit the order was placed on, the
     * national one for a national order, a shop that sells from no sale unit, or a Thelia that does
     * not know them yet. Once it is done the client speaks again for the configuration in scope where it
     * was called: a call that forgets forOrder() never signs with the contract of the last order.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function forOrder(Order $order, callable $callback): mixed
    {
        try {
            return PayzenEmbedded::runForOrder($order, function () use ($callback): mixed {
                $this->useConfiguration();

                return $callback();
            });
        } finally {
            $this->useConfiguration();
        }
    }

    /**
     * Call the platform. The history rows read before the call are kept in memory by the ORM and
     * handed back as they were by any later query: a notification may have written them while the
     * platform answered, so they are read again from the database afterwards.
     *
     * @param string       $target
     * @param array<mixed> $array
     *
     * @return array<mixed>
     */
    public function post($target, $array)
    {
        try {
            return parent::post($target, $array);
        } finally {
            PayzenEmbeddedTransactionHistoryTableMap::clearInstancePool();
        }
    }

    /**
     * Record a transaction, or bring the record of a transaction already known up to date.
     *
     * One row per transaction, keyed on the identifier the platform gives it: an order that was
     * paid for on its second attempt has two rows, and a transaction the platform notifies twice
     * keeps the one row it already has. Without that, a shop reading its own history could not tell
     * a retry from a duplicate notification.
     *
     * @param string|null $debitUuid the debit the order stands on, when the caller knows it: a transaction
     *                               of its own that does not say its operation type is then a credit
     *
     * @throws \Exception
     */
    protected function updateTransactionHistory($answer, Order $order, ?Admin $admin = null, ?string $debitUuid = null): void
    {
        // An answer that does not say what the transaction is (a listing, say) is no correction of a
        // type the history already holds: a credit read back as a debit would leave the ledger.
        if ('' === trim((string) ($answer['operationType'] ?? '')) && \is_scalar($answer['uuid'] ?? null)) {
            $known = PayzenEmbeddedTransactionHistoryQuery::create()->filterByUuid(HistoryColumn::bounded('uuid', $answer['uuid']))->findOne();

            if (null !== $known && '' !== (string) $known->getOperationtype()) {
                $answer['operationType'] = $known->getOperationtype();
            }
        }

        $outcome = TransactionOutcome::fromAnswer($answer, $debitUuid);
        $currency = isset($answer['currency']) ? CurrencyQuery::create()->findOneByCode($answer['currency']) : null;

        $transaction = PayzenEmbeddedTransactionHistoryQuery::create()
            ->filterByUuid(HistoryColumn::bounded('uuid', $outcome->uuid))
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
            // A duplicate key only (MySQL 1062): SQLSTATE 23000 also covers a missing value or a
            // foreign key, which no second write would cure.
            $previous = $exception->getPrevious();
            $duplicate = $previous instanceof \PDOException && 1062 === (int) ($previous->errorInfo[1] ?? 0);

            if (!$duplicate || !$transaction->isNew() || null === $existing = PayzenEmbeddedTransactionHistoryQuery::create()->filterByUuid(HistoryColumn::bounded('uuid', $outcome->uuid))->findOne()) {
                throw $exception;
            }

            if (null !== $existing->getOrderId() && (int) $existing->getOrderId() !== (int) $order->getId()) {
                Tlog::getInstance()->addWarning(sprintf('PayZen transaction %s belongs to order #%d, not to order %s: ignored.', $outcome->uuid, (int) $existing->getOrderId(), $order->getRef()));

                return;
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
            ->setUuid(HistoryColumn::bounded('uuid', $outcome->uuid))
            ->setDetailedstatus(HistoryColumn::bounded('detailedStatus', $outcome->detailedStatus))
            ->setStatus(HistoryColumn::bounded('status', $outcome->status))
            ->setOperationtype(HistoryColumn::bounded('operationType', $outcome->operationType))
            // A parent the answer does not repeat is kept: an answer that says less is no correction.
            ->setParentuuid(HistoryColumn::bounded('parentUuid', $outcome->parentUuid ?? $transaction->getParentuuid()))
            ->setAmount($outcome->amount)
            ->setCurrencyId($currencyId)
            // Kept as a UTC wall clock whatever the offset the platform wrote it with: see TransactionOutcome::fromHistoryRow().
            ->setCreationdate($outcome->createdAt !== null ? \DateTime::createFromImmutable($outcome->createdAt)->setTimezone(new \DateTimeZone('UTC')) : null)
            ->setErrorcode(HistoryColumn::bounded('errorCode', $answer['errorCode'] ?? null))
            ->setErrormessage(HistoryColumn::bounded('errorMessage', $answer['errorMessage'] ?? null))
            ->setDetailederrorcode(HistoryColumn::bounded('detailedErrorCode', $answer['detailedErrorCode'] ?? null))
            ->setDetailederrormessage(HistoryColumn::bounded('detailedErrorMessage', $answer['detailedErrorMessage'] ?? null))
            ->setFinished($outcome->isFinished());

        // The author of a refund is kept; a notification, which has none, never erases it.
        if (null !== $admin) {
            $transaction->setAdmin($admin);
        }
    }

    /**
     * The transaction the order currently stands on, see GoverningTransaction.
     */
    protected function governingTransaction(Order $order): ?TransactionOutcome
    {
        return GoverningTransaction::among((new TransactionHistoryReader())->outcomesOf($order), (string) $order->getTransactionRef());
    }
}
