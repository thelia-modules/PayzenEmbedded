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

use PayzenEmbedded\LyraClient\StoredTokenGateway;
use Propel\Runtime\Propel;
use Thelia\Log\Tlog;
use Thelia\Model\Map\CustomerTableMap;

/**
 * Has PayZen forget the cards of the old table that the update to 3.6.0 did not move: those of a guest and of an
 * anonymized account, whom the shop no longer holds an identity for. Their rows stay in place until the platform
 * confirms, otherwise nobody could cancel the card any more.
 *
 * Run by the console command `payzen-embedded:legacy-tokens:cancel`, never by the update itself: each call to the
 * platform may wait for its timeout, and an update has no business waiting for as many calls as there are cards.
 * A run is bounded by a limit. Replayable: a card cancelled is gone from the table, a card not cancelled stays for
 * the next run. The card is the national contract's: the old table never knew the sale unit it was registered on.
 *
 * A token goes to the gateway and nowhere else: never into a log or an output. A failure is logged by its class only.
 */
class LegacyTokenCanceller
{
    private const FROM_WHERE = 'FROM `payzen_embedded_customer_token` AS `legacy` '
        . 'INNER JOIN `customer` ON `customer`.`id` = `legacy`.`customer_id` '
        . "WHERE `legacy`.`payment_token` IS NOT NULL AND `legacy`.`payment_token` <> '' "
        . 'AND (`customer`.`is_guest` = 1 OR `customer`.`anonymized_at` IS NOT NULL)';

    public function __construct(
        private readonly StoredTokenGateway $gateway,
    ) {
    }

    /**
     * How many cards wait to be cancelled, from the row after `$afterId` on. No call to the platform, and no gateway:
     * the update of the module counts with this.
     */
    public static function countPending(int $afterId = 0): int
    {
        $statement = Propel::getWriteConnection(CustomerTableMap::DATABASE_NAME)->prepare(
            'SELECT COUNT(*) ' . self::FROM_WHERE . ' AND `legacy`.`id` > ' . max(0, $afterId)
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function pending(int $afterId = 0): int
    {
        return self::countPending($afterId);
    }

    /**
     * Ask the platform to cancel at most `$limit` cards, the oldest rows first, then drop the rows of those it cancelled.
     *
     * @param int $afterId only the rows after this id: a card the platform keeps refusing is skipped by the next run
     *                     that starts after it, instead of taking the place of the others forever
     *
     * @return array{cancelled: int, failed: int, remaining: int, beyond: int, lastId: int} the rows removed, those that
     *                                                                                      failed, those still waiting (the
     *                                                                                      refused ones included, so never zero while
     *                                                                                      a card is refused), those after the last
     *                                                                                      row tried, and the last row tried
     */
    public function cancelAndDrop(int $limit, int $afterId = 0): array
    {
        $connection = Propel::getWriteConnection(CustomerTableMap::DATABASE_NAME);

        $statement = $connection->prepare(
            'SELECT `legacy`.`id`, `legacy`.`payment_token` ' . self::FROM_WHERE
            . ' AND `legacy`.`id` > ' . max(0, $afterId) . ' ORDER BY `legacy`.`id` LIMIT ' . max(0, $limit)
        );
        $statement->execute();
        $rows = $statement->fetchAll(\PDO::FETCH_NUM);

        $cancelled = 0;
        $failed = 0;
        $lastId = $afterId;

        foreach ($rows as [$id, $token]) {
            $lastId = (int) $id;

            try {
                $this->gateway->cancel((string) $token);

                $delete = $connection->prepare('DELETE FROM `payzen_embedded_customer_token` WHERE `id` = ?');
                $delete->execute([(int) $id]);
                ++$cancelled;
            } catch (\Throwable $failure) {
                ++$failed;
                Tlog::getInstance()->addError(sprintf('PayZen card of the old table, row %d, not cancelled: %s.', (int) $id, $failure::class));
            }
        }

        return ['cancelled' => $cancelled, 'failed' => $failed, 'remaining' => $this->pending(), 'beyond' => $this->pending($lastId), 'lastId' => $lastId];
    }
}
