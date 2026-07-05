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

-- ----------------------------
-- 1. Categorias dos pratos
-- ----------------------------
CREATE TABLE IF NOT EXISTS categories (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
name VARCHAR(100) NOT NULL,
type VARCHAR(50) NOT NULL,          -- tipo livre (ex: 'comida', 'bebida')
PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir categorias padrao
INSERT INTO categories (name, type) VALUES
('Pratos Principais', 'comida'),
('Bebidas', 'bebida'),
('Sobremesas', 'comida');

-- ----------------------------
-- 2. Itens do cardapio
-- ----------------------------
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

-- Pratos principais (category_id=1)
INSERT INTO menu_items (category_id, name, description, price) VALUES
(1, 'Feijoada Completa', 'Feijoada com arroz, couve, farofa e laranja', 25.90),
(1, 'Bife à Parmegiana', 'Bife à parmegiana com arroz e batata frita', 22.50),
(1, 'Frango Grelhado', 'Frango grelhado com legumes', 18.90);

-- Bebidas (category_id=2)
INSERT INTO menu_items (category_id, name, description, price) VALUES
(2, 'Coca-Cola', 'Lata 350ml', 5.00),
(2, 'Suco de Laranja', 'Copo 300ml', 7.00);

-- Sobremesas (category_id=3)
INSERT INTO menu_items (category_id, name, description, price) VALUES
(3, 'Pudim', 'Pudim de leite condensado', 8.00);

-- ----------------------------
-- 3. Pedidos
-- ----------------------------
CREATE TABLE IF NOT EXISTS orders (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
table_number VARCHAR(50) NOT NULL,
status ENUM('pending','preparing','ready','done') NOT NULL DEFAULT 'pending',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- 4. Itens do pedido
-- ----------------------------
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

-- ----------------------------
-- 5. Usuarios
-- ----------------------------
CREATE TABLE IF NOT EXISTS users (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
username VARCHAR(50) NOT NULL UNIQUE,
password VARCHAR(255) NOT NULL,
role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin padrão (senha: admin123)
INSERT INTO users (username, password, role) VALUES
('admin', '$2y$10$kAdCtbkdV7SCeV8aL3gJput/GXQsvgpjxTSI/lVfMhgaEPuXiMRry', 'admin');

-- ----------------------------
-- 6. Categorias de ingredientes
-- ----------------------------
CREATE TABLE IF NOT EXISTS ingredient_categories (
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ingredient_categories (name) VALUES
('Carnes'),
('Proteínas'),
('Grãos / Acompanhamentos'),
('Vegetais'),
('Frutas'),
('Laticínios'),
('Molhos');

-- ----------------------------
-- 7. Ingredientes
-- ----------------------------
CREATE TABLE IF NOT EXISTS ingredients (
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
unit VARCHAR(20) NOT NULL DEFAULT 'un',
category_id INT UNSIGNED NULL,
FOREIGN KEY (category_id) REFERENCES ingredient_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir ingredientes com a categoria correta (usando subselect com base no nome da categoria)
INSERT INTO ingredients (name, unit, category_id) VALUES
('Arroz Branco', 'g',        (SELECT id FROM ingredient_categories WHERE name = 'Grãos / Acompanhamentos')),
('Feijão Carioca', 'g',      (SELECT id FROM ingredient_categories WHERE name = 'Grãos / Acompanhamentos')),
('Farofa', 'g',              (SELECT id FROM ingredient_categories WHERE name = 'Grãos / Acompanhamentos')),
('Peito de Frango', 'un',    (SELECT id FROM ingredient_categories WHERE name = 'Carnes')),
('Contra-filé', 'un',        (SELECT id FROM ingredient_categories WHERE name = 'Carnes')),
('Tilápia', 'un',            (SELECT id FROM ingredient_categories WHERE name = 'Carnes')),
('Legumes', 'g',             (SELECT id FROM ingredient_categories WHERE name = 'Vegetais')),
('Couve', 'g',               (SELECT id FROM ingredient_categories WHERE name = 'Vegetais')),
('Laranja', 'un',            (SELECT id FROM ingredient_categories WHERE name = 'Frutas')),
('Batata', 'g',              (SELECT id FROM ingredient_categories WHERE name = 'Vegetais')),
('Queijo Parmesão', 'g',     (SELECT id FROM ingredient_categories WHERE name = 'Laticínios')),
('Molho de Tomate', 'ml',    (SELECT id FROM ingredient_categories WHERE name = 'Molhos')),
('Ovo', 'un',                (SELECT id FROM ingredient_categories WHERE name = 'Proteínas'));

-- ----------------------------
-- 8. Vinculo prato <-> ingrediente
-- ----------------------------
CREATE TABLE IF NOT EXISTS item_ingredients (
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
menu_item_id INT UNSIGNED NOT NULL,
ingredient_id INT UNSIGNED NOT NULL,
quantity DECIMAL(8,2) NOT NULL,
FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receita Feijoada Completa
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
(1, (SELECT id FROM ingredients WHERE name = 'Feijão Carioca'), 200),
(1, (SELECT id FROM ingredients WHERE name = 'Arroz Branco'), 150),
(1, (SELECT id FROM ingredients WHERE name = 'Farofa'), 50),
(1, (SELECT id FROM ingredients WHERE name = 'Couve'), 100),
(1, (SELECT id FROM ingredients WHERE name = 'Laranja'), 1);

-- Receita Bife à Parmegiana
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
(2, (SELECT id FROM ingredients WHERE name = 'Contra-filé'), 1),
(2, (SELECT id FROM ingredients WHERE name = 'Arroz Branco'), 150),
(2, (SELECT id FROM ingredients WHERE name = 'Batata'), 150),
(2, (SELECT id FROM ingredients WHERE name = 'Queijo Parmesão'), 50),
(2, (SELECT id FROM ingredients WHERE name = 'Molho de Tomate'), 100);

-- Receita Frango Grelhado
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) VALUES
(3, (SELECT id FROM ingredients WHERE name = 'Peito de Frango'), 1),
(3, (SELECT id FROM ingredients WHERE name = 'Arroz Branco'), 150),
(3, (SELECT id FROM ingredients WHERE name = 'Legumes'), 100);

SET FOREIGN_KEY_CHECKS = 1;