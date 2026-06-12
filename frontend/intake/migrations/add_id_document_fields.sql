-- Applicants now declare their ID document type and number before uploading.
-- Columns are NULLable: rows registered before this feature stay NULL.
-- Run: mysql -u nattest_reg -p nattest_regs < add_id_document_fields.sql

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS id_document_type ENUM('passport', 'national_id') NULL
AFTER id_size_bytes;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS id_document_number VARCHAR(30) NULL
AFTER id_document_type;
