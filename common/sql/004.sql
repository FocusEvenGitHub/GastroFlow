SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Add food_category column to menu_items
ALTER TABLE menu_items
  ADD COLUMN food_category VARCHAR(30) NULL DEFAULT NULL AFTER available;

-- 2. Classify Adicionais by food category
UPDATE menu_items SET food_category = 'protein' WHERE name IN (
    'Filé de Tilápia', 'Filé de Frango', 'Filé de Carne', 'Linguiça Fina', 'Ovo Frito'
);
UPDATE menu_items SET food_category = 'grain' WHERE name IN (
    'Arroz Branco', 'Feijão Carioca', 'Farofa', 'Macarrão'
);
UPDATE menu_items SET food_category = 'vegetable' WHERE name IN (
    'Salada', 'Cebola'
);
UPDATE menu_items SET food_category = 'sauce' WHERE name IN (
    'Vinagrete', 'Molhos'
);
UPDATE menu_items SET food_category = 'side' WHERE name IN (
    'Fritas Individual', 'Fritas Cone', 'Fritas com Anel Cebola'
);
UPDATE menu_items SET food_category = 'other' WHERE name = 'Shot de Limão';

-- 3. Create dish_components pivot table (main dish → add-on items)
CREATE TABLE IF NOT EXISTS dish_components (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dish_id INT UNSIGNED NOT NULL,
    component_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    UNIQUE KEY (dish_id, component_id),
    FOREIGN KEY (dish_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
