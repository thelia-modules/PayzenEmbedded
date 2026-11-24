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

use Thelia\Core\Event\ActionEvent;
use Thelia\Model\Order;

/**
 * Dispatched once the module recorded a transaction the platform reported, by a notification or a
 * refresh, and moved the order on it: what Thelia's payment journal learns from it is written then.
 */
class TransactionReportedEvent extends ActionEvent
{
    /**
     * @param array<string, mixed> $answer the transaction as the platform describes it
     */
    public function __construct(
        protected Order $order,
        protected array $answer,
    ) {
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnswer(): array
    {
        return $this->answer;
    }
}
