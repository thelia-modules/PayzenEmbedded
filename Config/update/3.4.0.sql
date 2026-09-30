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
-- every transaction the platform lists for an order is recorded now: a column too short cuts the
-- status, read back as another one, or refuses the row on a strict server. Widened only when it is
-- still too short.
SET @widen_status := (SELECT COUNT(*) = 1 FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'payzen_embedded_transaction_history' AND `COLUMN_NAME` = 'detailedStatus' AND `CHARACTER_MAXIMUM_LENGTH` < 64);
SET @statement := IF(@widen_status, 'ALTER TABLE `payzen_embedded_transaction_history` MODIFY `detailedStatus` VARCHAR(64)', 'DO 0');
PREPARE widen_status_statement FROM @statement;
EXECUTE widen_status_statement;
DEALLOCATE PREPARE widen_status_statement;

-- The transaction statuses of the platform are longer than 10 characters (PARTIALLY_PAID): the
-- column is widened like the detailed status, and only when it is still too short.
SET @widen_state := (SELECT COUNT(*) = 1 FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'payzen_embedded_transaction_history' AND `COLUMN_NAME` = 'status' AND `CHARACTER_MAXIMUM_LENGTH` < 32);
SET @statement := IF(@widen_state, 'ALTER TABLE `payzen_embedded_transaction_history` MODIFY `status` VARCHAR(32)', 'DO 0');
PREPARE widen_state_statement FROM @statement;
EXECUTE widen_state_statement;
DEALLOCATE PREPARE widen_state_statement;

-- A refund records the administrator who asked for it: an administrator who refunded an order
-- can still be deleted, the history then keeps the refund without its author (the administrator
-- log keeps the name). The constraint is found by its column, since its name depends on the
-- version that created the table, and replaced only while it still restricts the deletion.
SET @admin_constraint := (SELECT `k`.`CONSTRAINT_NAME` FROM `information_schema`.`KEY_COLUMN_USAGE` `k` JOIN `information_schema`.`REFERENTIAL_CONSTRAINTS` `r` ON `r`.`CONSTRAINT_SCHEMA` = `k`.`CONSTRAINT_SCHEMA` AND `r`.`CONSTRAINT_NAME` = `k`.`CONSTRAINT_NAME` WHERE `k`.`TABLE_SCHEMA` = DATABASE() AND `k`.`TABLE_NAME` = 'payzen_embedded_transaction_history' AND `k`.`COLUMN_NAME` = 'admin_id' AND `k`.`REFERENCED_TABLE_NAME` = 'admin' AND `r`.`DELETE_RULE` <> 'SET NULL' LIMIT 1);
SET @statement := IF(@admin_constraint IS NULL, 'DO 0', CONCAT('ALTER TABLE `payzen_embedded_transaction_history` DROP FOREIGN KEY `', @admin_constraint, '`'));
PREPARE drop_admin_statement FROM @statement;
EXECUTE drop_admin_statement;
DEALLOCATE PREPARE drop_admin_statement;
SET @statement := IF(@admin_constraint IS NULL, 'DO 0', CONCAT('ALTER TABLE `payzen_embedded_transaction_history` ADD CONSTRAINT `', @admin_constraint, '` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON UPDATE RESTRICT ON DELETE SET NULL'));
PREPARE add_admin_statement FROM @statement;
EXECUTE add_admin_statement;
DEALLOCATE PREPARE add_admin_statement;

-- The replacement above is two statements: a run stopped between them leaves no constraint on
-- the administrator, which the guard above cannot see. It is created here whenever none exists.
SET @admin_missing := (SELECT COUNT(*) = 0 FROM `information_schema`.`KEY_COLUMN_USAGE` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'payzen_embedded_transaction_history' AND `COLUMN_NAME` = 'admin_id' AND `REFERENCED_TABLE_NAME` = 'admin');
SET @statement := IF(@admin_missing, 'ALTER TABLE `payzen_embedded_transaction_history` ADD CONSTRAINT `payzen_embedded_transaction_history_fk_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON UPDATE RESTRICT ON DELETE SET NULL', 'DO 0');
PREPARE restore_admin_statement FROM @statement;
EXECUTE restore_admin_statement;
DEALLOCATE PREPARE restore_admin_statement;
