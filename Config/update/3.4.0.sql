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
