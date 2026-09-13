SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 015: Build-your-own dish ("Monte Seu Prato") (spec 030)
-- Adds a per-menu-item flag for dishes the cashier assembles from
-- Adicionais, seeds the "Monte Seu Prato" item in Pratos Principais
-- (base price R$0,00 — editable in Admin), and creates the table that
-- snapshots each chosen add-on (name/price/quantity) per order item.
-- Idempotent: pode ser reexecutada sem duplicar dados.
-- ================================================================

-- 1) menu_items.is_customizable
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'menu_items'
    AND COLUMN_NAME = 'is_customizable';
SET @alter_sql = IF(@col_exists = 0,
    'ALTER TABLE menu_items ADD COLUMN is_customizable TINYINT(1) NOT NULL DEFAULT 0 AFTER available',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) "Monte Seu Prato" em Pratos Principais (criado só se não existir pelo nome),
-- posicionado ao final da categoria.
INSERT INTO menu_items (category_id, name, description, price, available, is_customizable, position)
SELECT c.id, 'Monte Seu Prato', 'Escolha os adicionais', 0.00, 1, 1,
       COALESCE((SELECT MAX(mi.position) FROM menu_items mi WHERE mi.category_id = c.id), 0) + 1
FROM categories c
WHERE c.name = 'Pratos Principais'
  AND NOT EXISTS (
    SELECT 1 FROM menu_items mi2
    WHERE mi2.category_id = c.id AND mi2.name = 'Monte Seu Prato'
  );

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.is_customizable = 1
WHERE c.name = 'Pratos Principais' AND mi.name = 'Monte Seu Prato';

-- 3) Snapshot dos adicionais escolhidos por item de pedido
CREATE TABLE IF NOT EXISTS order_item_components (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_item_id BIGINT UNSIGNED NOT NULL,
    menu_item_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(100) NOT NULL DEFAULT '',
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    INDEX idx_order_item (order_item_id),
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
