SET NAMES utf8mb4;

-- ================================================================
-- Migration 022: Clean fresh install (spec 061)
-- Migrations 001-019 still insert the menu of the restaurant
-- GastroFlow was built for (dishes, add-ons, ingredients, dish
-- components). On a database created from the current
-- 001_schema.sql — and only there — remove it, keeping the rows
-- that carry behavior: "Monte Seu Prato" (is_customizable) and the
-- two packaging items (packaging_option).
-- The only trigger is the `fresh_install` marker table, which
-- exists solely on databases born from the clean 001_schema.sql;
-- an install created before it never has the table, so this
-- migration changes nothing there. No orders is a second guard.
-- Idempotente: a tabela marcadora é apagada no fim.
-- ================================================================

SELECT COUNT(*) INTO @is_fresh FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fresh_install';

SET @has_orders = 0;
SET @check_sql = IF(@is_fresh = 1,
    'SELECT EXISTS (SELECT 1 FROM orders) INTO @has_orders',
    'SELECT 0 INTO @has_orders'
);
PREPARE stmt FROM @check_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @clean = (@is_fresh = 1 AND @has_orders = 0);

SET @sql = IF(@clean, 'DELETE FROM item_ingredients', 'SELECT 1 AS dummy');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@clean, 'DELETE FROM dish_components', 'SELECT 1 AS dummy');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@clean, 'DELETE FROM ingredients', 'SELECT 1 AS dummy');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@clean,
    'DELETE FROM menu_items WHERE is_customizable = 0 AND packaging_option IS NULL',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS fresh_install;
