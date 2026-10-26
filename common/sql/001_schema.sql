CREATE DATABASE IF NOT EXISTS restaurant
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE restaurant;

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
customer_name VARCHAR(100) DEFAULT NULL,
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

-- Categorias estruturais: o código as procura pelo nome ('Pratos Principais', 'Adicionais'…)
-- e o Admin não cria categorias. Nenhum item de cardápio — cada restaurante cadastra o seu;
-- para uma demonstração, `bin/seed-demo` (spec 061).
INSERT INTO categories (name, type) VALUES
('Pratos Principais', 'food'),
('Adicionais', 'food'),
('Bebidas', 'drink'),
('Sobremesas', 'food'),
('Viagem', 'food');

-- Itens das taxas de embalagem: a migração 019 os liga a viagem_simples/viagem_vip pelo nome, e
-- o Admin não tem tela para fazer essa ligação. Preço editável no Admin (spec 050).
INSERT INTO menu_items (category_id, name, description, price) VALUES
(5, 'Embalagem Simples', '', 1.00),
(5, 'Embalagem Especial', '', 2.00);

-- Marcador de instalação nova (spec 061). Algumas migrações antigas ainda inserem o cardápio do
-- restaurante para o qual o GastroFlow foi feito; a 022 o remove só quando esta tabela existe e
-- depois a apaga. Um banco criado antes desta versão nunca a tem, então nunca é limpo.
CREATE TABLE IF NOT EXISTS fresh_install (
id TINYINT UNSIGNED NOT NULL,
PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;