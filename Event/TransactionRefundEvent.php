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

namespace PayzenEmbedded\Event;

use PayzenEmbedded\LyraClient\RefundOutcome;
use Thelia\Core\Event\ActionEvent;

/**
 * Asks the platform to give the shopper of an order their money back, in full or in part.
 * Once dispatched, the event carries what the platform did in $outcome.
 */
class TransactionRefundEvent extends ActionEvent
{
    protected ?RefundOutcome $outcome = null;

    /**
     * @param int         $amount  in the smallest unit of the order currency, e.g. 1250 for 12.50 EUR
     * @param string|null $comment written on the refund in the PayZen back-office
     * @param int|null    $adminId the administrator asking for the refund, kept on the history row
     */
    public function __construct(
        protected int $orderId,
        protected int $amount,
        protected ?string $comment = null,
        protected ?int $adminId = null,
    ) {
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getAdminId(): ?int
    {
        return $this->adminId;
    }

    public function getOutcome(): ?RefundOutcome
    {
        return $this->outcome;
    }

    public function setOutcome(RefundOutcome $outcome): static
    {
        $this->outcome = $outcome;

        return $this;
    }
}
