-- broadcast_jobs.sql
--
-- Persistent job queue for admin broadcast emails. Snapshots the recipient
-- list at confirm time so a resumed/retried broadcast never re-emails
-- anyone already sent, and so mid-batch PHP/Apache deaths lose at most one
-- in-flight recipient. Mirrors the score_reports stage-and-send pattern.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/broadcast_jobs.sql
--
-- Idempotent in the DROP-IF-EXISTS sense (same caveat as score_reports):
-- re-running RESETS broadcast send history. Apply once; do not re-run on
-- production after broadcasts exist.

START TRANSACTION;

DROP TABLE IF EXISTS broadcast_recipients;
DROP TABLE IF EXISTS broadcasts;

CREATE TABLE broadcasts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    exam_date_id CHAR(36)     NOT NULL,
    exam_date    DATE         NOT NULL COMMENT 'Snapshot of exam_dates.exam_date for display; survives later edits',
    subject      VARCHAR(255) NOT NULL,
    body         MEDIUMTEXT   NOT NULL,
    created_by   INT          NOT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at  TIMESTAMP    NULL COMMENT 'NULL = never completed (stopped early / resumable)',

    INDEX idx_exam_date (exam_date_id),
    INDEX idx_created (created_at),

    CONSTRAINT fk_b_exam FOREIGN KEY (exam_date_id)
        REFERENCES exam_dates(id) ON DELETE CASCADE,
    CONSTRAINT fk_b_creator FOREIGN KEY (created_by)
        REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Broadcast email jobs: one row per confirmed send';

CREATE TABLE broadcast_recipients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    broadcast_id    INT          NOT NULL,
    registration_id VARCHAR(36)  NULL COMMENT 'Snapshot; deliberately NO FK — registration may be deleted, send history must survive',
    email           VARCHAR(255) NOT NULL COMMENT 'Matches registrations.email width',
    full_name       VARCHAR(255) NOT NULL,
    status          ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts        INT          NOT NULL DEFAULT 0,
    last_error      TEXT         NULL,
    sent_at         TIMESTAMP    NULL,

    UNIQUE KEY uniq_bcast_email (broadcast_id, email),
    INDEX idx_bcast_status (broadcast_id, status),

    CONSTRAINT fk_br_bcast FOREIGN KEY (broadcast_id)
        REFERENCES broadcasts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-recipient send state for a broadcast job; UNIQUE key is the dedup backstop';

COMMIT;

-- Sanity check: both values should be 2 / present after applying.
SELECT
    (SELECT COUNT(*) FROM information_schema.tables
       WHERE table_schema = DATABASE()
         AND table_name IN ('broadcasts','broadcast_recipients')) AS tables_ok,
    (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = 'broadcast_recipients'
         AND column_name = 'status' AND data_type = 'enum') AS status_enum_ok;
