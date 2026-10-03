SET NAMES utf8mb4;

-- ================================================================
-- Migration 020: Failed login attempts, for login throttling
-- (spec 053). One row per failed POST /api/login: client IP,
-- normalized username and time. No password or hash is stored.
-- Rows older than the 15-minute window are deleted by the app
-- itself (LoginThrottleService), so this table stays small.
-- Idempotente: pode ser reexecutada sem duplicar dados.
-- ================================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(100) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_login_attempts_ip_user (ip, username, attempted_at),
    INDEX idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
