-- NAT-TEST Intake Service Database Schema
-- MySQL database initialization script

CREATE TABLE IF NOT EXISTS registrations (
    id VARCHAR(36) PRIMARY KEY COMMENT 'UUID v4',
    full_name VARCHAR(255) NOT NULL COMMENT 'Full name of applicant',
    email VARCHAR(255) NOT NULL COMMENT 'Email address',
    mobile VARCHAR(20) NOT NULL COMMENT 'Mobile phone number',
    address TEXT NOT NULL COMMENT 'Residential address',
    dob DATE NOT NULL COMMENT 'Date of birth',
    gender ENUM('male', 'female', 'other') NOT NULL COMMENT 'Gender',
    nationality VARCHAR(100) NOT NULL COMMENT 'Nationality',
    payment_method ENUM('online', 'offline') NOT NULL COMMENT 'Payment method',
    exam_level VARCHAR(50) NOT NULL COMMENT 'Exam level (e.g., N5, N4, N3, N2, N1)',
    test_date DATE NOT NULL COMMENT 'Preferred test date',
    photo_filename VARCHAR(255) NOT NULL COMMENT 'Photo file name',
    photo_storage_path VARCHAR(500) NOT NULL COMMENT 'Photo file storage path',
    photo_size_bytes INT NOT NULL COMMENT 'Photo file size in bytes',
    id_filename VARCHAR(255) NOT NULL COMMENT 'ID document file name',
    id_storage_path VARCHAR(500) NOT NULL COMMENT 'ID document storage path',
    id_size_bytes INT NOT NULL COMMENT 'ID document file size in bytes',
    payment_receipt_filename VARCHAR(255) NULL COMMENT 'Payment receipt file name',
    payment_receipt_storage_path VARCHAR(500) NULL COMMENT 'Payment receipt storage path',
    payment_receipt_size_bytes INT NULL COMMENT 'Payment receipt file size in bytes',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Submission timestamp',
    ip_hash VARCHAR(64) NOT NULL COMMENT 'SHA256 hash of client IP',
    user_agent TEXT NULL COMMENT 'Client user agent',
    honeypot_tripped TINYINT(1) DEFAULT 0 COMMENT 'Whether honeypot was triggered',
    honeypot_value VARCHAR(255) NULL COMMENT 'Honeypot field value if filled',
    approved TINYINT(1) DEFAULT 0 COMMENT 'Approval status (0=pending, 1=approved by admin)',
    approved_at TIMESTAMP NULL COMMENT 'Approval timestamp',
    approved_by VARCHAR(100) NULL COMMENT 'Admin who approved the application',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',

    INDEX idx_email (email),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_ip_hash (ip_hash),
    INDEX idx_approved (approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registration applications for NAT-TEST';
