-- Migration: Add approval fields to registrations table
-- Run this script if you already created the database before this feature was added

-- Add approved field
ALTER TABLE registrations
ADD COLUMN approved TINYINT(1) DEFAULT 0 COMMENT 'Approval status (0=pending, 1=approved by admin)';

-- Add approved_at field
ALTER TABLE registrations
ADD COLUMN approved_at TIMESTAMP NULL COMMENT 'Approval timestamp';

-- Add approved_by field
ALTER TABLE registrations
ADD COLUMN approved_by VARCHAR(100) NULL COMMENT 'Admin who approved the application';

-- Add index for approved field
ALTER TABLE registrations
ADD INDEX idx_approved (approved);

-- Set all existing records to pending approval (should already be 0, but ensuring consistency)
UPDATE registrations SET approved = 0 WHERE approved IS NULL;
