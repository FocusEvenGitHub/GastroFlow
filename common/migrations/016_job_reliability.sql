SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 016: Job reliability / queue observability (spec 033)
-- Adds an explicit job status, a reservation deadline and the
-- diagnostic columns needed to see why a job failed or got stuck.
-- Backfills existing rows so a live queue keeps working: exhausted
-- jobs become 'failed', jobs stuck with a reservation become
-- 'reserved' with a deadline already in the past (so the first
-- sweep after deploy recovers them), everything else stays 'pending'.
-- Idempotent: pode ser reexecutada sem duplicar dados.
-- ================================================================

-- 1) jobs.status
SELECT COUNT(*) INTO @status_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jobs'
    AND COLUMN_NAME = 'status';
SET @status_sql = IF(@status_exists = 0,
    "ALTER TABLE jobs ADD COLUMN status ENUM('pending','reserved','completed','failed') NOT NULL DEFAULT 'pending' AFTER payload",
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) jobs.reserved_until — prazo explícito da reserva
SELECT COUNT(*) INTO @until_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jobs'
    AND COLUMN_NAME = 'reserved_until';
SET @until_sql = IF(@until_exists = 0,
    'ALTER TABLE jobs ADD COLUMN reserved_until TIMESTAMP NULL DEFAULT NULL AFTER reserved_at',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @until_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) jobs.last_error
SELECT COUNT(*) INTO @error_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jobs'
    AND COLUMN_NAME = 'last_error';
SET @error_sql = IF(@error_exists = 0,
    'ALTER TABLE jobs ADD COLUMN last_error VARCHAR(1000) NULL DEFAULT NULL AFTER available_at',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @error_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) jobs.failed_at
SELECT COUNT(*) INTO @failed_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jobs'
    AND COLUMN_NAME = 'failed_at';
SET @failed_sql = IF(@failed_exists = 0,
    'ALTER TABLE jobs ADD COLUMN failed_at TIMESTAMP NULL DEFAULT NULL AFTER last_error',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @failed_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5) jobs.completed_at
SELECT COUNT(*) INTO @completed_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jobs'
    AND COLUMN_NAME = 'completed_at';
SET @completed_sql = IF(@completed_exists = 0,
    'ALTER TABLE jobs ADD COLUMN completed_at TIMESTAMP NULL DEFAULT NULL AFTER failed_at',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @completed_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6) Índices: claim/varredura por (queue, status, available_at) e poda por completed_at
SELECT COUNT(*) INTO @idx_claim_exists FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jobs'
    AND INDEX_NAME = 'idx_queue_status_available';
SET @idx_claim_sql = IF(@idx_claim_exists = 0,
    'ALTER TABLE jobs ADD INDEX idx_queue_status_available (queue, status, available_at)',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @idx_claim_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @idx_prune_exists FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'jobs'
    AND INDEX_NAME = 'idx_status_completed';
SET @idx_prune_sql = IF(@idx_prune_exists = 0,
    'ALTER TABLE jobs ADD INDEX idx_status_completed (status, completed_at)',
    'SELECT 1 AS dummy'
);
PREPARE stmt FROM @idx_prune_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7) Backfill das linhas existentes.
-- Reexecutar é no-op: as duas condições deixam de bater depois da primeira passada,
-- e o restante já nasce com o DEFAULT 'pending'.
-- failed_at fica NULL nas falhas antigas de propósito: o momento real da falha nunca
-- foi registrado, e inventar um seria pior que a lacuna honesta.
UPDATE jobs SET status = 'failed'
    WHERE attempts >= max_attempts AND status = 'pending';

UPDATE jobs SET status = 'reserved', reserved_until = reserved_at
    WHERE reserved_at IS NOT NULL AND attempts < max_attempts AND status = 'pending';

SET FOREIGN_KEY_CHECKS = 1;
