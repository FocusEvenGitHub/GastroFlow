SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 006: Settings Table
-- Armazena configurações do sistema (nome do restaurante, impressora, etc.)
-- ================================================================

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir valores padrão (ignora se já existirem)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('restaurant_name', 'GastroFlow'),
    ('printer_ip', '192.168.0.100'),
    ('printer_port', '9100');

SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE orders
    ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL AFTER table_number;