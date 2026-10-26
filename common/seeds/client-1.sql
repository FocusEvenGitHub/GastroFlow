-- ================================================================
-- Seed: cliente 1 (spec 061)
-- O cardápio do restaurante para o qual o GastroFlow foi feito — o
-- cliente piloto, usado para testes e implementações. Até a spec 061
-- toda instalação nova recebia isto; agora é opcional:
--   docker compose exec web php bin/seed-client-1
-- Gerado do estado que `001_schema.sql` + `bin/migrate` produziam no
-- master @ dbd8578 (antes da spec 061): categorias, itens, preços,
-- ingredientes e componentes dos pratos. Tudo é referenciado por nome
-- (únicos nesse estado), nunca por id, para aplicar sobre qualquer
-- instalação limpa. Não aplicar direto: bin/seed-client-1 recusa
-- rodar num banco que já tem cardápio.
-- ================================================================
SET NAMES utf8mb4;

-- Categorias (as estruturais já existem numa instalação limpa; só "Livros" é nova).
INSERT INTO categories (name, type) SELECT 'Pratos Principais', 'food' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Pratos Principais');
INSERT INTO categories (name, type) SELECT 'Adicionais', 'food' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Adicionais');
INSERT INTO categories (name, type) SELECT 'Bebidas', 'drink' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Bebidas');
INSERT INTO categories (name, type) SELECT 'Sobremesas', 'food' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Sobremesas');
INSERT INTO categories (name, type) SELECT 'Viagem', 'food' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Viagem');
INSERT INTO categories (name, type) SELECT 'Livros', 'food' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Livros');

-- Itens com função ("Monte Seu Prato", embalagens) já existem: só recebem os valores do cliente.
UPDATE menu_items SET name = 'Monte Seu Prato', description = 'Escolha os adicionais', price = '0.00', available = 1, position = 14 WHERE is_customizable = 1;
UPDATE menu_items SET name = 'Embalagem Especial', description = '', price = '2.00', available = 1, position = 3 WHERE packaging_option = 'viagem_vip';
UPDATE menu_items SET name = 'Embalagem Simples', description = '', price = '1.00', available = 1, position = 4 WHERE packaging_option = 'viagem_simples';

-- Cardápio.
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Barça de Carne', 'carne, arroz, salada, fritas, molho e anéis de cebola', '32.00', 1, 1, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Barça de Frango', 'frango, arroz, salada, fritas, molho e anéis de cebola', '30.00', 1, 2, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Barça de Tilápia', 'tilápia, arroz, salada, fritas, molho e anéis de cebola', '30.00', 1, 3, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Luís de Carne', 'Carne, Arroz, Feijão, Salada, Molho', '28.00', 1, 4, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Luís de Frango', 'Frango, Arroz, Feijão, Salada, Molho', '23.00', 1, 5, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Luís de Tilápia', 'Tilápia, Arroz, Feijão, Salada, Molho', '28.00', 1, 6, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Parmegiana de Carne', 'Carne empanada, Macarrão alho e óleo, Molho especial, Manjericão, Mussarela', '30.00', 1, 7, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Parmegiana de Frango', 'Frango empanado, Macarrão alho e óleo, Molho especial, Manjericão, Mussarela', '25.00', 1, 8, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Picadinho da Alegria', 'macarrão acebolado, linguiça de frango, tiras de filé e barbecue', '30.00', 1, 9, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Prato do Dia', 'Frango empanado, Macarrão alho e óleo, Molho especial, Mussarela, Arroz', '20.00', 1, 10, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Salada de Carne', 'Carne, Milho, Rúcula, Beterraba, Alface, Tomate, Cenoura', '26.00', 1, 11, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Salada de Frango', 'Frango, Milho, Rúcula, Beterraba, Alface, Tomate, Cenoura', '20.00', 1, 12, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Salada de Tilápia', 'Tilápia, Milho, Rúcula, Beterraba, Alface, Tomate, Cenoura', '26.00', 1, 13, NULL FROM categories WHERE name = 'Pratos Principais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Frango Empanado', '', '13.00', 1, 0, 'protein' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Carne Empanada', '', '15.00', 1, 0, 'protein' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Mussarela', '', '4.00', 1, 0, 'other' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Manjericão', '', '2.00', 1, 0, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Milho', '', '2.00', 1, 0, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Rúcula', '', '2.00', 1, 0, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Beterraba', '', '2.00', 1, 0, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Alface', '', '2.00', 1, 0, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Tomate', '', '2.00', 1, 0, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Cenoura', '', '2.00', 1, 0, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Arroz Branco', '', '4.00', 1, 1, 'grain' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Cebola', '', '4.00', 1, 2, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Farofa', '', '4.00', 1, 3, 'grain' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Feijão Carioca', '', '4.00', 1, 4, 'grain' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Filé de Carne', '', '15.00', 1, 5, 'protein' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Filé de Frango', '', '13.00', 1, 6, 'protein' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Filé de Tilápia', '', '15.00', 1, 7, 'protein' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Fritas com Anel Cebola', '', '20.00', 1, 8, 'side' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Fritas Cone', '', '15.00', 1, 9, 'side' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Fritas Individual', '', '8.00', 1, 10, 'side' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Linguiça Fina', '', '13.00', 1, 11, 'protein' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Macarrão', '', '7.00', 1, 12, 'grain' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Molhos', '', '2.00', 1, 13, 'sauce' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Ovo Frito', '', '2.00', 1, 14, 'protein' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Salada', '', '7.00', 1, 15, 'vegetable' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Shot de Limão', '', '2.00', 1, 16, 'other' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Vinagrete', '', '5.00', 1, 17, 'sauce' FROM categories WHERE name = 'Adicionais';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Àgua', '', '3.50', 1, 1, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Àgua com Gás', '', '5.00', 1, 2, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Bebidas 600ml', '', '9.00', 1, 3, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Café Gelado', '', '13.00', 1, 4, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Capuccino', '', '9.00', 1, 5, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Coca-Cola', 'Lata 350ml', '5.00', 1, 6, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Creme de Morango', '', '10.00', 1, 7, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Expresso com Leite', '', '7.00', 1, 8, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Expresso Curto', '', '5.00', 1, 9, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Expresso Longo', '', '7.00', 1, 10, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Frapê', '', '12.00', 1, 11, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Garrafa KS', '', '8.00', 1, 12, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Lata 220ml', '', '6.00', 1, 13, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Mocca', '', '15.00', 1, 14, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Refrigerante 1 Litro', '', '12.00', 1, 15, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Soda Italiana', '', '15.00', 1, 16, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Suco', '', '6.00', 1, 17, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Suco de Laranja', 'Copo 300ml', '7.00', 1, 18, NULL FROM categories WHERE name = 'Bebidas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Bolo no Pote', '', '8.00', 1, 1, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Doce de Leite', '', '20.00', 1, 2, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Quebra Queixo', '', '20.00', 1, 3, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Sorvete', 'Quilograma', '79.90', 1, 4, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Sorvete Pote 10', '', '10.00', 1, 5, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Sorvete Pote 13', '', '13.00', 1, 6, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Sorvete Pote 6', '', '6.00', 1, 7, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Sorvete Pote 8', '', '8.00', 1, 8, NULL FROM categories WHERE name = 'Sobremesas';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Bandeja Ovos', '', '20.00', 1, 1, NULL FROM categories WHERE name = 'Viagem';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Cartela de Ovo 17', '', '17.00', 1, 2, NULL FROM categories WHERE name = 'Viagem';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Manteiga de Garrafa', '', '30.00', 1, 5, NULL FROM categories WHERE name = 'Viagem';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Livro UDF 15', '', '15.40', 1, 1, NULL FROM categories WHERE name = 'Livros';
INSERT INTO menu_items (category_id, name, description, price, available, position, food_category) SELECT id, 'Livro UDF 22', '', '22.00', 1, 2, NULL FROM categories WHERE name = 'Livros';

