-- Booking Layer allows dateless bookings (e.g. a booking whose lines are all
-- undated purchases). Those arrive with starts_at/ends_at null, and under
-- STRICT_TRANS_TABLES a NOT NULL DATETIME rejects them, failing the whole pull.

SET @reservation_starts_at_nullable := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_reservation_b_layer'
      AND COLUMN_NAME = 'starts_at'
      AND IS_NULLABLE = 'YES'
);

SET @make_reservation_starts_at_nullable := IF(
    @reservation_starts_at_nullable = 0,
    'ALTER TABLE `tbl_reservation_b_layer` MODIFY COLUMN `starts_at` DATETIME NULL DEFAULT NULL',
    'DO 0'
);

PREPARE make_reservation_starts_at_nullable_statement FROM @make_reservation_starts_at_nullable;
EXECUTE make_reservation_starts_at_nullable_statement;
DEALLOCATE PREPARE make_reservation_starts_at_nullable_statement;

SET @reservation_ends_at_nullable := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_reservation_b_layer'
      AND COLUMN_NAME = 'ends_at'
      AND IS_NULLABLE = 'YES'
);

SET @make_reservation_ends_at_nullable := IF(
    @reservation_ends_at_nullable = 0,
    'ALTER TABLE `tbl_reservation_b_layer` MODIFY COLUMN `ends_at` DATETIME NULL DEFAULT NULL',
    'DO 0'
);

PREPARE make_reservation_ends_at_nullable_statement FROM @make_reservation_ends_at_nullable;
EXECUTE make_reservation_ends_at_nullable_statement;
DEALLOCATE PREPARE make_reservation_ends_at_nullable_statement;
