-- SSLCommerz Payment Gateway Integration Migration
-- Run: mysql -u nattest_reg -p nattest_regs < add_payment_gateway_fields.sql
--
-- IMPORTANT: This migration assumes the existing 'total_amount' column (INT) stores base fees.
-- The new 'total_amount_paid' column (DECIMAL) will store total amount including transaction fees.
-- Existing 'total_amount' column remains unchanged for backward compatibility.

-- Add payment status tracking
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid', 'paid', 'failed', 'refunded')
DEFAULT 'unpaid'
AFTER payment_method;

-- Add SSLCommerz transaction references
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS sslcommerz_transaction_id VARCHAR(100) NULL
AFTER payment_status;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS sslcommerz_session_id VARCHAR(100) NULL
AFTER sslcommerz_transaction_id;

-- Add payment amount breakdown
-- Note: These columns complement the existing 'total_amount' column
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS base_amount DECIMAL(10,2) NULL
AFTER sslcommerz_session_id;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS transaction_fee DECIMAL(10,2) NULL
AFTER base_amount;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS total_amount_paid DECIMAL(10,2) NULL
AFTER transaction_fee;

-- Add payment method detail
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS payment_method_detail ENUM('card', 'bkash', 'nagad', 'rocket', 'bank', 'other') NULL
AFTER total_amount_paid;

-- Add payment timestamp
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS payment_time DATETIME NULL
AFTER payment_method_detail;

-- Add IPN tracking
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS payment_ipn_received BOOLEAN DEFAULT FALSE
AFTER payment_time;

-- Add retry functionality fields
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS payment_retry_token VARCHAR(50) NULL
AFTER payment_ipn_received;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS payment_retry_expires DATETIME NULL
AFTER payment_retry_token;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS payment_retry_count INT DEFAULT 0
AFTER payment_retry_expires;

-- Create indexes for payment queries
CREATE INDEX IF NOT EXISTS idx_payment_status ON registrations(payment_status);
CREATE INDEX IF NOT EXISTS idx_sslcommerz_transaction_id ON registrations(sslcommerz_transaction_id);
CREATE INDEX IF NOT EXISTS idx_payment_ipn_received ON registrations(payment_ipn_received);
CREATE INDEX IF NOT EXISTS idx_payment_status_created_at ON registrations(payment_status, created_at);
CREATE INDEX IF NOT EXISTS idx_payment_retry_token ON registrations(payment_retry_token);