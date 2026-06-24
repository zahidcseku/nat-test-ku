-- =====================================================================
-- Migrate exam-level labels: 1Q->N1, 2Q->N2, 3Q->N3, 4Q->N4, 5Q->N5
-- Target DB: nattest_regs (MySQL)
--
-- !! BEFORE RUNNING:
--   1. Back up the database:
--        mysqldump -u nattest_reg -p nattest_regs > backup_before_level_rename.sql
--   2. Deploy the matching code changes in the same maintenance window.
--      The code expects N1-N5 after this runs; until it is applied, old
--      1Q-5Q rows will not match the new validation/dropdowns.
--
-- Tables touched:
--   registrations.exam_level            VARCHAR (comma-separated, e.g. "1Q,3Q")
--   registration_sheet_numbers.level    ENUM('1Q'...'5Q')
--   exam_levels.level                   ENUM('1Q'...'5Q')   (junction table)
--
-- NOT touched:
--   registration_sheet_numbers.reg_no   already stores the numeric digit only,
--                                       so issued registration numbers are
--                                       unaffected by this rename.
--   audit_log / email_log JSON          left as historical record.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1) registrations.exam_level (VARCHAR): token-safe chained REPLACE.
--    Tokens are distinct 2-char strings with no substring overlap, so
--    order does not matter. Runs each as its own UPDATE.
-- ---------------------------------------------------------------------
UPDATE registrations SET exam_level = REPLACE(exam_level, '1Q', 'N1') WHERE exam_level LIKE '%1Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '2Q', 'N2') WHERE exam_level LIKE '%2Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '3Q', 'N3') WHERE exam_level LIKE '%3Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '4Q', 'N4') WHERE exam_level LIKE '%4Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '5Q', 'N5') WHERE exam_level LIKE '%5Q%';

-- ---------------------------------------------------------------------
-- 2) registration_sheet_numbers.level (ENUM): widen -> remap -> narrow.
-- ---------------------------------------------------------------------
ALTER TABLE registration_sheet_numbers
    MODIFY level ENUM('1Q','2Q','3Q','4Q','5Q','N1','N2','N3','N4','N5') NOT NULL;

UPDATE registration_sheet_numbers SET level = 'N1' WHERE level = '1Q';
UPDATE registration_sheet_numbers SET level = 'N2' WHERE level = '2Q';
UPDATE registration_sheet_numbers SET level = 'N3' WHERE level = '3Q';
UPDATE registration_sheet_numbers SET level = 'N4' WHERE level = '4Q';
UPDATE registration_sheet_numbers SET level = 'N5' WHERE level = '5Q';

ALTER TABLE registration_sheet_numbers
    MODIFY level ENUM('N1','N2','N3','N4','N5') NOT NULL;

-- ---------------------------------------------------------------------
-- 3) exam_levels.level (ENUM): widen -> remap -> narrow.
--    NOTE: this is the junction table queried via GROUP_CONCAT in
--    get_schedule.php. If your live DB names this column/table
--    differently, adjust below before running.
-- ---------------------------------------------------------------------
ALTER TABLE exam_levels
    MODIFY level ENUM('1Q','2Q','3Q','4Q','5Q','N1','N2','N3','N4','N5') NOT NULL;

UPDATE exam_levels SET level = 'N1' WHERE level = '1Q';
UPDATE exam_levels SET level = 'N2' WHERE level = '2Q';
UPDATE exam_levels SET level = 'N3' WHERE level = '3Q';
UPDATE exam_levels SET level = 'N4' WHERE level = '4Q';
UPDATE exam_levels SET level = 'N5' WHERE level = '5Q';

ALTER TABLE exam_levels
    MODIFY level ENUM('N1','N2','N3','N4','N5') NOT NULL;

-- ---------------------------------------------------------------------
-- Sanity checks (run manually, compare counts before/after).
-- No 1Q-5Q values should remain anywhere.
-- ---------------------------------------------------------------------
SELECT 'registrations still containing old codes (expect 0)' AS check_name,
       COUNT(*) AS cnt
FROM registrations
WHERE exam_level REGEXP '1Q|2Q|3Q|4Q|5Q';

SELECT 'registration_sheet_numbers old codes (expect 0)' AS check_name,
       COUNT(*) AS cnt
FROM registration_sheet_numbers
WHERE level IN ('1Q','2Q','3Q','4Q','5Q');

SELECT 'exam_levels old codes (expect 0)' AS check_name,
       COUNT(*) AS cnt
FROM exam_levels
WHERE level IN ('1Q','2Q','3Q','4Q','5Q');

-- If all checks show 0, COMMIT; otherwise ROLLBACK and investigate.
COMMIT;
