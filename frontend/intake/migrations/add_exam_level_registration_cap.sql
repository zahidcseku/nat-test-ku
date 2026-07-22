-- Add per-(exam_date, level) registration cap to exam_levels.
-- NULL  = unlimited (default — preserves existing behaviour for rows in place).
-- 0     = effectively closed (no new paid registrations allowed).
-- >= 1  = max paid registrations allowed for this (exam_date, level).
--
-- Counts are taken from registrations.payment_status = 'paid' (online
-- successful payments only, per project decision). Offline registrations
-- and pending-online payments do NOT reserve a seat.

ALTER TABLE exam_levels
    ADD COLUMN registration_cap INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Max paid registrations allowed for this (exam_date, level). NULL = unlimited.';
