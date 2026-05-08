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