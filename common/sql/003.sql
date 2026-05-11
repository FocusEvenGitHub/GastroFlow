
-- 1. Create ingredients table
CREATE TABLE IF NOT EXISTS ingredients (
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
unit VARCHAR(20) NOT NULL DEFAULT 'un',   -- 'un', 'g', 'ml', 'porção'
category VARCHAR(50) NULL                -- 'meat', 'grain', 'vegetable'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create pivot table for menu item <-> ingredient
CREATE TABLE IF NOT EXISTS item_ingredients (
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
menu_item_id INT UNSIGNED NOT NULL,
ingredient_id INT UNSIGNED NOT NULL,
quantity DECIMAL(8,2) NOT NULL,
FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Remove the old 'Acompanhamentos' category and its items (they are becoming ingredients)
DELETE FROM menu_items WHERE category_id = (SELECT id FROM categories WHERE name = 'Acompanhamentos');
DELETE FROM categories WHERE name = 'Acompanhamentos';

-- 4. Insert ingredients (converted from old side dishes)
INSERT INTO ingredients (name, unit, category) VALUES
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

-- 5. Define recipe for each main dish (update existing menu items)
-- Assuming existing main dishes: Feijoada Completa (id 1), Bife à Parmegiana (id 2), Frango Grelhado (id 3)
-- For Feijoada Completa: 1 porção de feijão (200g), 1 porção de arroz (150g), farofa (50g), couve (100g), laranja (1 un)
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
(1, (SELECT id FROM ingredients WHERE name='Feijão Carioca'), 200),
(1, (SELECT id FROM ingredients WHERE name='Arroz Branco'), 150),
(1, (SELECT id FROM ingredients WHERE name='Farofa'), 50),
(1, (SELECT id FROM ingredients WHERE name='Couve'), 100),
(1, (SELECT id FROM ingredients WHERE name='Laranja'), 1);

-- Bife à Parmegiana (id 2): contra-filé (1 un), arroz (150g), batata (150g), queijo (50g), molho (100ml)
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
(2, (SELECT id FROM ingredients WHERE name='Contra-filé'), 1),
(2, (SELECT id FROM ingredients WHERE name='Arroz Branco'), 150),
(2, (SELECT id FROM ingredients WHERE name='Batata'), 150),
(2, (SELECT id FROM ingredients WHERE name='Queijo Parmesão'), 50),
(2, (SELECT id FROM ingredients WHERE name='Molho de Tomate'), 100);

-- Frango Grelhado (id 3): peito de frango (1 un), arroz (150g), legumes (100g)
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
(3, (SELECT id FROM ingredients WHERE name='Peito de Frango'), 1),
(3, (SELECT id FROM ingredients WHERE name='Arroz Branco'), 150),
(3, (SELECT id FROM ingredients WHERE name='Legumes'), 100);

CREATE TABLE ingredient_categories (
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed data from the existing strings
INSERT INTO ingredient_categories (name) VALUES
('meat'),('protein'),('grain'),('vegetable'),('fruit'),('dairy'),('sauce');

-- Alter ingredients table: add category_id, drop old column
ALTER TABLE ingredients ADD COLUMN category_id INT UNSIGNED NULL;
UPDATE ingredients i JOIN ingredient_categories ic ON i.category = ic.name SET i.category_id = ic.id;
ALTER TABLE ingredients DROP COLUMN category;
ALTER TABLE ingredien