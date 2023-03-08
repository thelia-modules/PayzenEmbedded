
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- payzen_embedded_customer_token
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `payzen_embedded_customer_token`;

CREATE TABLE `payzen_embedded_customer_token`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `payment_token` TEXT,
    `customer_id` INTEGER NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `fi_payzen_embedded_customer_token_customer_id` (`customer_id`),
    CONSTRAINT `fk_payzen_embedded_customer_token_customer_id`
        FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- payzen_embedded_transaction_history
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `payzen_embedded_transaction_history`;

CREATE TABLE `payzen_embedded_transaction_history`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `customer_id` INTEGER NOT NULL,
    `order_id` INTEGER,
    `admin_id` INTEGER,
    `uuid` VARCHAR(128),
    `status` VARCHAR(10),
    `detailedStatus` VARCHAR(32),
    `amount` INTEGER(11),
    `currency_id` INTEGER NOT NULL,
    `creationDate` DATETIME,
    `errorCode` VARCHAR(10),
    `errorMessage` VARCHAR(255),
    `detailedErrorCode` VARCHAR(10),
    `detailedErrorMessage` VARCHAR(255),
    `finished` TINYINT(1) NOT NULL,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    INDEX `payzen_embedded_transaction_history_fi_7e8f3e` (`customer_id`),
    INDEX `payzen_embedded_transaction_history_fi_75704f` (`order_id`),
    INDEX `payzen_embedded_transaction_history_fi_8e51ba` (`admin_id`),
    INDEX `payzen_embedded_transaction_history_fi_16a5a4` (`currency_id`),
    CONSTRAINT `payzen_embedded_transaction_history_fk_7e8f3e`
        FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT `payzen_embedded_transaction_history_fk_75704f`
        FOREIGN KEY (`order_id`)
        REFERENCES `order` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT `payzen_embedded_transaction_history_fk_8e51ba`
        FOREIGN KEY (`admin_id`)
        REFERENCES `admin` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT `payzen_embedded_transaction_history_fk_16a5a4`
        FOREIGN KEY (`currency_id`)
        REFERENCES `currency` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
