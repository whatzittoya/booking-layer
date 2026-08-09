SET @customer_bill_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_customers'
      AND COLUMN_NAME = 'bill_id'
);

SET @add_customer_bill_id := IF(
    @customer_bill_id_exists = 0,
    'ALTER TABLE `tbl_customers` ADD COLUMN `bill_id` VARCHAR(36) NULL AFTER `reservation_id`',
    'DO 0'
);

PREPARE add_customer_bill_id_statement FROM @add_customer_bill_id;
EXECUTE add_customer_bill_id_statement;
DEALLOCATE PREPARE add_customer_bill_id_statement;
