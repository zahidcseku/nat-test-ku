-- add_exam_guide.sql
--
-- Per-exam-date "exam guide" PDF that is attached to every admission
-- ticket email sent for that exam date.
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/add_exam_guide.sql

ALTER TABLE exam_dates
    ADD COLUMN guide_pdf_path VARCHAR(500) NULL
    COMMENT 'Optional PDF attached to every admission-ticket email for this exam date';
