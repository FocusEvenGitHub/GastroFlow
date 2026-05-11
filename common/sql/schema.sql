CREATE DATABASE IF NOT EXISTS restaurant
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE restaurant;

-- Garantir acesso do usuário da aplicação vindo de qualquer host
CREATE USER IF NOT EXISTS 'restuser'@'%' IDENTIFIED BY 'restpass';
GRANT ALL PRIVILEGES ON restaurant.* TO 'restuser'@'%';
FLUSH PRIVILEGES;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
-- Tabela de categorias
CREATE TABLE IF NOT EXISTS categories (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
name VARCHAR(100) NOT NULL,
type ENUM('food', 'drink') NOT NULL,
PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de itens do cardápio
CREATE TABLE IF NOT EXISTS menu_items (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
category_id INT UNSIGNED NOT NULL,
name VARCHAR(100) NOT NULL,
description TEXT,
price DECIMAL(10,2) NOT NULL,
available BOOLEAN NOT NULL DEFAULT TRUE,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de pedidos
CREATE TABLE IF NOT EXISTS orders (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
table_number VARCHAR(50) NOT NULL,
status ENUM('pending','preparing','ready','done') NOT NULL DEFAULT 'pending',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de itens do pedido
CREATE TABLE IF NOT EXISTS order_items (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
order_id BIGINT UNSIGNED NOT NULL,
menu_item_id INT UNSIGNED NOT NULL,
quantity INT NOT NULL DEFAULT 1,
notes TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir categorias e itens de exemplo
INSERT INTO categories (name, type) VALUES
('Pratos Principais', 'food'),
('Acompanhamentos', 'food'),
('Bebidas', 'drink'),
('Sobremesas', 'food');

INSERT INTO menu_items (category_id, name, description, price) VALUES
(1, 'Feijoada Completa', 'Feijoada com arroz, couve, farofa e laranja', 25.90),
(1, 'Bife à Parmegiana', 'Bife à parmegiana com arroz e batata frita', 22.50),
(1, 'Frango Grelhado', 'Frango grelhado com legumes', 18.90),
(2, 'Arroz Branco', 'Porção de arroz branco', 5.00),
(2, 'Feijão Carioca', 'Porção de feijão', 5.00),
(2, 'Farofa', 'Farofa de bacon', 4.50),
(3, 'Coca-Cola', 'Lata 350ml', 5.00),
(3, 'Suco de Laranja', 'Copo 300ml', 7.00),
(4, 'Pudim', 'Pudim de leite condensado', 8.00);

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir um usuário admin padrão (senha: admin123, hash bcrypt)
INSERT INTO users (username, password, role) VALUES
    ('admin', '$2y$10$kAdCtbkdV7SCeV8aL3gJput/GXQsvgpjxTSI/lVfMhgaEPuXiMRry', 'admin');

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
ALTER TABLE ingredients ADD FOREIGN KEY (category_id) REFERENCES ingredient_categories(id);