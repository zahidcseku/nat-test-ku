-- SSLCommerz Payment Gateway Integration Migration
-- Run: mysql -u nattest_reg -p nattest_regs < add_payment_gateway_fields.sql

-- Add payment status tracking
ALTER TABLE registrations
ADD COLUMN payment_status ENUM('unpaid', 'paid', 'failed', 'refunded')
DEFAULT 'unpaid'
AFTER payment_method;

-- Add SSLCommerz transaction references
ALTER TABLE registrations
ADD COLUMN sslcommerz_transaction_id VARCHAR(100) NULL
AFTER payment_status;

ALTER TABLE registrations
ADD COLUMN sslcommerz_session_id VARCHAR(100) NULL
AFTER sslcommerz_transaction_id;

-- Add payment amount breakdown
ALTER TABLE registrations
ADD COLUMN base_amount DECIMAL(10,2) NULL
AFTER sslcommerz_session_id;

ALTER TABLE registrations
ADD COLUMN transaction_fee DECIMAL(10,2) NULL
AFTER base_amount;

ALTER TABLE registrations
ADD COLUMN total_amount_paid DECIMAL(10,2) NULL
AFTER transaction_fee;

-- Add payment method detail
ALTER TABLE registrations
ADD COLUMN payment_method_detail ENUM('card', 'bkash', 'nagad', 'rocket', 'bank', 'other') NULL
AFTER total_amount_paid;

-- Add payment timestamp
ALTER TABLE registrations
ADD COLUMN payment_time DATETIME NULL
AFTER payment_method_detail;

-- Add IPN tracking
ALTER TABLE registrations
ADD COLUMN payment_ipn_received BOOLEAN DEFAULT FALSE
AFTER payment_time;

-- Add retry functionality fields
ALTER TABLE registrations
ADD COLUMN payment_retry_token VARCHAR(50) NULL
AFTER payment_ipn_received;

ALTER TABLE registrations
ADD COLUMN payment_retry_expires DATETIME NULL
AFTER payment_retry_token;

ALTER TABLE registrations
ADD COLUMN payment_retry_count INT DEFAULT 0
AFTER payment_retry_expires;

-- Create index for payment status queries
CREATE INDEX idx_payment_status ON registrations(payment_status);
CREATE INDEX idx_payment_retry_token ON registrations(payment_retry_token);