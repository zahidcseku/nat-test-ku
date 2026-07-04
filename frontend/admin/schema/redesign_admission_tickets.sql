-- redesign_admission_tickets.sql
--
-- Replaces the broken admission_tickets table (INT columns referencing
-- VARCHAR(36) / CHAR(36) UUIDs — every INSERT silently coerced to 0)
-- with a minimal stage-and-send schema.
--
-- One row per (xlsx_id, exam_date_id). reg_no links to
-- registration_sheet_numbers (assigned during the Registration Sheet
-- xlsx export) — its registration_id FK gives us email + full_name at
-- send time via JOIN. No denormalized name/dob columns.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/redesign_admission_tickets.sql
--
-- Existing admission_tickets data is unrecoverable (every row had
-- registration_id = 0 due to the type bug), so dropping is safe.

START TRANSACTION;

DROP TABLE IF EXISTS admission_tickets;

CREATE TABLE admission_tickets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    xlsx_id      VARCHAR(50)  NOT NULL COMMENT 'Admin-assigned ID from xlsx column 1; PDF is named <xlsx_id>.pdf',
    reg_no       VARCHAR(20)  NOT NULL COMMENT 'From xlsx column 2; resolves via registration_sheet_numbers',
    exam_date_id CHAR(36)     NOT NULL,
    file_path    VARCHAR(500) NOT NULL COMMENT 'Absolute filesystem path to the staged PDF',
    send_status  ENUM('staged','sent','failed') NOT NULL DEFAULT 'staged',
    emailed_at   TIMESTAMP    NULL,
    last_error   TEXT         NULL,
    created_by   INT          NOT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_xlsx_exam (xlsx_id, exam_date_id),
    INDEX idx_exam_date (exam_date_id),
    INDEX idx_reg_no (reg_no),
    INDEX idx_send_status (send_status),

    CONSTRAINT fk_at_exam FOREIGN KEY (exam_date_id)
        REFERENCES exam_dates(id) ON DELETE CASCADE,
    CONSTRAINT fk_at_creator FOREIGN KEY (created_by)
        REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Staged admission tickets: xlsx manifest + PDF zip, sent on admin action';

-- Fix email_log.registration_id (INT -> VARCHAR(36)) so email_type
-- = 'admission_ticket' rows can actually link to registrations.
-- Drop the existing FK first if present (defensive; schema.sql declared
-- one but production may or may not have it).
SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'email_log'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
-- MySQL doesn't allow dynamic FK drop in a script — leave a marker.
-- If you see "Cannot drop FK" errors on the ALTER below, run this
-- manually first:
--   ALTER TABLE email_log DROP FOREIGN KEY email_log_ibfk_1;  (or whatever the FK name is)

ALTER TABLE email_log
    MODIFY registration_id VARCHAR(36) NULL;

-- Widen email_type ENUM to cover the variants intake actually writes.
-- ALTER MODIFY on ENUM is idempotent if the values already match.
ALTER TABLE email_log
    MODIFY email_type ENUM(
        'confirmation', 'rejection', 'admission_ticket', 'resend',
        'submission_receipt', 'payment_confirmation'
    ) NOT NULL;

COMMIT;

-- Sanity check.
SELECT
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'admission_tickets'
         AND column_name = 'xlsx_id'
         AND data_type   = 'varchar') AS xlsx_id_ok,
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'email_log'
         AND column_name = 'registration_id'
         AND data_type   = 'varchar') AS email_log_regid_ok;
