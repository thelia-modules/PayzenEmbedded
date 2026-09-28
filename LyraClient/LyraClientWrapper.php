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
use PayzenEmbedded\PayzenEmbedded;
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
    protected function updateTransactionHistory($answer, Order $order, ?Admin $admin = null): void
    {
        // Guess transaction status, terminated or not
        $finished = in_array($answer['status'], [ 'PAID', 'UNPAID' ]);

        $currency = CurrencyQuery::create()->findOneByCode($answer['currency']);

        $transaction = PayzenEmbeddedTransactionHistoryQuery::create()
            ->filterByUuid($answer['uuid'])
            ->findOne()
            ?? new PayzenEmbeddedTransactionHistory();

        $transaction
            ->setOrderId($order->getId())
            ->setCustomerId($order->getCustomerId())
            ->setAdmin($admin)
            ->setUuid($answer['uuid'])
            ->setDetailedstatus($answer['detailedStatus'])
            ->setStatus($answer['status'])
            ->setOperationtype(strtoupper((string) ($answer['operationType'] ?? TransactionOutcome::OPERATION_DEBIT)))
            ->setAmount($answer['amount'])
            ->setCurrencyId($currency ? $currency->getId() : null)
            ->setCreationdate(new \DateTime($answer['creationDate']) ?: null)
            ->setErrorcode($answer['errorCode'])
            ->setErrormessage($answer['errorMessage'])
            ->setDetailederrorcode($answer['detailedErrorCode'])
            ->setDetailederrormessage($answer['detailedErrorMessage'])
            ->setFinished($finished)
            ->save();
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
