SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 005: Dining Option
-- Adiciona coluna dining_option à tabela order_items
-- Valores: 'local', 'viagem_simples', 'viagem_vip'
-- ================================================================

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'order_items'
    AND COLUMN_NAME = 'dining_option';

SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE order_items ADD COLUMN dining_option VARCHAR(20) NOT NULL DEFAULT ''local'' AFTER notes',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
