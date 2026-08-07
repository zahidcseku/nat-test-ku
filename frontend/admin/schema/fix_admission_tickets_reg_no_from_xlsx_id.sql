-- fix_admission_tickets_reg_no_from_xlsx_id.sql
--
-- One-time data fix: prefix existing admission_tickets.reg_no and
-- score_reports.reg_no with YYYYMM (derived from the exam_date of each
-- row) so the stored value is globally unique.
--
-- Background: the same reg_no (e.g. 47650019) is reused every month for
-- a different applicant. Storing it without a period prefix means rows
-- from different exam periods can be confused at lookup time. New uploads
-- prefix the value at staging time (see lib/ticket-staging.php); this
-- script brings existing rows in line with the new format.
--
-- Example: 47650019 for an Aug 2026 exam -> 20260847650019.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/fix_admission_tickets_reg_no_from_xlsx_id.sql
--
-- Safe to re-run: only rows whose reg_no is still in the unprefixed
-- form (length <= 8) are touched.

-- =====================================================================
-- 1. Preview: how many rows will change, and a sample of the diff.
-- =====================================================================
SELECT
    'admission_tickets' AS tbl,
    COUNT(*)            AS rows_that_will_change
FROM admission_tickets at
JOIN exam_dates ed ON ed.id = at.exam_date_id
WHERE LENGTH(at.reg_no) <= 8
UNION ALL
SELECT
    'score_reports'     AS tbl,
    COUNT(*)            AS rows_that_will_change
FROM score_reports sr
JOIN exam_dates ed ON ed.id = sr.exam_date_id
WHERE LENGTH(sr.reg_no) <= 8;

-- Sample diff (first 20 admission_tickets rows that will change).
SELECT
    at.id,
    at.xlsx_id,
    at.reg_no                                          AS old_reg_no,
    CONCAT(DATE_FORMAT(ed.exam_date, '%Y%m'), at.reg_no) AS new_reg_no
FROM admission_tickets at
JOIN exam_dates ed ON ed.id = at.exam_date_id
WHERE LENGTH(at.reg_no) <= 8
ORDER BY at.id
LIMIT 20;

-- =====================================================================
-- 2. Apply: prefix reg_no with YYYYMM (from exam_dates.exam_date).
--    Only rows whose reg_no is still <= 8 chars get touched, so rows
--    already prefixed (length 14) are left alone on re-run.
-- =====================================================================
UPDATE admission_tickets at
JOIN exam_dates ed ON ed.id = at.exam_date_id
SET at.reg_no = CONCAT(DATE_FORMAT(ed.exam_date, '%Y%m'), at.reg_no)
WHERE LENGTH(at.reg_no) <= 8;

UPDATE score_reports sr
JOIN exam_dates ed ON ed.id = sr.exam_date_id
SET sr.reg_no = CONCAT(DATE_FORMAT(ed.exam_date, '%Y%m'), sr.reg_no)
WHERE LENGTH(sr.reg_no) <= 8;

-- =====================================================================
-- 3. Verify: any remaining unprefixed rows indicate an orphan
--    exam_date_id (no matching exam_dates row) — worth eyeballing.
-- =====================================================================
SELECT 'admission_tickets' AS tbl, id, exam_date_id, reg_no FROM admission_tickets WHERE LENGTH(reg_no) <= 8
UNION ALL
SELECT 'score_reports'     AS tbl, id, exam_date_id, reg_no FROM score_reports     WHERE LENGTH(reg_no) <= 8;
