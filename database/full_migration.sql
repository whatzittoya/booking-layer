CREATE TABLE IF NOT EXISTS `access_token` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` VARCHAR(255) NOT NULL,
    `api_key` VARCHAR(255) NOT NULL,
    `item_id` VARCHAR(32) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

SET @access_token_item_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'access_token'
      AND COLUMN_NAME = 'item_id'
);

SET @add_access_token_item_id := IF(
    @access_token_item_id_exists = 0,
    'ALTER TABLE `access_token` ADD COLUMN `item_id` VARCHAR(32) NULL AFTER `api_key`',
    'SELECT 1'
);

PREPARE add_access_token_item_id_statement FROM @add_access_token_item_id;
EXECUTE add_access_token_item_id_statement;
DEALLOCATE PREPARE add_access_token_item_id_statement;


SET @customer_reservation_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_customers'
      AND COLUMN_NAME = 'reservation_id'
);

SET @add_customer_reservation_id := IF(
    @customer_reservation_id_exists = 0,
    'ALTER TABLE `tbl_customers` ADD COLUMN `reservation_id` VARCHAR(32) NULL AFTER `refunded`',
    'SELECT 1'
);

PREPARE add_customer_reservation_id_statement FROM @add_customer_reservation_id;
EXECUTE add_customer_reservation_id_statement;
DEALLOCATE PREPARE add_customer_reservation_id_statement;

SET @customer_reservation_id_nullable := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_customers'
      AND COLUMN_NAME = 'reservation_id'
      AND IS_NULLABLE = 'YES'
);

SET @make_customer_reservation_id_nullable := IF(
    @customer_reservation_id_nullable = 0,
    'ALTER TABLE `tbl_customers` MODIFY COLUMN `reservation_id` VARCHAR(32) NULL',
    'SELECT 1'
);

PREPARE make_customer_reservation_id_nullable_statement FROM @make_customer_reservation_id_nullable;
EXECUTE make_customer_reservation_id_nullable_statement;
DEALLOCATE PREPARE make_customer_reservation_id_nullable_statement;

SET @customer_reservation_id_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_customers'
      AND INDEX_NAME = 'uniq_tbl_customers_reservation_id'
);

SET @drop_customer_reservation_id_unique := IF(
    @customer_reservation_id_unique_exists = 1,
    'ALTER TABLE `tbl_customers` DROP INDEX `uniq_tbl_customers_reservation_id`',
    'SELECT 1'
);

PREPARE drop_customer_reservation_id_unique_statement FROM @drop_customer_reservation_id_unique;
EXECUTE drop_customer_reservation_id_unique_statement;
DEALLOCATE PREPARE drop_customer_reservation_id_unique_statement;

CREATE TABLE IF NOT EXISTS `tbl_reservation_b_layer` (
    `id` VARCHAR(36) NOT NULL,
    `reference` VARCHAR(50) NOT NULL DEFAULT '',
    `starts_at` DATETIME NOT NULL,
    `ends_at` DATETIME NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT '',
    `booker_id` VARCHAR(36) NOT NULL DEFAULT '',
    `guest` VARCHAR(255) NOT NULL DEFAULT '',
    `guest_gender` VARCHAR(20) NOT NULL DEFAULT '',
    `guest_age` INT UNSIGNED NULL DEFAULT NULL,
    `guest_email` VARCHAR(255) NOT NULL DEFAULT '',
    `final_price_excl_tax` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `final_price_incl_tax` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `duration_in_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `duration_in_nights` INT UNSIGNED NOT NULL DEFAULT 0,
    `product` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_b_layer_status` (`status`),
    KEY `idx_b_layer_booker_id` (`booker_id`),
    KEY `idx_b_layer_starts_at` (`starts_at`),
    KEY `idx_b_layer_ends_at` (`ends_at`),
    KEY `idx_b_layer_reference` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
