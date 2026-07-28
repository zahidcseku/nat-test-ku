-- migrate_certificate_uses_xlsx_id.sql
--
-- The public certificate-request form should accept the examinee-facing
-- "Examinee ID" (the 14-digit number admins upload as xlsx column 1,
-- e.g. 26070047650100) — NOT the internal reg_no. This migration
-- swaps the certificate_requests.reg_no column for xlsx_id.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/migrate_certificate_uses_xlsx_id.sql
--
-- Safe to re-run: each statement checks for the current shape first.

START TRANSACTION;

-- Drop the old reg_no column (and its index) if it still exists.
SET @has_reg_no = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'certificate_requests'
      AND column_name = 'reg_no');
SET @sql = IF(@has_reg_no > 0,
    'ALTER TABLE certificate_requests DROP COLUMN reg_no',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add the new xlsx_id column if missing.
SET @has_xlsx_id = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'certificate_requests'
      AND column_name = 'xlsx_id');
SET @sql = IF(@has_xlsx_id = 0,
    'ALTER TABLE certificate_requests ADD COLUMN xlsx_id VARCHAR(50) NOT NULL COMMENT ''Examinee ID (xlsx column 1), e.g. 26070047650100'' AFTER exam_date_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for lookups (ignore if it already exists under this name).
SET @has_idx = (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'certificate_requests'
      AND index_name = 'idx_cr_xlsx_id');
SET @sql = IF(@has_idx = 0,
    'ALTER TABLE certificate_requests ADD INDEX idx_cr_xlsx_id (xlsx_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- Sanity check.
SELECT
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'certificate_requests'
         AND column_name = 'xlsx_id') AS xlsx_id_ok,
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'certificate_requests'
         AND column_name = 'reg_no') AS reg_no_remaining;
