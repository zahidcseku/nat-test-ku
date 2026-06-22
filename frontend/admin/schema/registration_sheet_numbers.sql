-- registration_sheet_numbers
-- Maps each (registration_id, level) to its assigned Reg. Number in the
-- monthly Registration Sheet (.xlsx) sent to Japan HQ.
--
-- One row per (registration_id, level, year, month). A registration with
-- multiple levels (e.g. "1Q,2Q,3Q") produces one row per level.
--
-- Reg. No format (matches the formula in the level sheets):
--   site_code + level_digit + zero-padded sheet_row
-- e.g. site 476, level 1Q, sheet_row 1 -> "47610001"
--
-- Once written, a mapping is NEVER changed: re-exports of the same period
-- keep every existing assignment intact and only hand out fresh rows to
-- newly approved applicants.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/registration_sheet_numbers.sql

CREATE TABLE IF NOT EXISTS registration_sheet_numbers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    registration_id VARCHAR(36)    NOT NULL,
    level           ENUM('1Q','2Q','3Q','4Q','5Q') NOT NULL,
    year            SMALLINT       NOT NULL,
    month           TINYINT        NOT NULL,
    -- 1-indexed position within the level sheet. Data starts at sheet row 4,
    -- so sheet_row=1 corresponds to cells C4/D4.
    sheet_row       INT            NOT NULL,
    -- site_code + level_digit + zero-padded sheet_row (e.g. "47610001").
    reg_no          VARCHAR(20)    NOT NULL,
    assigned_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Natural key: one reg_no per applicant per level per period.
    UNIQUE KEY uniq_reg_level_period (registration_id, level, year, month),
    -- Safety net: no two applicants share the same reg_no in one period.
    UNIQUE KEY uniq_regno_period     (reg_no, year, month),

    INDEX idx_period        (year, month),
    INDEX idx_level_period  (level, year, month),

    CONSTRAINT fk_rsn_reg FOREIGN KEY (registration_id)
        REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reg-no assignments for monthly Registration Sheet exports';
