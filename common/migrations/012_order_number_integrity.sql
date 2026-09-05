SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 012: Order number integrity (spec 019)
-- Completes the table_number -> order_number rename spec 010 deferred to
-- this milestone, scopes order_number uniqueness to a per-business-day
-- counter, and adds the concurrency-safe allocation table.
-- ================================================================

-- 1) Rename table_number -> order_number (idempotent: only if the old
-- column name is still present).
SELECT COUNT(*) INTO @old_col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'table_number';
SET @rename_sql = IF(@old_col_exists > 0,
    'ALTER TABLE orders CHANGE COLUMN table_number order_number VARCHAR(50) NOT NULL',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @rename_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Add business_date, backfilled from created_at. DATE(created_at)
-- already reflects the DB session/OS timezone (container TZ, set to
-- America/Sao_Paulo in docker-compose.yml), matching Settings::getTimezone()'s
-- default (APP_TIMEZONE) used by the application for the same computation.
SELECT COUNT(*) INTO @bd_col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'business_date';
SET @add_bd_sql = IF(@bd_col_exists = 0,
    'ALTER TABLE orders ADD COLUMN business_date DATE NULL AFTER order_number',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @add_bd_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE orders SET business_date = DATE(created_at) WHERE business_date IS NULL;

SET @notnull_sql = IF(@bd_col_exists = 0,
    'ALTER TABLE orders MODIFY COLUMN business_date DATE NOT NULL',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @notnull_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Enforce uniqueness at the database level. If historical data already
-- has a genuine (business_date, order_number) collision, this ALTER fails
-- loudly with MySQL's own duplicate-entry error, bin/migrate aborts without
-- marking this file as run, and nothing above needs re-doing on retry
-- (every step here is already guarded/idempotent).
SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uniq_order_number_per_day';
SET @idx_sql = IF(@idx_exists = 0,
    'ALTER TABLE orders ADD UNIQUE KEY uniq_order_number_per_day (business_date, order_number)',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) Concurrency-safe per-day counter for automatic order_number
-- allocation (see OrderRepository::allocateNextNumber()). Independent of
-- manual order_number overrides, which never write to this table.
CREATE TABLE IF NOT EXISTS order_number_counters (
    business_date DATE NOT NULL PRIMARY KEY,
    last_number   INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed each existing business_date's counter from the highest numeric
-- order_number already used that day, so post-migration suggestions
-- continue from the right place instead of restarting at 0. Non-numeric
-- historical values CAST(...AS UNSIGNED) to 0, matching the exact
-- behavior the old getNextNumber() query already relied on.
INSERT INTO order_number_counters (business_date, last_number)
SELECT business_date, MAX(CAST(order_number AS UNSIGNED))
FROM orders
GROUP BY business_date
ON DUPLICATE KEY UPDATE last_number = GREATEST(order_number_counters.last_number, VALUES(last_number));

SET FOREIGN_KEY_CHECKS = 1;
