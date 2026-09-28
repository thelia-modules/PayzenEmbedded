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
