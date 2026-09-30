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
     * @throws \Exception
     */
    /**
     * @param string|null $debitUuid the debit the order stands on, when the caller knows it: a transaction
     *                               of its own that does not say its operation type is then a credit
     */
    protected function updateTransactionHistory($answer, Order $order, ?Admin $admin = null, ?string $debitUuid = null): void
    {
        // An answer that does not say what the transaction is (a listing, say) is no correction of a
        // type the history already holds: a credit read back as a debit would leave the ledger.
        if ('' === trim((string) ($answer['operationType'] ?? '')) && \is_scalar($answer['uuid'] ?? null)) {
            $known = PayzenEmbeddedTransactionHistoryQuery::create()->filterByUuid(self::bounded($answer['uuid'], 128))->findOne();

            if (null !== $known && '' !== (string) $known->getOperationtype()) {
                $answer['operationType'] = $known->getOperationtype();
            }
        }

        $outcome = TransactionOutcome::fromAnswer($answer, $debitUuid);
        $currency = isset($answer['currency']) ? CurrencyQuery::create()->findOneByCode($answer['currency']) : null;

        $transaction = PayzenEmbeddedTransactionHistoryQuery::create()
            ->filterByUuid(self::bounded($outcome->uuid, 128))
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

            if (!$duplicate || !$transaction->isNew() || null === $existing = PayzenEmbeddedTransactionHistoryQuery::create()->filterByUuid(self::bounded($outcome->uuid, 128))->findOne()) {
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
            ->setUuid(self::bounded($outcome->uuid, 128))
            ->setDetailedstatus(self::bounded($outcome->detailedStatus, 64))
            ->setStatus(self::bounded($outcome->status, 32))
            ->setOperationtype(self::bounded($outcome->operationType, 16))
            // A parent the answer does not repeat is kept: an answer that says less is no correction.
            ->setParentuuid(self::bounded($outcome->parentUuid ?? $transaction->getParentuuid(), 128))
            ->setAmount($outcome->amount)
            ->setCurrencyId($currencyId)
            // Kept as a UTC wall clock whatever the offset the platform wrote it with: see TransactionOutcome::fromHistoryRow().
            ->setCreationdate($outcome->createdAt !== null ? \DateTime::createFromImmutable($outcome->createdAt)->setTimezone(new \DateTimeZone('UTC')) : null)
            ->setErrorcode(self::bounded($answer['errorCode'] ?? null, 10))
            ->setErrormessage(self::bounded($answer['errorMessage'] ?? null, 255))
            ->setDetailederrorcode(self::bounded($answer['detailedErrorCode'] ?? null, 10))
            ->setDetailederrormessage(self::bounded($answer['detailedErrorMessage'] ?? null, 255))
            ->setFinished($outcome->isFinished());

        // The author of a refund is kept; a notification, which has none, never erases it.
        if (null !== $admin) {
            $transaction->setAdmin($admin);
        }
    }

    /**
     * What the platform says, cut to the column that keeps it: every transaction the platform lists
     * is recorded, and a value too long is refused with the row on a strict server, the refund with it.
     */
    private static function bounded(mixed $value, int $length): ?string
    {
        if (!\is_scalar($value) || '' === (string) $value) {
            return null;
        }

        return mb_substr((string) $value, 0, $length);
    }

    /**
     * The transaction the order currently stands on, see GoverningTransaction.
     */
    protected function governingTransaction(Order $order): ?TransactionOutcome
    {
        return GoverningTransaction::among((new TransactionHistoryReader())->outcomesOf($order), (string) $order->getTransactionRef());
    }
}
