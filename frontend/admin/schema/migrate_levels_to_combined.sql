-- =====================================================================
-- Migrate exam-level labels to combined Q/N notation.
--   1Q -> 1Q/N1, 2Q -> 2Q/N2, 3Q -> 3Q/N3, 4Q -> 4Q/N4, 5Q -> 5Q/N5
-- Target DB: nattest_regs (MySQL)
--
-- The stored value is the combined string itself: the NAT-TEST Q level
-- and its JLPT N equivalent shown together (e.g. "1Q/N1").
--
-- !! BEFORE RUNNING:
--   1. Back up the database:
--        mysqldump -u nattest_reg -p nattest_regs > backup_before_combined_levels.sql
--   2. This script assumes the DB still has the ORIGINAL 1Q-5Q values.
--      If the earlier migrate_levels_to_N.sql was already run (DB now on
--      N1-N5), restore from backup first -- the REPLACE passes below
--      target 1Q-5Q tokens and would corrupt N1-N5 data.
--   3. Deploy the matching code in the same window. The code validates and
--      stores the combined "1Q/N1" tokens; until this runs, old rows will
--      not match validation/dropdowns.
--
-- Tables touched:
--   registrations.exam_level            VARCHAR (comma-separated, e.g. "1Q,3Q")
--   registration_sheet_numbers.level    ENUM('1Q'...'5Q')
--   exam_levels.level                   ENUM('1Q'...'5Q')   (junction table)
--
-- NOT touched:
--   registration_sheet_numbers.reg_no   stores only the numeric digit; the
--                                       digit is unchanged (1Q/N1 -> "1").
--   audit_log / email_log JSON          left as historical record.
--
-- Run ONCE. REPLACE is not idempotent (re-running would yield 1Q/N1/N1).
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1) registrations.exam_level (VARCHAR): token-safe chained REPLACE.
--    Sibling tokens 1Q..5Q do not overlap, and none of them appear inside
--    any combined result (1Q/N1 .. 5Q/N5) except as the intended prefix,
--    so each single-pass UPDATE is safe. Comma is the level separator, so
--    comma-splitting still works after migration ("1Q/N1,3Q/N3").
-- ---------------------------------------------------------------------
UPDATE registrations SET exam_level = REPLACE(exam_level, '1Q', '1Q/N1') WHERE exam_level LIKE '%1Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '2Q', '2Q/N2') WHERE exam_level LIKE '%2Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '3Q', '3Q/N3') WHERE exam_level LIKE '%3Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '4Q', '4Q/N4') WHERE exam_level LIKE '%4Q%';
UPDATE registrations SET exam_level = REPLACE(exam_level, '5Q', '5Q/N5') WHERE exam_level LIKE '%5Q%';

-- ---------------------------------------------------------------------
-- 2) registration_sheet_numbers.level (ENUM): widen -> remap -> narrow.
-- ---------------------------------------------------------------------
ALTER TABLE registration_sheet_numbers
    MODIFY level ENUM('1Q','2Q','3Q','4Q','5Q','1Q/N1','2Q/N2','3Q/N3','4Q/N4','5Q/N5') NOT NULL;

UPDATE registration_sheet_numbers SET level = '1Q/N1' WHERE level = '1Q';
UPDATE registration_sheet_numbers SET level = '2Q/N2' WHERE level = '2Q';
UPDATE registration_sheet_numbers SET level = '3Q/N3' WHERE level = '3Q';
UPDATE registration_sheet_numbers SET level = '4Q/N4' WHERE level = '4Q';
UPDATE registration_sheet_numbers SET level = '5Q/N5' WHERE level = '5Q';

ALTER TABLE registration_sheet_numbers
    MODIFY level ENUM('1Q/N1','2Q/N2','3Q/N3','4Q/N4','5Q/N5') NOT NULL;

-- ---------------------------------------------------------------------
-- 3) exam_levels.level (ENUM): widen -> remap -> narrow.
--    This is the junction table read via GROUP_CONCAT in get_schedule.php
--    and get_exam_dates.php. If your live DB names this column/table
--    differently, adjust below before running.
-- ---------------------------------------------------------------------
ALTER TABLE exam_levels
    MODIFY level ENUM('1Q','2Q','3Q','4Q','5Q','1Q/N1','2Q/N2','3Q/N3','4Q/N4','5Q/N5') NOT NULL;

UPDATE exam_levels SET level = '1Q/N1' WHERE level = '1Q';
UPDATE exam_levels SET level = '2Q/N2' WHERE level = '2Q';
UPDATE exam_levels SET level = '3Q/N3' WHERE level = '3Q';
UPDATE exam_levels SET level = '4Q/N4' WHERE level = '4Q';
UPDATE exam_levels SET level = '5Q/N5' WHERE level = '5Q';

ALTER TABLE exam_levels
    MODIFY level ENUM('1Q/N1','2Q/N2','3Q/N3','4Q/N4','5Q/N5') NOT NULL;

-- ---------------------------------------------------------------------
-- Sanity checks. Every count below must be 0 before COMMIT.
-- The regex matches a BARE 1Q-5Q token as a whole comma-separated piece,
-- so already-migrated values like "1Q/N1" are NOT flagged.
-- ---------------------------------------------------------------------
SELECT 'registrations still containing bare old codes (expect 0)' AS check_name,
       COUNT(*) AS cnt
FROM registrations
WHERE exam_level REGEXP '(^|,)(1Q|2Q|3Q|4Q|5Q)(,|$)';

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
