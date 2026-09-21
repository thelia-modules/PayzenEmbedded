-- One row per transaction, keyed on the identifier the platform gives it.
--
-- Until 3.2.1 every notification inserted a row, so a shop that received the browser return and the
-- server notification of one payment holds two rows for it. The duplicates are folded into the row
-- the shop last wrote, which is the one that carries the final state of the transaction.

SET FOREIGN_KEY_CHECKS = 0;

DELETE `older`
FROM `payzen_embedded_transaction_history` AS `older`
INNER JOIN `payzen_embedded_transaction_history` AS `kept`
    ON `kept`.`uuid` = `older`.`uuid`
    AND `kept`.`id` > `older`.`id`
WHERE `older`.`uuid` IS NOT NULL;

CREATE UNIQUE INDEX `payzen_embedded_transaction_history_uuid`
    ON `payzen_embedded_transaction_history` (`uuid`);

SET FOREIGN_KEY_CHECKS = 1;
