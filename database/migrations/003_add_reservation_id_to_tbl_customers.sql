SET @reservation_id_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_customers'
      AND COLUMN_NAME = 'reservation_id'
);

SET @add_reservation_id_column := IF(
    @reservation_id_column_exists = 0,
    'ALTER TABLE `tbl_customers` ADD COLUMN `reservation_id` VARCHAR(255) NULL AFTER `refunded`',
    'SELECT 1'
);

PREPARE add_reservation_id_column_statement FROM @add_reservation_id_column;
EXECUTE add_reservation_id_column_statement;
DEALLOCATE PREPARE add_reservation_id_column_statement;
