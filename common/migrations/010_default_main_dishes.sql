SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 010: Default Main Dishes (Pratos Principais)
-- Atualiza o cardápio padrão de Pratos Principais para o conjunto
-- pedido: renomeia/reprecifica pratos existentes, separa "Barça" em
-- variantes de proteína (Frango/Carne/Tilápia), cria os adicionais que
-- faltam (proteínas empanadas, mussarela, manjericão, vegetais da
-- salada) e corrige dish_components (parmegiana usa proteína empanada,
-- não filé). Tudo resolvido por nome (não por id numérico), então
-- funciona tanto numa instalação nova quanto numa já em uso.
-- Idempotente: pode ser reexecutada sem duplicar dados.
-- ================================================================

-- ---------------------------------------------------------------
-- 1) Adicionais que faltam (criados só se não existirem pelo nome)
-- ---------------------------------------------------------------
INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Frango Empanado', '', 13.00, 'protein' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Frango Empanado'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Carne Empanada', '', 15.00, 'protein' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Carne Empanada'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Mussarela', '', 4.00, 'other' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Mussarela'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Manjericão', '', 2.00, 'vegetable' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Manjericão'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Milho', '', 2.00, 'vegetable' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Milho'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Rúcula', '', 2.00, 'vegetable' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Rúcula'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Beterraba', '', 2.00, 'vegetable' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Beterraba'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Alface', '', 2.00, 'vegetable' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Alface'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Tomate', '', 2.00, 'vegetable' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Tomate'
);

INSERT INTO menu_items (category_id, name, description, price, food_category)
SELECT (SELECT id FROM categories WHERE name = 'Adicionais'), 'Cenoura', '', 2.00, 'vegetable' FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Adicionais' AND mi.name = 'Cenoura'
);

-- ---------------------------------------------------------------
-- 2) Pratos Principais existentes: nome / preço / descrição
-- ---------------------------------------------------------------
UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.name = 'Picadinho da Alegria', mi.price = 30.00,
    mi.description = 'Macarrão acebolado, Linguiça de frango, Tiras de filé, Barbecue'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Picadinho';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.description = 'Frango empanado, Macarrão alho e óleo, Molho especial, Manjericão, Mussarela'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Parmegiana de Frango';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.price = 30.00,
    mi.description = 'Carne empanada, Macarrão alho e óleo, Molho especial, Manjericão, Mussarela'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Parmegiana de Carne';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.description = 'Frango, Milho, Rúcula, Beterraba, Alface, Tomate, Cenoura'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Salada de Frango';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.price = 26.00,
    mi.description = 'Carne, Milho, Rúcula, Beterraba, Alface, Tomate, Cenoura'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Salada de Carne';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.description = 'Tilápia, Milho, Rúcula, Beterraba, Alface, Tomate, Cenoura'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Salada de Tilápia';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.description = 'Frango, Arroz, Feijão, Salada, Molho'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Luís de Frango';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.price = 28.00,
    mi.description = 'Carne, Arroz, Feijão, Salada, Molho'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Luís de Carne';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.description = 'Tilápia, Arroz, Feijão, Salada, Molho'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Luís de Tilápia';

UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.price = 20.00,
    mi.description = 'Frango empanado, Macarrão alho e óleo, Molho especial, Mussarela, Arroz'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Prato do Dia';

-- "Barça" vira "Barça de Frango" (as outras duas variantes de proteína são novas, abaixo)
UPDATE menu_items mi JOIN categories c ON c.id = mi.category_id
SET mi.name = 'Barça de Frango', mi.price = 30.00,
    mi.description = 'Frango, Arroz, Salada, Fritas, Molho, Anel de Cebola'
WHERE c.name = 'Pratos Principais' AND mi.name = 'Barça';

-- ---------------------------------------------------------------
-- 3) Novos Pratos Principais (Barça de Carne / Barça de Tilápia)
-- ---------------------------------------------------------------
INSERT INTO menu_items (category_id, name, description, price)
SELECT (SELECT id FROM categories WHERE name = 'Pratos Principais'),
       'Barça de Carne', 'Carne, Arroz, Salada, Fritas, Molho, Anel de Cebola', 32.00 FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Pratos Principais' AND mi.name = 'Barça de Carne'
);

