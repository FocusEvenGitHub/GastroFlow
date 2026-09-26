SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 018: Audit log for sensitive administrative operations
-- (spec 043). Deliberately separate from the `events` (spec 041)
-- and `jobs` (spec 033/016) tables: those exist for short-lived
-- operational recovery/replay and are pruned; this is a permanent
-- historical record and is never pruned. No FK to `users` on
-- purpose — `username` is snapshotted at write time so a later
-- username/role change (or a future user-deletion feature) never
-- rewrites or blocks history.
-- Idempotente: pode ser reexecutada sem duplicar dados.
-- ================================================================

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    username VARCHAR(100) NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT UNSIGNED NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_created_at (created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
