-- add_broadcast_email_type.sql
--
-- Adds the 'broadcast' email_type so the admin Broadcast Email feature
-- can log one row per recipient via the existing sendEmail() helper.
--
-- Run manually (no automated migration runner in this project):
--   mysql -u nattest_reg -p nattest_regs < schema/add_broadcast_email_type.sql
--
-- This is the authoritative current set of email_type values (verified
-- against every sendEmail()/sendRegistrationEmail() call site):
--   admin:    confirmation, rejection, admission_ticket, resend,
--             score_report, certificate_posted
--   intake:   submission_receipt, payment_confirmation, certificate_requested
-- ALTER MODIFY on an ENUM replaces the allowed set, so the list below
-- must include every value currently in use — adding 'broadcast' on top.
-- Re-running is safe: MODIFY to the same set is a no-op.

ALTER TABLE email_log
    MODIFY email_type ENUM(
        'confirmation', 'rejection', 'admission_ticket', 'resend',
        'submission_receipt', 'payment_confirmation', 'score_report',
        'certificate_requested', 'certificate_posted',
        'broadcast'
    ) NOT NULL;
