SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration: Empanado protein for Prato do Dia and parmesan dishes
-- - Frango Empanado passa a ser 'protein' (aparece nas Proteínas na Cozinha)
-- - Cria o item "Carne Empanada" nas Adicionais com food_category 'protein'
-- - Troca a proteína 'filé' das parmegianas por 'empanado'
-- Idempotente: UPDATE/INSERT ... WHERE NOT EXISTS / nome estável.
-- ================================================================

-- 1. Frango Empanado como proteína
UPDATE menu_items SET food_category = 'protein' WHERE name = 'Frango Empanado';

-- 2. Criar "Carne Empanada" se ainda não existir (preço igual ao Filé de Carne)
INSERT INTO menu_items (category_id, name, description, price, available, food_category)
SELECT 2, 'Carne Empanada', '', 15.00, 1, 'protein'
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE name = 'Carne Empanada');

-- 3. Resolver ids por nome (estáveis, evita dependência de id fixo)
SET @frango_empanado = (SELECT id FROM menu_items WHERE name = 'Frango Empanado' LIMIT 1);
SET @carne_empanada  = (SELECT id FROM menu_items WHERE name = 'Carne Empanada' LIMIT 1);
SET @filé_frango     = (SELECT id FROM menu_items WHERE name = 'Filé de Frango' LIMIT 1);
SET @filé_carne      = (SELECT id FROM menu_items WHERE name = 'Filé de Carne' LIMIT 1);
SET @prato_dia       = (SELECT id FROM menu_items WHERE name = 'Prato do Dia' LIMIT 1);
SET @parm_frango     = (SELECT id FROM menu_items WHERE name = 'Parmegiana de Frango' LIMIT 1);
SET @parm_carne      = (SELECT id FROM menu_items WHERE name = 'Parmegiana de Carne' LIMIT 1);

-- 4. Parmegiana de Frango: remover Filé de Frango e usar Frango Empanado
DELETE FROM dish_components WHERE dish_id = @parm_frango AND component_id = @filé_frango;
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES (@parm_frango, @frango_empanado, 1);

-- 5. Parmegiana de Carne: remover Filé de Carne e usar Carne Empanada
DELETE FROM dish_components WHERE dish_id = @parm_carne AND component_id = @filé_carne;
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES (@parm_carne, @carne_empanada, 1);

-- 6. Prato do Dia: garantir Frango Empanado como proteína
INSERT IGNORE INTO dish_components (dish_id, component_id, quantity) VALUES (@prato_dia, @frango_empanado, 1);

SET FOREIGN_KEY_CHECKS = 1;
