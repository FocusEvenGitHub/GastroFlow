ALTER TABLE order_items
  ADD COLUMN unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dining_option,
  ADD COLUMN packaging_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_price;

-- Backfill: preencher unit_price e packaging_cost dos pedidos existentes
UPDATE order_items oi
  JOIN menu_items mi ON oi.menu_item_id = mi.id
  SET oi.unit_price = mi.price,
      oi.packaging_cost = CASE oi.dining_option
        WHEN 'viagem_simples' THEN 1.00 * oi.quantity
        WHEN 'viagem_vip'    THEN 2.00 * oi.quantity
        ELSE 0.00
      END
  WHERE oi.unit_price = 0.00;
