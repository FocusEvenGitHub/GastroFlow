SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Limpar dados de teste anteriores
TRUNCATE TABLE dish_components;

-- Popular dish_components baseado no XuxuMenu
-- Prato do Dia (id=1): Arroz, Feijão, Macarrão, Farofa, Batata Frita, Vinagrete
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(1, 23, 1), (1, 24, 1), (1, 25, 1), (1, 18, 1), (1, 26, 1), (1, 20, 1);

-- Picadinho (id=2): Macarrão Acebolado, Linguiça de frango, Tiras de filé, Barbecue
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(2, 25, 1), (2, 17, 1), (2, 16, 1), (2, 22, 1);

-- Parmegiana de Frango (id=3): Macarrão alho óleo, Molho especial, Manjericão, Mussarela
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(3, 25, 1), (3, 22, 1), (3, 15, 1);

-- Parmegiana de Carne (id=4): Macarrão alho óleo, Molho especial, Manjericão, Mussarela
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(4, 25, 1), (4, 22, 1), (4, 16, 1);

-- Salada de Frango (id=5)
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(5, 21, 1), (5, 15, 1);

-- Salada de Carne (id=6)
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(6, 21, 1), (6, 16, 1);

-- Salada de Tilápia (id=7)
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(7, 21, 1), (7, 14, 1);

-- Luís de Frango (id=8): Arroz, Feijão, Salada, Molho
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(8, 23, 1), (8, 24, 1), (8, 21, 1), (8, 22, 1), (8, 15, 1);

-- Luís de Carne (id=9)
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(9, 23, 1), (9, 24, 1), (9, 21, 1), (9, 22, 1), (9, 16, 1);

-- Luís de Tilápia (id=10)
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(10, 23, 1), (10, 24, 1), (10, 21, 1), (10, 22, 1), (10, 14, 1);

-- Barça (id=11): Arroz, Salada, Fritas, Molho, Anel de Cebola
INSERT INTO dish_components (dish_id, component_id, quantity) VALUES
(11, 23, 1), (11, 21, 1), (11, 30, 1), (11, 22, 1);

SET FOREIGN_KEY_CHECKS = 1;
