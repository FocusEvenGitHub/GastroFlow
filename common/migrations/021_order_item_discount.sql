SET NAMES utf8mb4;

-- ================================================================
-- Migration 021: Desconto por item do pedido (spec 058)
-- Cada linha de order_items pode ter um desconto em % ou em R$,
-- dado no Caixa (ou corrigido no "Editar Pedido" da Cozinha).
-- discount_value guarda o que foi digitado (20.00 = 20% ou R$ 20,00);
-- discount_amount guarda o valor aplicado, em reais, e é o que os
-- relatórios subtraem. Linhas existentes ficam com desconto 0,00,
-- então nenhum total histórico muda.
-- O teto (%) fica em settings.discount_max_percent — padrão 100.
-- Idempotente: pode ser reexecutada sem duplicar dados.
-- ================================================================

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'discount_type';
SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE order_items ADD COLUMN discount_type ENUM(''percent'',''fixed'') NULL DEFAULT NULL AFTER packaging_cost',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'discount_value';
SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE order_items ADD COLUMN discount_value DECIMAL(10,2) NULL DEFAULT NULL AFTER discount_type',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'discount_amount';
SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE order_items ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_value',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO settings (`key`, `value`) VALUES ('discount_max_percent', '100');
