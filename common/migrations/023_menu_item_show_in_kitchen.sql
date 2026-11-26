SET NAMES utf8mb4;

-- ================================================================
-- Migration 023: Item aparece na Cozinha (spec 087, issue #80)
-- A cozinha deixava de filtrar por nome de categoria fixo
-- ('Pratos Principais', 'Adicionais'): cada item do cardápio passa
-- a ter show_in_kitchen, ligado/desligado no Admin.
-- Para nada mudar na tela no dia da atualização, os itens das duas
-- categorias que a cozinha mostrava são ligados — só quando a coluna
-- é criada agora, para não religar o que o Admin já desligou.
-- Idempotente: pode ser reexecutada sem alterar dados.
-- ================================================================

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'show_in_kitchen';

SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE menu_items ADD COLUMN show_in_kitchen TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @update_sql = IF(@col_exists = 0,
    'UPDATE menu_items m JOIN categories c ON c.id = m.category_id SET m.show_in_kitchen = 1 WHERE c.name IN (''Pratos Principais'', ''Adicionais'')',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @update_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
