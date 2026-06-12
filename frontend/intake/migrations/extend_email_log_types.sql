-- Allow system-sent applicant emails in the admin email_log.
-- Run ONLY where the admin schema (email_log table) is installed:
--   mysql -u nattest_reg -p nattest_regs < extend_email_log_types.sql
-- The intake mailer degrades gracefully if this has not been run.

ALTER TABLE email_log
MODIFY email_type ENUM('confirmation', 'rejection', 'admission_ticket', 'resend',
                       'submission_receipt', 'payment_confirmation') NOT NULL;

-- System-sent emails have no admin user
ALTER TABLE email_log
MODIFY sent_by INT NULL;
