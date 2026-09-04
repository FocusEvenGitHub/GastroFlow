SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 011: User Roles (Authorization / RBAC)
-- Substitui o enum genérico ('admin','staff') pelo conjunto de papéis
-- sugerido no roadmap: admin, manager, cashier, kitchen. Linhas
-- existentes com role='staff' (nenhuma nesta instalação, mas tratado
-- de forma genérica para qualquer instalação) são migradas para
-- 'cashier' antes de estreitar o enum, para não quebrar em bancos
-- que já tenham essa role.
-- ================================================================

UPDATE users SET role = 'cashier' WHERE role = 'staff';

ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','cashier','kitchen') NOT NULL DEFAULT 'cashier';

SET FOREIGN_KEY_CHECKS = 1;
