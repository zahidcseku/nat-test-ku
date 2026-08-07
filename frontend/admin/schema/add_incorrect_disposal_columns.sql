-- add_incorrect_disposal_columns.sql
--
-- Super admins need a manual way to flag a sent admission ticket as an
-- incorrect disposal (wrong recipient, wrong PDF, etc.) and unstage it
-- for review / re-send. This migration adds two columns to track that
-- marker on admission_tickets.
--
-- incorrect_disposal_at: NULL by default. Set to NOW() when a super
--   admin marks the row. Cleared again on the next successful send
--   (see lib/ticket-staging.php sendTickets()).
-- incorrect_disposal_by: NULL by default. The admin_users.id of the
--   super admin who marked it.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/add_incorrect_disposal_columns.sql
--
-- Safe to re-run: each step checks for the column's existence first.

START TRANSACTION;

SET @has_at = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'admission_tickets'
      AND column_name = 'incorrect_disposal_at');
SET @sql = IF(@has_at = 0,
    'ALTER TABLE admission_tickets ADD COLUMN incorrect_disposal_at TIMESTAMP NULL DEFAULT NULL COMMENT ''Set when a super admin marks a sent ticket as an incorrect disposal; cleared on next successful send''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_by = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'admission_tickets'
      AND column_name = 'incorrect_disposal_by');
SET @sql = IF(@has_by = 0,
    'ALTER TABLE admission_tickets ADD COLUMN incorrect_disposal_by INT NULL DEFAULT NULL COMMENT ''admin_users.id of the super admin who marked this disposal incorrect''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- Sanity check.
SELECT
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'admission_tickets'
         AND column_name = 'incorrect_disposal_at') AS has_at,
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'admission_tickets'
         AND column_name = 'incorrect_disposal_by') AS has_by;
