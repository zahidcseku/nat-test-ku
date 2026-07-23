-- email_templates.sql
--
-- Stores editable subject + body for every email the system sends.
-- Seeded from lib/email-template-defaults.php on first visit to
-- /admin/pages/email-templates.php (any missing template_key row is
-- INSERTed from the PHP defaults). This script just creates the table;
-- no data seed is needed here.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/email_templates.sql
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS email_templates (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    template_key        VARCHAR(50)  NOT NULL COMMENT 'Stable key, e.g. confirmation, admission_ticket, submission_receipt_online',
    name                VARCHAR(100) NOT NULL COMMENT 'Human-friendly label shown in the admin UI',
    description         TEXT         NULL     COMMENT 'What this template is for, shown under the name',
    subject             VARCHAR(255) NOT NULL,
    body_html           MEDIUMTEXT   NOT NULL,
    available_variables JSON         NULL     COMMENT 'Array of {key,label,example} for the editor UI',
    is_system           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'System templates can be edited but not deleted',
    updated_by          INT          NULL,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_template_key (template_key),
    INDEX idx_template_key (template_key),

    CONSTRAINT fk_et_updater FOREIGN KEY (updated_by)
        REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Editable email templates; defaults seeded from lib/email-template-defaults.php';

-- Sanity check.
SELECT
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'email_templates'
         AND column_name = 'template_key') AS key_col_ok,
    (SELECT COUNT(*) FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name = 'email_templates') AS table_ok;
