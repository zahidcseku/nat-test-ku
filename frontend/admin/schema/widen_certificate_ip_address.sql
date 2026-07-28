-- widen_certificate_ip_address.sql
--
-- certificate_requests.ip_address was created as VARCHAR(45) (sized for a
-- raw IPv6 string) but the code stores a SHA256 hash from hashIp() which
-- is 64 hex chars. Under MySQL strict mode the INSERT fails with
-- "Data too long for column 'ip_address'". Widen to match
-- registrations.ip_hash (VARCHAR(64)).
--
-- Apply with:
--   mysql -u nattest_reg -p nattest_regs < schema/widen_certificate_ip_address.sql
--
-- Idempotent: MODIFY is safe to re-run.

ALTER TABLE certificate_requests
    MODIFY COLUMN ip_address VARCHAR(64) NULL
    COMMENT 'SHA256 hash of client IP (mirrors registrations.ip_hash)';

-- Sanity check.
SELECT column_name, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'certificate_requests'
  AND column_name = 'ip_address';
