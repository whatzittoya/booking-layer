SET @reservation_id_is_nullable := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_customers'
      AND COLUMN_NAME = 'reservation_id'
      AND IS_NULLABLE = 'YES'
);

SET @make_reservation_id_nullable := IF(
    @reservation_id_is_nullable = 0,
    'ALTER TABLE `tbl_customers` MODIFY COLUMN `reservation_id` VARCHAR(32) NULL',
    'DO 0'
);

PREPARE make_reservation_id_nullable_statement FROM @make_reservation_id_nullable;
EXECUTE make_reservation_id_nullable_statement;
DEALLOCATE PREPARE make_reservation_id_nullable_statement;

SET @reservation_id_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_customers'
      AND INDEX_NAME = 'uniq_tbl_customers_reservation_id'
);

SET @drop_reservation_id_unique := IF(
    @reservation_id_unique_exists = 1,
    'ALTER TABLE `tbl_customers` DROP INDEX `uniq_tbl_customers_reservation_id`',
    'DO 0'
);

PREPARE drop_reservation_id_unique_statement FROM @drop_reservation_id_unique;
EXECUTE drop_reservation_id_unique_statement;
DEALLOCATE PREPARE drop_reservation_id_unique_statement;
