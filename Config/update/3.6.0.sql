-- The cards PayZen kept for a customer (1-click payment) used to live in this module's own table,
-- one token per customer. They are payment methods of the shop now, in customer_payment_method,
-- kept through this module: the shop lists them at the checkout, the customer manages them in their
-- account.
--
-- The cards are MOVED: a row of the old table is deleted once its card is among the payment methods
-- of its customer, so that a card the customer forgets later is never brought back by a replay of this
-- script. A guest and an anonymized account are not migrated (the shop no longer holds an identity for
-- them), but their rows are NOT deleted here either: PayZen still holds their cards, and a row is all
-- that is left to cancel them with. The console command payzen-embedded:legacy-tokens:cancel has PayZen
-- cancel them, then drops the row (the update only counts them, see PayzenEmbedded::postUpdate()). An account that no longer exists and an empty token are deleted here: there
-- is nothing to cancel. The table itself stays in place for one more version (it is dropped by a later one).
--
-- Replayable: a token already kept for the customer through this module is not added a second time,
-- and a customer who has a default method keeps it.
--
-- The migrated cards carry a generic label: the old table held the token and nothing else, and the
-- shop never holds a card number.
--
-- Nothing here writes a token anywhere but into customer_payment_method: it is read by a SELECT and
-- never appears as a literal in a statement.

-- First, before any data is written: the statement below, when it runs, is DDL and commits.
-- The table is created only when it is missing, by a statement prepared only then (a replay runs no DDL, which
-- would also commit a transaction of the caller, as the core guards its own scripts).
SET @create_table := (SELECT COUNT(*) = 0 FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'payzen_embedded_stored_method_contract');
SET @statement := IF(@create_table, 'CREATE TABLE `payzen_embedded_stored_method_contract` (`customer_payment_method_id` INTEGER NOT NULL, `sale_unit_id` INTEGER, PRIMARY KEY (`customer_payment_method_id`), CONSTRAINT `fk_payzen_embedded_stored_method_contract_method` FOREIGN KEY (`customer_payment_method_id`) REFERENCES `customer_payment_method` (`id`) ON UPDATE RESTRICT ON DELETE CASCADE) ENGINE=InnoDB', 'DO 0');
PREPARE create_table_statement FROM @statement;
EXECUTE create_table_statement;
DEALLOCATE PREPARE create_table_statement;

INSERT INTO `customer_payment_method` (`customer_id`, `payment_module_id`, `token`, `label`, `expires_at`, `is_default`, `created_at`, `updated_at`)
SELECT `legacy`.`customer_id`, `payzen`.`id`, `legacy`.`payment_token`, 'PayZen card', NULL, 0, NOW(), NOW()
FROM (
    SELECT DISTINCT `customer_id`, `payment_token`
    FROM `payzen_embedded_customer_token`
    WHERE `payment_token` IS NOT NULL AND `payment_token` <> ''
) AS `legacy`
INNER JOIN `customer` ON `customer`.`id` = `legacy`.`customer_id` AND `customer`.`is_guest` = 0 AND `customer`.`anonymized_at` IS NULL
INNER JOIN `module` AS `payzen` ON `payzen`.`code` = 'PayzenEmbedded'
WHERE NOT EXISTS (
    SELECT 1
    FROM `customer_payment_method` AS `kept`
    WHERE `kept`.`customer_id` = `legacy`.`customer_id`
        AND `kept`.`payment_module_id` = `payzen`.`id`
        AND BINARY `kept`.`token` = BINARY `legacy`.`payment_token`
);

-- A customer has one default method as soon as they have any: the oldest card of those just migrated
-- becomes it for a customer who had none.
UPDATE `customer_payment_method` AS `method`
INNER JOIN (
    SELECT MIN(`id`) AS `id`
    FROM `customer_payment_method`
    WHERE `customer_id` IN (SELECT `customer_id` FROM `payzen_embedded_customer_token`)
    GROUP BY `customer_id`
    HAVING SUM(`is_default`) = 0
) AS `first_method` ON `first_method`.`id` = `method`.`id`
SET `method`.`is_default` = 1;

-- The move: what was copied (or was already kept), and what has nothing to cancel. A row whose card could not
-- be copied for another reason stays, for the next pass, and so does that of a guest or an anonymized account.
DELETE `legacy` FROM `payzen_embedded_customer_token` AS `legacy`
WHERE `legacy`.`payment_token` IS NULL
    OR `legacy`.`payment_token` = ''
    OR NOT EXISTS (
        SELECT 1
        FROM `customer`
        WHERE `customer`.`id` = `legacy`.`customer_id`
    )
    OR EXISTS (
        SELECT 1
        FROM `customer_payment_method` AS `kept`
        INNER JOIN `module` AS `payzen` ON `payzen`.`id` = `kept`.`payment_module_id` AND `payzen`.`code` = 'PayzenEmbedded'
        WHERE `kept`.`customer_id` = `legacy`.`customer_id`
            AND BINARY `kept`.`token` = BINARY `legacy`.`payment_token`
    );

-- The contract each card was registered on. PayZen creates a token on the contract of the sale unit the payment
-- is made on, and the token is only good there: the card is offered, paid with and cancelled on that sale unit
-- only. sale_unit_id is NULL for the national contract. A card with no row here is unusable (see the Readme).
--
-- Every card of this module the shop holds is linked to the NATIONAL contract (NULL): the migrated ones and any
-- registered by a build that did not link them. The old table never knew the sale unit a card was registered on, and
-- 3.5.0 already paid on the contract of a sale unit. A card that was registered on a sale unit's own contract (a shop
-- that sets contracts per sale unit) is therefore tied to the wrong contract: it is offered on national orders only,
-- where PayZen refuses it, and cancelling it on the national contract is refused too, so it stays kept until it is
-- cancelled from the PayZen back office. A shop with no sale unit contract is not affected.
--
-- Replayable: a card already linked keeps its link.

INSERT IGNORE INTO `payzen_embedded_stored_method_contract` (`customer_payment_method_id`, `sale_unit_id`)
SELECT `method`.`id`, NULL
FROM `customer_payment_method` AS `method`
INNER JOIN `module` AS `payzen` ON `payzen`.`id` = `method`.`payment_module_id` AND `payzen`.`code` = 'PayzenEmbedded';
