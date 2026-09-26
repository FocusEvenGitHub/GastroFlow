SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration: Default "Pratos Principais" menu + dish components
-- Substitui os pratos principais divergentes pela lista canônica de
-- 13 pratos (nome, descrição, preço) e (re) atribui os componentes.
-- ================================================================

-- 1. Remover componentes dos pratos principais atuais (o FK cascade de menu_items
--    também limparia dish_components, mas fazemos de forma explícita)
DELETE FROM dish_components
WHERE dish_id IN (SELECT id FROM menu_items WHERE category_id = 1);

-- 2. Remover os pratos principais que divergem da lista canônica
DELETE FROM menu_items WHERE category_id = 1;

-- 3. Inserir a lista canônica de 13 pratos (ids explícitos 1-13)
INSERT IGNORE INTO menu_items (id, category_id, name, description, price) VALUES
(1,  1, 'Prato do Dia',           'parmegiana de frango, macarrão alho e óleo, molho especial, mussarela e arroz', 20.00),
(2,  1, 'Picadinho da Alegria',   'macarrão acebolado, linguiça de frango, tiras de filé e barbecue',             30.00),
(3,  1, 'Parmegiana de Frango',   'frango, macarrão alho e óleo, molho especial, manjericão e mussarela',         25.00),
(4,  1, 'Parmegiana de Carne',    'carne, macarrão alho e óleo, molho especial, manjericão e mussarela',          30.00),
(5,  1, 'Salada de Frango',       'frango, milho, rúcula, beterraba, alface, tomate e cenoura',                   20.00),
(6,  1, 'Salada de Carne',        'carne, milho, rúcula, beterraba, alface, tomate e cenoura',                    26.00),
(7,  1, 'Salada de Tilápia',      'tilápia, milho, rúcula, beterraba, alface, tomate e cenoura',                  26.00),
(8,  1, 'Luís de Frango',         'frango, arroz, feijão, salada e molho',                                        23.00),
(9,  1, 'Luís de Carne',          'carne, arroz, feijão, salada e molho',                                         28.00),
(10, 1, 'Luís de Tilápia',        'tilápia, arroz, feijão, salada e molho',                                       28.00),
(11, 1, 'Barça de Frango',        'frango, arroz, salada, fritas, molho e anéis de cebola',                       30.00),
(12, 1, 'Barça de Carne',         'carne, arroz, salada, fritas, molho e anéis de cebola',                        32.00),
(13, 1, 'Barça de Tilápia',       'tilápia, arroz, salada, fritas, molho e anéis de cebola',                      30.00);

-- 4. Atribuir componentes (component_id refere-se aos itens 'Adicionais')
-- Prato do Dia (1): Macarrão, Molhos, Arroz Branco
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(1, 25, 1), (1, 22, 1), (1, 23, 1);

-- Picadinho da Alegria (2): Macarrão, Linguiça Fina, Filé de Carne, Molhos
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(2, 25, 1), (2, 17, 1), (2, 16, 1), (2, 22, 1);

-- Parmegiana de Frango (3): Macarrão, Molhos, Filé de Frango
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(3, 25, 1), (3, 22, 1), (3, 15, 1);

-- Parmegiana de Carne (4): Macarrão, Molhos, Filé de Carne
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(4, 25, 1), (4, 22, 1), (4, 16, 1);

-- Salada de Frango (5): Salada, Filé de Frango
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(5, 21, 1), (5, 15, 1);

-- Salada de Carne (6): Salada, Filé de Carne
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(6, 21, 1), (6, 16, 1);

-- Salada de Tilápia (7): Salada, Filé de Tilápia
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(7, 21, 1), (7, 14, 1);

-- Luís de Frango (8): Arroz Branco, Feijão, Salada, Molhos, Filé de Frango
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(8, 23, 1), (8, 24, 1), (8, 21, 1), (8, 22, 1), (8, 15, 1);

-- Luís de Carne (9): Arroz Branco, Feijão, Salada, Molhos, Filé de Carne
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(9, 23, 1), (9, 24, 1), (9, 21, 1), (9, 22, 1), (9, 16, 1);

-- Luís de Tilápia (10): Arroz Branco, Feijão, Salada, Molhos, Filé de Tilápia
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(10, 23, 1), (10, 24, 1), (10, 21, 1), (10, 22, 1), (10, 14, 1);

-- Barça de Frango (11): Arroz Branco, Salada, Fritas, Molhos, Anel de Cebola, Filé de Frango
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(11, 23, 1), (11, 21, 1), (11, 26, 1), (11, 22, 1), (11, 30, 1), (11, 15, 1);

-- Barça de Carne (12): Arroz Branco, Salada, Fritas, Molhos, Anel de Cebola, Filé de Carne
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(12, 23, 1), (12, 21, 1), (12, 26, 1), (12, 22, 1), (12, 30, 1), (12, 16, 1);

-- Barça de Tilápia (13): Arroz Branco, Salada, Fritas, Molhos, Anel de Cebola, Filé de Tilápia
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES
(13, 23, 1), (13, 21, 1), (13, 26, 1), (13, 22, 1), (13, 30, 1), (13, 14, 1);

SET FOREIGN_KEY_CHECKS = 1;
