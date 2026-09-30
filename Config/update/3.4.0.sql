-- A refund is a transaction of its own that gives money back to the shopper. The history tells a
-- credit from a debit, so that a shop knows what it may still refund. Every row written before this
-- version is a payment, since the module could record nothing else: they are debits.
--
-- Replayable: the column is added only when it is missing, as the core guards its own scripts.

SET @add_column := (SELECT COUNT(*) = 0 FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'payzen_embedded_transaction_history' AND `COLUMN_NAME` = 'operationType');
SET @statement := IF(@add_column, 'ALTER TABLE `payzen_embedded_transaction_history` ADD COLUMN `operationType` VARCHAR(16) DEFAULT \'DEBIT\' AFTER `detailedStatus`', 'DO 0');
PREPARE add_column_statement FROM @statement;
EXECUTE add_column_statement;
DEALLOCATE PREPARE add_column_statement;

UPDATE `payzen_embedded_transaction_history`
    SET `operationType` = 'DEBIT'
    WHERE `operationType` IS NULL;

-- The debit a credit gives money back on, so that a refund of another payment of the order (a
-- double payment refunded from the PayZen back-office) is not deducted from this one.
SET @add_parent := (SELECT COUNT(*) = 0 FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'payzen_embedded_transaction_history' AND `COLUMN_NAME` = 'parentUuid');
SET @statement := IF(@add_parent, 'ALTER TABLE `payzen_embedded_transaction_history` ADD COLUMN `parentUuid` VARCHAR(128) AFTER `operationType`', 'DO 0');
PREPARE add_parent_statement FROM @statement;
EXECUTE add_parent_statement;
DEALLOCATE PREPARE add_parent_statement;

-- The detailed statuses of the platform go up to 33 characters (WAITING_AUTHORISATION_TO_VALIDATE), and
-- every transaction the platform lists for an order is recorded now: a column too short refuses
-- the row, and the refund with it. Widened only when it is still too short.
SET @widen_status := (SELECT COUNT(*) = 1 FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'payzen_embedded_transaction_history' AND `COLUMN_NAME` = 'detailedStatus' AND `CHARACTER_MAXIMUM_LENGTH` < 64);
SET @statement := IF(@widen_status, 'ALTER TABLE `payzen_embedded_transaction_history` MODIFY `detailedStatus` VARCHAR(64)', 'DO 0');
PREPARE widen_status_statement FROM @statement;
EXECUTE widen_status_statement;
DEALLOCATE PREPARE widen_status_statement;
