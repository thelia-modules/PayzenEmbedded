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
 * Created by Franck Allimant, CQFDev <franck@cqfdev.fr>
 * Date: 05/06/2019 15:32
 */

namespace PayzenEmbedded\Event;

use Thelia\Core\Event\ActionEvent;

class TransactionUpdateEvent extends ActionEvent
{
    /** @var int */
    protected $amount;

    /** @var int */
    protected $orderId;

    /** @var \DateTime */
    protected $expectedCaptureDate;

    /** @var boolean */
    protected $manualValidation;

    /** @var int */
    protected $paymentStatus;

    /**
     * TransactionUpdateEvent constructor.
     *
     * @param int $orderId
     */
    public function __construct($orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * @return int
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * @param int $amount
     * @return $this
     */
    public function setAmount($amount)
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getExpectedCaptureDate()
    {
        return $this->expectedCaptureDate;
    }

    /**
     * @param \DateTime $expectedCaptureDate
     * @return $this
     */
    public function setExpectedCaptureDate($expectedCaptureDate)
    {
        $this->expectedCaptureDate = $expectedCaptureDate;
        return $this;
    }

    /**
     * @return bool
     */
    public function isManualValidation()
    {
        return $this->manualValidation;
    }

    /**
     * @param bool $manualValidation
     * @return $this
     */
    public function setManualValidation($manualValidation)
    {
        $this->manualValidation = $manualValidation;
        return $this;
    }

    /**
     * @return int
     */
    public function getOrderId()
    {
        return $this->orderId;
    }

    /**
     * @return int
     */
    public function getPaymentStatus()
    {
        return $this->paymentStatus;
    }

    /**
     * The payment status, one of LyraClientWrapper::PAYEMENT_STATUS_* constants
     *
     * @param int $paymentStatus
     * @return $this
     */
    public function setPaymentStatus($paymentStatus)
    {
        $this->paymentStatus = $paymentStatus;
        return $this;
    }
}
