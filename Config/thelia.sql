
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- payzen_embedded_customer_token
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS  `payzen_embedded_customer_token`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `payment_token` TEXT,
    `customer_id` INTEGER NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `FI_payzen_embedded_customer_token_customer_id` (`customer_id`),
    CONSTRAINT `fk_payzen_embedded_customer_token_customer_id`
        FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
