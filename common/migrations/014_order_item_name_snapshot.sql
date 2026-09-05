SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 014: Order item name snapshot (spec 023)
-- order_items never stored the item's name -- every read live-joined
-- menu_items.name, so renaming a menu item silently rewrote every past
-- order/receipt/report that referenced it. Price was already snapshotted
-- (migration 008); this closes the remaining "item name" half of
-- docs/ROADMAP.md's v1.7.0 "Historical order snapshots".
-- ================================================================

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'item_name';
SET @add_sql = IF(@col_exists = 0,
    'ALTER TABLE order_items ADD COLUMN item_name VARCHAR(100) NOT NULL DEFAULT '''' AFTER menu_item_id',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Best-effort backfill from each item's *current* name — the true name at
-- the time an already-existing order was placed is not recoverable if that
-- item has since been renamed (see spec 023's Backward compatibility).
UPDATE order_items oi
JOIN menu_items mi ON mi.id = oi.menu_item_id
SET oi.item_name = mi.name
WHERE oi.item_name = '';

SET FOREIGN_KEY_CHECKS = 1;
