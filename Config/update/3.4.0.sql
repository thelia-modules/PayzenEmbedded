-- A refund is a transaction of its own that gives money back to the shopper. The history tells a
-- credit from a debit, so that a shop knows what it may still refund. Rows written before this
-- version carry no type and count as debits.

ALTER TABLE `payzen_embedded_transaction_history`
    ADD COLUMN `operationType` VARCHAR(16) AFTER `detailedStatus`;
