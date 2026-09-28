-- A refund is a transaction of its own that gives money back to the shopper. The history tells a
-- credit from a debit, so that a shop knows what it may still refund. Every row written before this
-- version is a payment, since the module could record nothing else: they are debits.

ALTER TABLE `payzen_embedded_transaction_history`
    ADD COLUMN `operationType` VARCHAR(16) DEFAULT 'DEBIT' AFTER `detailedStatus`;

UPDATE `payzen_embedded_transaction_history`
    SET `operationType` = 'DEBIT'
    WHERE `operationType` IS NULL;
