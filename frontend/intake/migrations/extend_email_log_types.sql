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

-- The original admin schema declared registration_id INT, but registration
-- ids are CHAR(36) UUIDs — an INT column cannot reference them. Widen it.
-- If SHOW CREATE TABLE email_log shows a FOREIGN KEY on registration_id,
-- drop that constraint first (its auto-generated name appears in the same
-- output): ALTER TABLE email_log DROP FOREIGN KEY <name>;
ALTER TABLE email_log
MODIFY registration_id VARCHAR(36) NULL;