-- Ingredientes.
INSERT INTO ingredients (name, unit, category) VALUES ('Arroz Branco', 'g', 'grain');
INSERT INTO ingredients (name, unit, category) VALUES ('Feijão Carioca', 'g', 'grain');
INSERT INTO ingredients (name, unit, category) VALUES ('Farofa', 'g', 'grain');
INSERT INTO ingredients (name, unit, category) VALUES ('Peito de Frango', 'un', 'meat');
INSERT INTO ingredients (name, unit, category) VALUES ('Contra-filé', 'un', 'meat');
INSERT INTO ingredients (name, unit, category) VALUES ('Tilápia', 'un', 'meat');
INSERT INTO ingredients (name, unit, category) VALUES ('Legumes', 'g', 'vegetable');
INSERT INTO ingredients (name, unit, category) VALUES ('Couve', 'g', 'vegetable');
INSERT INTO ingredients (name, unit, category) VALUES ('Laranja', 'un', 'fruit');
INSERT INTO ingredients (name, unit, category) VALUES ('Batata', 'g', 'vegetable');
INSERT INTO ingredients (name, unit, category) VALUES ('Queijo Parmesão', 'g', 'dairy');
INSERT INTO ingredients (name, unit, category) VALUES ('Molho de Tomate', 'ml', 'sauce');
INSERT INTO ingredients (name, unit, category) VALUES ('Ovo', 'un', 'protein');
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '200.00' FROM menu_items m JOIN ingredients i ON i.name = 'Feijão Carioca' WHERE m.name = 'Prato do Dia';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '150.00' FROM menu_items m JOIN ingredients i ON i.name = 'Arroz Branco' WHERE m.name = 'Prato do Dia';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '50.00' FROM menu_items m JOIN ingredients i ON i.name = 'Farofa' WHERE m.name = 'Prato do Dia';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '100.00' FROM menu_items m JOIN ingredients i ON i.name = 'Couve' WHERE m.name = 'Prato do Dia';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '1.00' FROM menu_items m JOIN ingredients i ON i.name = 'Laranja' WHERE m.name = 'Prato do Dia';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '1.00' FROM menu_items m JOIN ingredients i ON i.name = 'Contra-filé' WHERE m.name = 'Picadinho da Alegria';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '150.00' FROM menu_items m JOIN ingredients i ON i.name = 'Arroz Branco' WHERE m.name = 'Picadinho da Alegria';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '150.00' FROM menu_items m JOIN ingredients i ON i.name = 'Batata' WHERE m.name = 'Picadinho da Alegria';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '50.00' FROM menu_items m JOIN ingredients i ON i.name = 'Queijo Parmesão' WHERE m.name = 'Picadinho da Alegria';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '100.00' FROM menu_items m JOIN ingredients i ON i.name = 'Molho de Tomate' WHERE m.name = 'Picadinho da Alegria';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '1.00' FROM menu_items m JOIN ingredients i ON i.name = 'Peito de Frango' WHERE m.name = 'Parmegiana de Frango';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '150.00' FROM menu_items m JOIN ingredients i ON i.name = 'Arroz Branco' WHERE m.name = 'Parmegiana de Frango';
INSERT INTO item_ingredients (menu_item_id, ingredient_id, quantity) SELECT m.id, i.id, '100.00' FROM menu_items m JOIN ingredients i ON i.name = 'Legumes' WHERE m.name = 'Parmegiana de Frango';

