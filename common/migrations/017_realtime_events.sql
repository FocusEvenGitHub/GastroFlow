SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 017: Realtime events table (spec 041)
-- Substitui o mecanismo de sinal por arquivo do SSE da cozinha
-- (sys_get_temp_dir()/gastroflow-events.json), que é por container e
-- guarda só o último evento — um evento do worker de impressão nunca
-- chegava à cozinha, servida pelo container web (confirmado quebrado
-- nas specs 038-040). `id` autoincremento serve de cursor de
-- reconexão SSE (Last-Event-ID), o mesmo papel que jobs.id já tem
-- para ordem FIFO (migração 007).
-- Idempotente: pode ser reexecutada sem duplicar dados.
-- ================================================================

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(50) NOT NULL,
    order_id INT UNSIGNED NULL,
    payload JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
