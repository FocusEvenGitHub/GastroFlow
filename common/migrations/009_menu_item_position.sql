SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 009: Menu Item Position
-- Adiciona coluna position à tabela menu_items para permitir que o
-- Caixa reorganize manualmente os itens dentro de cada categoria.
-- No primeiro deploy, faz backfill pela ordem alfabética atual
-- (por categoria) para não embaralhar o cardápio existente.
-- ================================================================

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'position';

SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE menu_items ADD COLUMN position SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER available',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill só roda quando a coluna acabou de ser criada (evita
-- reordenar itens que o caixa já organizou manualmente em runs futuras).
SET @do_backfill = IF(@col_exists = 0, 1, 0);

SET @backfill_sql = IF(@do_backfill = 1,
    'UPDATE menu_items mi
        JOIN (
            SELECT id, ROW_NUMBER() OVER (PARTITION BY category_id ORDER BY name) AS rn
            FROM menu_items
        ) t ON mi.id = t.id
        SET mi.position = t.rn',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @backfill_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