INSERT INTO menu_items (category_id, name, description, price)
SELECT (SELECT id FROM categories WHERE name = 'Pratos Principais'),
       'Barça de Tilápia', 'Tilápia, Arroz, Salada, Fritas, Molho, Anel de Cebola', 30.00 FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM menu_items mi JOIN categories c ON c.id = mi.category_id
    WHERE c.name = 'Pratos Principais' AND mi.name = 'Barça de Tilápia'
);

-- ---------------------------------------------------------------
-- 4) Corrigir dish_components (proteína correta + adicionais que faltam)
-- ---------------------------------------------------------------

-- Parmegianas: remove o vínculo errado com filé (parmegiana é empanada, não filé)
DELETE dc FROM dish_components dc
    JOIN menu_items d ON d.id = dc.dish_id
    JOIN menu_items comp ON comp.id = dc.component_id
WHERE d.name = 'Parmegiana de Frango' AND comp.name = 'Filé de Frango';

DELETE dc FROM dish_components dc
    JOIN menu_items d ON d.id = dc.dish_id
    JOIN menu_items comp ON comp.id = dc.component_id
WHERE d.name = 'Parmegiana de Carne' AND comp.name = 'Filé de Carne';

-- Prato do Dia: composição nova, remove os ingredientes antigos que saíram da receita
DELETE dc FROM dish_components dc
    JOIN menu_items d ON d.id = dc.dish_id
    JOIN menu_items comp ON comp.id = dc.component_id
WHERE d.name = 'Prato do Dia' AND comp.name IN ('Farofa', 'Fritas Individual', 'Vinagrete', 'Feijão Carioca');

-- Saladas: troca o "Salada" genérico pelos vegetais individuais que o pedido detalhou
DELETE dc FROM dish_components dc
    JOIN menu_items d ON d.id = dc.dish_id
    JOIN menu_items comp ON comp.id = dc.component_id
    JOIN categories dcat ON dcat.id = d.category_id
WHERE dcat.name = 'Pratos Principais' AND d.name IN ('Salada de Frango', 'Salada de Carne', 'Salada de Tilápia')
    AND comp.name IN ('Salada', 'Salada Normal');

-- Vincula os componentes corretos (INSERT IGNORE evita duplicar em reexecuções)
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity)
SELECT d.id, c.id, 1 FROM menu_items d, menu_items c, categories dcat, categories ccat
WHERE d.category_id = dcat.id AND dcat.name = 'Pratos Principais'
  AND c.category_id = ccat.id AND ccat.name = 'Adicionais'
  AND (
    (d.name = 'Prato do Dia' AND c.name IN ('Frango Empanado', 'Molhos', 'Mussarela'))
    OR (d.name = 'Parmegiana de Frango' AND c.name IN ('Frango Empanado', 'Mussarela', 'Manjericão'))
    OR (d.name = 'Parmegiana de Carne' AND c.name IN ('Carne Empanada', 'Mussarela', 'Manjericão'))
    OR (d.name = 'Salada de Frango' AND c.name IN ('Milho', 'Rúcula', 'Beterraba', 'Alface', 'Tomate', 'Cenoura'))
    OR (d.name = 'Salada de Carne' AND c.name IN ('Milho', 'Rúcula', 'Beterraba', 'Alface', 'Tomate', 'Cenoura'))
    OR (d.name = 'Salada de Tilápia' AND c.name IN ('Milho', 'Rúcula', 'Beterraba', 'Alface', 'Tomate', 'Cenoura'))
    OR (d.name = 'Barça de Frango' AND c.name = 'Filé de Frango')
    OR (d.name = 'Barça de Carne' AND c.name IN ('Arroz Branco', 'Salada', 'Salada Normal', 'Fritas com Anel Cebola', 'Molhos', 'Filé de Carne'))
    OR (d.name = 'Barça de Tilápia' AND c.name IN ('Arroz Branco', 'Salada', 'Salada Normal', 'Fritas com Anel Cebola', 'Molhos', 'Filé de Tilápia'))
  );

SET FOREIGN_KEY_CHECKS = 1;
