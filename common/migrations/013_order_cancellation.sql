SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- Migration 013: Order cancellation (spec 020)
-- Drops the unused 'preparing'/'ready' status values (confirmed nowhere
-- referenced in the codebase) and adds an explicit 'cancelled' terminal
-- state, replacing the old hard-delete-as-cancellation behavior.
-- Safe to apply directly: a live check found only 'pending'/'done' rows
-- in use today, so MODIFY COLUMN needs no data backfill or guard.
-- ================================================================

ALTER TABLE orders MODIFY COLUMN status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending';

SET FOREIGN_KEY_CHECKS = 1;
