-- Add total_amount column after exam_level
ALTER TABLE `registrations`
ADD COLUMN `total_amount` INT NOT NULL DEFAULT 4000
COMMENT 'Total application fee in BDT (4000 × number of levels selected)'
AFTER `exam_level`;

-- Add index
CREATE INDEX `idx_total_amount` ON `registrations`(`total_amount`);

-- ============================================================================
-- VERIFICATION
-- ============================================================================

-- Verify migration: DESC registrations;

-- ============================================================================
-- ROLLBACK
-- ============================================================================

-- DROP INDEX `idx_total_amount` ON `registrations`;
-- ALTER TABLE `registrations` DROP COLUMN `total_amount`;
