-- remediate_admission_tickets_period_bug.sql
--
-- Recovery script for the 2026-08-06 incident where Send All on the
-- admission-tickets page emailed tickets to the WRONG recipients because
-- sendTickets() JOINed registration_sheet_numbers on reg_no alone, with
-- no (year, month) period filter. The same reg_no reappears every month
-- for a different applicant; ORDER BY rsn.id ASC LIMIT 1 picked the
-- oldest match, so emails went to prior months' registrants.
--
-- The code fix is already applied (lib/ticket-staging.php). This script
-- handles the data side:
--   1. List every admission_ticket email sent during the incident window
--      so the team can apologise / notify the unintended recipients.
--   2. Reset the affected exam's tickets back to 'staged' so they can be
--      re-sent correctly from the admin UI.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/remediate_admission_tickets_period_bug.sql
--
-- Edit the two @ variables below before running. Safe to re-run.

-- =====================================================================
-- EDIT THESE TWO VALUES
-- =====================================================================
-- The exam_date_id shown in the admin URL when the bug fired.
SET @incident_exam_date_id := '1a918e90-6c45-4770-8c0d-a486b8222707';
-- Earliest sent_at to treat as part of the incident. Set to a bit before
-- the first wrong send. MySQL TIMESTAMP format: 'YYYY-MM-DD HH:MM:SS'.
SET @incident_since := '2026-08-06 00:00:00';
-- =====================================================================

-- 1. Who received admission-ticket emails during the incident?
--    Cross-reference recipient_email against the CORRECT recipient for
--    each ticket (after the period-filter fix) so the team can see which
--    emails went to the wrong person.
SELECT
    el.id                                            AS email_log_id,
    el.sent_at,
    el.recipient_email                               AS actually_sent_to,
    at.xlsx_id,
    at.reg_no,
    correct_r.full_name                              AS should_have_gone_to_name,
    correct_r.email                                  AS should_have_gone_to_email,
    CASE
        WHEN correct_r.email = el.recipient_email THEN 'OK'
        ELSE 'WRONG RECIPIENT'
    END                                              AS verdict
FROM email_log el
JOIN admission_tickets at ON at.reg_no = el.recipient_email COLLATE utf8mb4_unicode_ci
    OR at.xlsx_id = el.subject  -- fallback join if reg_no matches elsewhere
LEFT JOIN exam_dates ed ON ed.id = at.exam_date_id
LEFT JOIN registration_sheet_numbers rsn
    ON rsn.reg_no = at.reg_no
    AND rsn.year  = YEAR(ed.exam_date)
    AND rsn.month = MONTH(ed.exam_date)
LEFT JOIN registrations correct_r ON correct_r.id = rsn.registration_id
WHERE el.email_type = 'admission_ticket'
  AND el.sent_at >= @incident_since
  AND at.exam_date_id = @incident_exam_date_id
ORDER BY el.sent_at DESC;

-- Simpler fallback list (every admission_ticket email in the window) in
-- case the JOIN above is too strict for your data shape.
SELECT
    el.id, el.sent_at, el.recipient_email, el.subject, el.status, el.error_message
FROM email_log el
WHERE el.email_type = 'admission_ticket'
  AND el.sent_at >= @incident_since
ORDER BY el.sent_at DESC;

-- 2. Reset the affected exam's tickets back to 'staged' so they can be
--    re-sent correctly. The PDFs and xlsx_id/reg_no on disk are fine —
--    only send_status needs to be reset. Run AFTER deploying the code
--    fix, then use the admin UI's Send Selected / Send All to re-send.
--
--    Commented out by default — uncomment to apply. Review the row count
--    reported by the SELECT first.
SELECT
    at.id, at.xlsx_id, at.reg_no, at.send_status, at.emailed_at
FROM admission_tickets at
WHERE at.exam_date_id = @incident_exam_date_id
ORDER BY at.id;

-- UPDATE admission_tickets
--     SET send_status = 'staged',
--         emailed_at  = NULL,
--         last_error  = NULL
--     WHERE exam_date_id = @incident_exam_date_id;
