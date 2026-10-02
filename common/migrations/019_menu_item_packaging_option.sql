SET NAMES utf8mb4;

-- ================================================================
-- Migration 019: Link the "viagem" dining options to a menu item
-- (spec 050). The packaging fee charged for viagem_simples /
-- viagem_vip becomes the price of the menu item holding that
-- option, so changing the price in the Admin changes the fee.
-- UNIQUE: at most one item per option (NULLs don't collide).
-- Idempotente: pode ser reexecutada sem duplicar dados.
-- ================================================================

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'packaging_option';
SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE menu_items ADD COLUMN packaging_option VARCHAR(20) NULL DEFAULT NULL, ADD UNIQUE INDEX uq_menu_items_packaging_option (packaging_option)',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Link the seeded packaging items by name, only if the option is still unlinked.
SELECT COUNT(*) INTO @simples_linked FROM menu_items WHERE packaging_option = 'viagem_simples';
UPDATE menu_items SET packaging_option = 'viagem_simples'
    WHERE name = 'Embalagem Simples' AND packaging_option IS NULL AND @simples_linked = 0
    ORDER BY id LIMIT 1;

SELECT COUNT(*) INTO @vip_linked FROM menu_items WHERE packaging_option = 'viagem_vip';
UPDATE menu_items SET packaging_option = 'viagem_vip'
    WHERE name = 'Embalagem Especial' AND packaging_option IS NULL AND @vip_linked = 0
    ORDER BY id LIMIT 1;