-- Componentes de cada prato principal.
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Macarrão' WHERE d.name = 'Prato do Dia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Prato do Dia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Arroz Branco' WHERE d.name = 'Prato do Dia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Macarrão' WHERE d.name = 'Picadinho da Alegria';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Linguiça Fina' WHERE d.name = 'Picadinho da Alegria';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Carne' WHERE d.name = 'Picadinho da Alegria';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Picadinho da Alegria';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Macarrão' WHERE d.name = 'Parmegiana de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Parmegiana de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Macarrão' WHERE d.name = 'Parmegiana de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Parmegiana de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Frango' WHERE d.name = 'Salada de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Carne' WHERE d.name = 'Salada de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Tilápia' WHERE d.name = 'Salada de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Arroz Branco' WHERE d.name = 'Luís de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Feijão Carioca' WHERE d.name = 'Luís de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Salada' WHERE d.name = 'Luís de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Luís de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Frango' WHERE d.name = 'Luís de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Arroz Branco' WHERE d.name = 'Luís de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Feijão Carioca' WHERE d.name = 'Luís de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Salada' WHERE d.name = 'Luís de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Luís de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Carne' WHERE d.name = 'Luís de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Arroz Branco' WHERE d.name = 'Luís de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Feijão Carioca' WHERE d.name = 'Luís de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Salada' WHERE d.name = 'Luís de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Luís de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Tilápia' WHERE d.name = 'Luís de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Arroz Branco' WHERE d.name = 'Barça de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Salada' WHERE d.name = 'Barça de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Fritas Individual' WHERE d.name = 'Barça de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Barça de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Fritas com Anel Cebola' WHERE d.name = 'Barça de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Frango' WHERE d.name = 'Barça de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Arroz Branco' WHERE d.name = 'Barça de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Salada' WHERE d.name = 'Barça de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Fritas Individual' WHERE d.name = 'Barça de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Barça de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Fritas com Anel Cebola' WHERE d.name = 'Barça de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Carne' WHERE d.name = 'Barça de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Arroz Branco' WHERE d.name = 'Barça de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Salada' WHERE d.name = 'Barça de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Fritas Individual' WHERE d.name = 'Barça de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Molhos' WHERE d.name = 'Barça de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Fritas com Anel Cebola' WHERE d.name = 'Barça de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Filé de Tilápia' WHERE d.name = 'Barça de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Frango Empanado' WHERE d.name = 'Prato do Dia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Mussarela' WHERE d.name = 'Prato do Dia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Frango Empanado' WHERE d.name = 'Parmegiana de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Mussarela' WHERE d.name = 'Parmegiana de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Manjericão' WHERE d.name = 'Parmegiana de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Carne Empanada' WHERE d.name = 'Parmegiana de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Mussarela' WHERE d.name = 'Parmegiana de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Manjericão' WHERE d.name = 'Parmegiana de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Milho' WHERE d.name = 'Salada de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Rúcula' WHERE d.name = 'Salada de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Beterraba' WHERE d.name = 'Salada de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Alface' WHERE d.name = 'Salada de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Tomate' WHERE d.name = 'Salada de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Cenoura' WHERE d.name = 'Salada de Frango';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Milho' WHERE d.name = 'Salada de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Rúcula' WHERE d.name = 'Salada de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Beterraba' WHERE d.name = 'Salada de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Alface' WHERE d.name = 'Salada de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Tomate' WHERE d.name = 'Salada de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Cenoura' WHERE d.name = 'Salada de Carne';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Milho' WHERE d.name = 'Salada de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Rúcula' WHERE d.name = 'Salada de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Beterraba' WHERE d.name = 'Salada de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Alface' WHERE d.name = 'Salada de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Tomate' WHERE d.name = 'Salada de Tilápia';
INSERT INTO dish_components (dish_id, component_id, quantity) SELECT d.id, k.id, 1 FROM menu_items d JOIN menu_items k ON k.name = 'Cenoura' WHERE d.name = 'Salada de Tilápia';
