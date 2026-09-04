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

-- Inserir categorias e itens do cardápio
INSERT INTO categories (name, type) VALUES
('Pratos Principais', 'food'),
('Adicionais', 'food'),
('Bebidas', 'drink'),
('Sobremesas', 'food'),
('Viagem', 'food'),
('Livros', 'food');

INSERT INTO menu_items (category_id, name, description, price) VALUES
-- Pratos Principais (category_id = 1)
(1, 'Prato do Dia', 'Arroz, Feijão, Macarrão, Farofa, Batata Frita, Vinagrete', 20.00),
(1, 'Picadinho', 'Macarrão Acebolado, Linguiça de frango, Tiras de filé, Barbecue', 28.00),
(1, 'Parmegiana de Frango', 'Macarrão alho óleo, Molho especial, Manjericão, Mussarela', 25.00),
(1, 'Parmegiana de Carne', 'Macarrão alho óleo, Molho especial, Manjericão, Mussarela', 28.00),
(1, 'Salada de Frango', 'Milho, Alface, Rúcula, Tomate, Beterraba, Cenoura', 20.00),
(1, 'Salada de Carne', 'Milho, Alface, Rúcula, Tomate, Beterraba, Cenoura', 23.00),
(1, 'Salada de Tilápia', 'Milho, Alface, Rúcula, Tomate, Beterraba, Cenoura', 26.00),
(1, 'Luís de Frango', 'Arroz, Feijão, Salada, Molho', 23.00),
(1, 'Luís de Carne', 'Arroz, Feijão, Salada, Molho', 26.00),
(1, 'Luís de Tilápia', 'Arroz, Feijão, Salada, Molho', 28.00),
(1, 'Barça', 'Arroz, Salada, Fritas, Molho, Anel de Cebola', 30.00),
(1, 'Especial X', '', 15.00),
(1, 'Prato Turbo', '', 25.00),
-- Adicionais (category_id = 2)
(2, 'Filé de Tilápia', '', 15.00),
(2, 'Filé de Frango', '', 13.00),
(2, 'Filé de Carne', '', 15.00),
(2, 'Linguiça Fina', '', 13.00),
(2, 'Farofa', '', 4.00),
(2, 'Cebola', '', 4.00),
(2, 'Vinagrete', '', 5.00),
(2, 'Salada', '', 7.00),
(2, 'Molhos', '', 2.00),
(2, 'Arroz Branco', '', 4.00),
(2, 'Feijão Carioca', '', 4.00),
(2, 'Macarrão', '', 7.00),
(2, 'Fritas Individual', '', 8.00),
(2, 'Fritas Cone', '', 15.00),
(2, 'Ovo Frito', '', 2.00),
(2, 'Shot de Limão', '', 2.00),
(2, 'Fritas com Anel Cebola', '', 20.00),
-- Bebidas (category_id = 3)
(3, 'Coca-Cola', 'Lata 350ml', 5.00),
(3, 'Suco de Laranja', 'Copo 300ml', 7.00),
(3, 'Àgua com Gás', '', 5.00),
(3, 'Suco', '', 6.00),
(3, 'Expresso Curto', '', 5.00),
(3, 'Expresso Longo', '', 7.00),
(3, 'Expresso com Leite', '', 7.00),
(3, 'Lata 220ml', '', 6.00),
(3, 'Creme de Morango', '', 10.00),
(3, 'Àgua', '', 3.50),
(3, 'Bebidas 600ml', '', 9.00),
(3, 'Soda Italiana', '', 15.00),
(3, 'Mocca', '', 15.00),
(3, 'Capuccino', '', 9.00),
(3, 'Frapê', '', 12.00),
(3, 'Café Gelado', '', 13.00),
(3, 'Garrafa KS', '', 8.00),
(3, 'Refrigerante 1 Litro', '', 12.00),
-- Sobremesas (category_id = 4)
(4, 'Bolo no Pote', '', 8.00),
(4, 'Sorvete Pote 6', '', 6.00),
(4, 'Sorvete Pote 8', '', 8.00),
(4, 'Sorvete Pote 10', '', 10.00),
(4, 'Sorvete Pote 13', '', 13.00),
(4, 'Quebra Queixo', '', 20.00),
(4, 'Doce de Leite', '', 20.00),
(4, 'Sorvete', 'Quilograma', 79.90),
-- Viagem (category_id = 5)
(5, 'Embalagem Simples', '', 1.00),
(5, 'Embalagem Especial', '', 2.00),
(5, 'Bandeja Ovos', '', 20.00),
(5, 'Cartela de Ovo 17', '', 17.00),
(5, 'Manteiga de Garrafa', '', 30.00),
-- Livros (category_id = 6)
(6, 'Livro UDF 15', '', 15.40),
(6, 'Livro UDF 22', '', 22.00);

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;