SET NAMES utf8mb4;

-- ================================================================
-- Migration: Ingredients & Recipes
-- Cria as tabelas de ingredientes e receitas para a cozinha
-- ================================================================

-- 1. Create ingredients table (raw materials for the kitchen)
CREATE TABLE IF NOT EXISTS ingredients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'un',   -- 'un', 'g', 'ml', 'porção'
    category VARCHAR(50) NULL                 -- 'meat', 'grain', 'vegetable', etc.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create pivot table: menu items <-> ingredients (recipe)
CREATE TABLE IF NOT EXISTS item_ingredients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(8,2) NOT NULL,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Insert base ingredients (idempotent: IGNORE skips duplicates)
INSERT IGNORE INTO ingredients (name, unit, category) VALUES
    ('Arroz Branco', 'g', 'grain'),
    ('Feijão Carioca', 'g', 'grain'),
    ('Farofa', 'g', 'grain'),
    ('Peito de Frango', 'un', 'meat'),
    ('Contra-filé', 'un', 'meat'),
    ('Tilápia', 'un', 'meat'),
    ('Legumes', 'g', 'vegetable'),
    ('Couve', 'g', 'vegetable'),
    ('Laranja', 'un', 'fruit'),
    ('Batata', 'g', 'vegetable'),
    ('Queijo Parmesão', 'g', 'dairy'),
    ('Molho de Tomate', 'ml', 'sauce'),
    ('Ovo', 'un', 'protein');

-- 4. Define recipes for each main dish (idempotent: IGNORE skips duplicates)
-- Prato do Dia (id=1): Arroz, Feijão, Macarrão, Farofa, Batata Frita, Vinagrete
INSERT IGNORE INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
    (1, (SELECT id FROM ingredients WHERE name='Feijão Carioca'), 200),
    (1, (SELECT id FROM ingredients WHERE name='Arroz Branco'), 150),
    (1, (SELECT id FROM ingredients WHERE name='Farofa'), 50),
    (1, (SELECT id FROM ingredients WHERE name='Couve'), 100),
    (1, (SELECT id FROM ingredients WHERE name='Laranja'), 1);

-- Picadinho (id=2): Macarrão Acebolado, Linguiça de frango, Tiras de filé, Barbecue
INSERT IGNORE INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
    (2, (SELECT id FROM ingredients WHERE name='Contra-filé'), 1),
    (2, (SELECT id FROM ingredients WHERE name='Arroz Branco'), 150),
    (2, (SELECT id FROM ingredients WHERE name='Batata'), 150),
    (2, (SELECT id FROM ingredients WHERE name='Queijo Parmesão'), 50),
    (2, (SELECT id FROM ingredients WHERE name='Molho de Tomate'), 100);

-- Parmegiana de Frango (id=3): Macarrão alho óleo, Molho especial, Manjericão, Mussarela
INSERT IGNORE INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
    (3, (SELECT id FROM ingredients WHERE name='Peito de Frango'), 1),
    (3, (SELECT id FROM ingredients WHERE name='Arroz Branco'), 150),
    (3, (SELECT id FROM ingredients WHERE name='Legumes'), 100);
