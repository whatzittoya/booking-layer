SET @item_id_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'access_token'
      AND COLUMN_NAME = 'item_id'
);

SET @add_item_id_column := IF(
    @item_id_column_exists = 0,
    'ALTER TABLE `access_token` ADD COLUMN `item_id` VARCHAR(32) NULL AFTER `api_key`',
    'DO 0'
);

PREPARE add_item_id_column_statement FROM @add_item_id_column;
EXECUTE add_item_id_column_statement;
DEALLOCATE PREPARE add_item_id_column_statement;
