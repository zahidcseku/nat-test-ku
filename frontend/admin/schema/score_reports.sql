-- score_reports.sql
--
-- Stage-and-send table for per-candidate score-report PDFs. Mirrors
-- admission_tickets in shape (same columns, same reg_no -> registration
-- JOIN path) so the upload/review/send workflow is identical.
--
-- Differences from admission_tickets:
--   * Separate table so score rows never collide with ticket rows for
--     the same (xlsx_id, exam_date_id).
--   * No guide column — scores have no exam-guide attachment.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/score_reports.sql
--
-- Idempotent: DROP IF EXISTS first, safe to re-run.

START TRANSACTION;

DROP TABLE IF EXISTS score_reports;

CREATE TABLE score_reports (
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

    CONSTRAINT fk_sr_exam FOREIGN KEY (exam_date_id)
        REFERENCES exam_dates(id) ON DELETE CASCADE,
    CONSTRAINT fk_sr_creator FOREIGN KEY (created_by)
        REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Staged score reports: xlsx manifest + PDF zip, sent on admin action';

-- Widen email_log.email_type to include 'score_report'. ALTER MODIFY on
-- ENUM is idempotent if the values already match.
ALTER TABLE email_log
    MODIFY email_type ENUM(
        'confirmation', 'rejection', 'admission_ticket', 'resend',
        'submission_receipt', 'payment_confirmation', 'score_report'
    ) NOT NULL;

COMMIT;

-- Sanity check.
SELECT
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'score_reports'
         AND column_name = 'xlsx_id'
         AND data_type   = 'varchar') AS xlsx_id_ok,
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'email_log'
         AND column_name = 'email_type'
         AND data_type   = 'enum') AS email_log_type_ok;
