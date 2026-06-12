-- Add dedicated column for the bank's transaction reference from SSLCommerz IPN.
-- Previously the IPN handler overwrote sslcommerz_transaction_id (our lookup key)
-- with bank_tran_id; this column gives the bank reference its own home.
-- Run: mysql -u nattest_reg -p nattest_regs < add_bank_transaction_id.sql

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS sslcommerz_bank_transaction_id VARCHAR(100) NULL
AFTER sslcommerz_transaction_id;
