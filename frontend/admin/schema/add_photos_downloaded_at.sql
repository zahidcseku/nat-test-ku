-- add_photos_downloaded_at.sql
-- Tracks which (registration, level, period) mappings have already had
-- their photo included in a Download Photos zip. NULL = pending, non-NULL
-- = already downloaded (and is therefore excluded from subsequent zips
-- until reset).
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/add_photos_downloaded_at.sql

ALTER TABLE registration_sheet_numbers
    ADD COLUMN photos_downloaded_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'When this row\'s photo was last included in a Download Photos zip; NULL = pending'
    AFTER reg_no;

-- Optional index for the common "what's pending this period" query.
CREATE INDEX idx_photos_pending ON registration_sheet_numbers (year, month, photos_downloaded_at);
