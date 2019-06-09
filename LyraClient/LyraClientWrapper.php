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
     * Update the transaction history table
     *
     * @param $answer
     * @param Order $order
     * @param Admin|null $admin
     * @throws \Exception
     */
    protected function updateTransactionHistory($answer, Order $order, Admin $admin = null)
    {
        // Guess transaction status, terminated or not
        $finished = in_array($answer['status'], [ 'PAID', 'UNPAID' ]);

        $currency = CurrencyQuery::create()->findOneByCode($answer['currency']);

        (new PayzenEmbeddedTransactionHistory())
            ->setOrderId($order->getId())
            ->setCustomerId($order->getCustomerId())
            ->setAdmin($admin)
            ->setUuid($answer['uuid'])
            ->setDetailedstatus($answer['detailedStatus'])
            ->setStatus($answer['status'])
            ->setAmount($answer['amount'])
            ->setCurrencyId($currency ? $currency->getId() : null)
            ->setCreationdate(new \DateTime($answer['creationDate']) ?: null)
            ->setErrorcode($answer['errorCode'])
            ->setErrormessage($answer['errorMessage'])
            ->setDetailederrorcode($answer['detailedErrorCode'])
            ->setDetailedstatus($answer['detailedErrorMessage'])
            ->setFinished($finished)
            ->save();
    }
}
