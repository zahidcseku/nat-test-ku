# SSLCommerz Payment Gateway Deployment Guide

**Status:** Ready for Production Deployment  
**Prerequisites:** SSLCommerz merchant account obtained  
**Deployment Type:** Major upgrade with breaking changes  
**Estimated Time:** 2-3 hours (including testing)

---

## 🎯 **Deployment Overview**

This will deploy your SSLCommerz payment gateway integration, including:
- Online payment processing via SSLCommerz
- Payment calculation and display
- Payment retry functionality
- Admin payment management dashboard
- IPN webhook handling

**⚠️ IMPORTANT:** This deployment requires database schema changes and core function modifications.

---

## 📋 **Pre-Deployment Checklist**

### **Required Items:**
- [ ] SSLCommerz Store ID: nattestkuacbdlive
- [ ] SSLCommerz Store Password: <redacted-live-credential>
- [ ] SSLCommerz IPN URL configured in SSLCommerz dashboard: nat-test.ku.ac.bd
- [ ] Database access (MySQL user with ALTER TABLE permissions): 
- [ ] Server SSH access: 
- [ ] Backup permissions
- [ ] Maintenance window scheduled (recommended: 2-3 hours)

### **SSLCommerz Credentials Format:**
```
Store ID: Your unique store identifier (e.g., "nat12345")
Store Password: Your API secret password
API Domain: live.sslcommerz.com (production)
```

---

## 🔒 **Phase 1: Pre-Deployment Safety** (30 minutes)

### **Step 1.1: Database Backup** ⚠️ **MANDATORY**
```bash
# SSH into production server
ssh user@nat-test.ku.ac.bd

# Navigate to project directory
cd /path/to/project

# Create complete database backup
mysqldump -u nattest_reg -p nattest_regs > backup_before_sslcommerz_$(date +%Y%m%d_%H%M%S).sql

# Verify backup file created
ls -lh backup_before_sslcommerz_*.sql

# Backup should be 50MB+ (depending on data size)
```

### **Step 1.2: Code Backup**
```bash
# Backup current codebase
tar -czf code_backup_before_sslcommerz_$(date +%Y%m%d_%H%M%S).tar.gz \
    frontend/intake/ \
    frontend/admin/ \
    frontend/js/registration.js \
    frontend/payment-*.html

# Verify backup
tar -tzf code_backup_before_sslcommerz_*.tar.gz | head -20
```

### **Step 1.3: Document Current State**
```bash
# Record current database schema
mysql -u nattest_reg -p nattest_regs -e "DESCRIBE registrations;" > current_schema.txt

# Record current registrations count
mysql -u nattest_reg -p nattest_regs -e "SELECT COUNT(*) FROM registrations;" > current_counts.txt

# Verify documentation
cat current_schema.txt
cat current_counts.txt
```

---

## 🗄️ **Phase 2: Database Migration** (45 minutes)

### **Step 2.1: Prepare Migration Files**
```bash
# Navigate to intake directory
cd /path/to/project/frontend/intake

# Verify migration file exists
ls -la migrations/add_payment_gateway_fields.sql

# Review migration file (important!)
cat migrations/add_payment_gateway_fields.sql
```

**Expected migration content:**
- 12 new columns to registrations table
- 5 new performance indexes
- Uses `IF NOT EXISTS` clauses for safety

### **Step 2.2: Test Migration on Backup** (Optional but Recommended)
```bash
# Create test database from backup
mysql -u nattest_reg -p -e "CREATE DATABASE nattest_test_backup;"
mysql -u nattest_reg -p nattest_test_backup < backup_before_sslcommerz_latest.sql

# Test migration on test database
mysql -u nattest_reg -p nattest_test_backup < migrations/add_payment_gateway_fields.sql

# Verify test migration
mysql -u nattest_reg -p nattest_test_backup -e "DESCRIBE registrations;"

# If test succeeds, proceed to production
```

### **Step 2.3: Execute Production Migration**
```bash
# Execute migration on production database
mysql -u nattest_reg -p nattest_regs < migrations/add_payment_gateway_fields.sql

# Expected output: (should be silent if successful)
# No errors = success

# Verify migration success
mysql -u nattest_reg -p nattest_regs -e "DESCRIBE registrations;"
```

**Expected new columns:**
- `payment_status` (enum: unpaid, paid, failed, refunded)
- `sslcommerz_transaction_id` (varchar)
- `sslcommerz_session_id` (varchar)
- `base_amount` (decimal)
- `transaction_fee` (decimal)
- `total_amount_paid` (decimal)
- `payment_method_detail` (enum)
- `payment_time` (datetime)
- `payment_ipn_received` (boolean)
- `payment_retry_token` (varchar)
- `payment_retry_expires` (datetime)
- `payment_retry_count` (int)

### **Step 2.4: Verify Indexes Created**
```bash
# Check if indexes were created
mysql -u nattest_reg -p nattest_regs -e "SHOW INDEX FROM registrations;"

# Expected new indexes:
# idx_payment_status
# idx_sslcommerz_transaction_id
# idx_payment_ipn_received
# idx_payment_status_created_at
# idx_payment_retry_token
```

### **Step 2.5: Migration Rollback Plan** (If Issues Occur)
```bash
# If migration fails, rollback immediately:
mysql -u nattest_reg -p nattest_regs << 'EOF'
-- Remove new columns
ALTER TABLE registrations DROP COLUMN payment_status;
ALTER TABLE registrations DROP COLUMN sslcommerz_transaction_id;
ALTER TABLE registrations DROP COLUMN sslcommerz_session_id;
ALTER TABLE registrations DROP COLUMN base_amount;
ALTER TABLE registrations DROP COLUMN transaction_fee;
ALTER TABLE registrations DROP COLUMN total_amount_paid;
ALTER TABLE registrations DROP COLUMN payment_method_detail;
ALTER TABLE registrations DROP COLUMN payment_time;
ALTER TABLE registrations DROP COLUMN payment_ipn_received;
ALTER TABLE registrations DROP COLUMN payment_retry_token;
ALTER TABLE registrations DROP COLUMN payment_retry_expires;
ALTER TABLE registrations DROP COLUMN payment_retry_count;

-- Remove new indexes
DROP INDEX IF EXISTS idx_payment_status ON registrations;
DROP INDEX IF EXISTS idx_sslcommerz_transaction_id ON registrations;
DROP INDEX IF EXISTS idx_payment_ipn_received ON registrations;
DROP INDEX IF EXISTS idx_payment_status_created_at ON registrations;
DROP INDEX IF EXISTS idx_payment_retry_token ON registrations;
EOF

# Restore from backup if needed
mysql -u nattest_reg -p nattest_regs < backup_before_sslcommerz_YYYYMMDD_HHMMSS.sql
```

---

## ⚙️ **Phase 3: SSLCommerz Configuration** (30 minutes)

### **Step 3.1: Update Environment Configuration**
```bash
# Navigate to intake directory
cd /path/to/project/frontend/intake

# Backup existing .env if exists
cp .env .env.backup_$(date +%Y%m%d_%H%M%S)

# Create/update .env file with SSLCommerz credentials
cat > .env << 'EOF'
# Database Configuration
DB_HOST=localhost
DB_NAME=nattest_regs
DB_USER=nattest_reg
DB_PASS=your_existing_password

# SSLCommerz Payment Gateway Configuration
SSLCZ_STORE_ID=your_sslcommerz_store_id
SSLCZ_STORE_PASSWORD=your_sslcommerz_store_password
SSLCZ_MODE=live
SSLCZ_SUCCESS_URL=https://nat-test.ku.ac.bd/payment-success.html
SSLCZ_FAIL_URL=https://nat-test.ku.ac.bd/payment-failed.html
SSLCZ_CANCEL_URL=https://nat-test.ku.ac.bd/payment-cancelled.html
SSLCZ_IPN_URL=https://nat-test.ku.ac.bd/intake/payment-ipn.php

# Site Configuration
SITE_URL=https://nat-test.ku.ac.bd

# Security Configuration
IP_SALT=your_existing_ip_salt
RATE_LIMIT_MINUTE=5
RATE_LIMIT_DAY=20
EOF
```

**Replace these values:**
- `your_sslcommerz_store_id` → Your actual Store ID
- `your_sslcommerz_store_password` → Your actual Store Password
- `your_existing_password` → Your existing database password
- `your_existing_ip_salt` → Your existing IP salt

### **Step 3.2: Verify Configuration**
```bash
# Check .env file contents
cat .env

# Verify no trailing spaces or errors
# Ensure SSLCommerz credentials are correct
```

### **Step 3.3: Configure SSLCommerz Dashboard** (External)
```bash
# Log into SSLCommerz merchant dashboard
# Visit: https://merchant.sslcommerz.com

# Configure IPN URL:
# Navigate to: Settings → IPN Settings
# Add IPN URL: https://nat-test.ku.ac.bd/intake/payment-ipn.php
# Select: POST method
# Enable: IPN for all transactions

# Configure redirect URLs:
# Success URL: https://nat-test.ku.ac.bd/payment-success.html
# Fail URL: https://nat-test.ku.ac.bd/payment-failed.html
# Cancel URL: https://nat-test.ku.ac.bd/payment-cancelled.html
```

---

## 🚀 **Phase 4: Code Deployment** (30 minutes)

### **Step 4.1: Deploy Updated Files**
```bash
# Deploy updated intake service files
# Method depends on your deployment process:

# Option A: If using Git
cd /path/to/project
git pull origin main  # Or deploy HEAD

# Option B: If using FTP/rsync
rsync -avz --delete \
    /local/path/frontend/intake/ \
    user@nat-test.ku.ac.bd:/path/to/frontend/intake/

# Option C: If using file upload
# Upload these specific files:
# - register.php (updated)
# - config.php (updated)  
# - payment-gateway.php (new)
# - payment-ipn.php (new)
# - payment-retry.php (new)
# - migrations/* (already executed)
```

### **Step 4.2: Deploy Frontend Files**
```bash
# Deploy updated frontend files
# These files need to be deployed:

# Payment result pages (new):
# - payment-success.html
# - payment-failed.html  
# - payment-cancelled.html
# - payment-retry.html

# Updated JavaScript:
# - js/registration.js (updated)

# Deployment command example:
rsync -avz \
    /local/path/frontend/payment-*.html \
    /local/path/frontend/js/registration.js \
    user@nat-test.ku.ac.bd:/path/to/frontend/
```

### **Step 4.3: Deploy Admin Panel Updates**
```bash
# Deploy updated admin files
# These files need deployment:

# New admin payment pages:
# - admin/pages/payments.php (new)
# - admin/api/payments/list.php (new)
# - admin/api/payments/retry-email.php (new)
# - admin/pages/registrations.php (updated)

# Deployment command:
rsync -avz \
    /local/path/frontend/admin/ \
    user@nat-test.ku.ac.bd:/path/to/frontend/admin/
```

### **Step 4.4: Set File Permissions**
```bash
# Set proper permissions on deployed files
chmod 644 frontend/intake/*.php
chmod 644 frontend/intake/payment-*.php
chmod 600 frontend/intake/.env

# Ensure directories are accessible
chmod 755 frontend/intake/
chmod 755 frontend/intake/uploads/
chmod 755 frontend/intake/logs/

# Admin panel permissions
chmod 644 frontend/admin/pages/*.php
chmod 644 frontend/admin/api/payments/*.php
chmod 600 frontend/admin/.env
```

---

## 🧪 **Phase 5: Testing & Validation** (30-45 minutes)

### **Step 5.1: Database Connection Test**
```bash
# Test database connectivity
curl https://nat-test.ku.ac.bd/intake/test_db_connection.php

# Expected response:
# {
#   "success": true,
#   "message": "Database connection successful",
#   "debug_info": {...}
# }
```

### **Step 5.2: Configuration Test**
```bash
# Test SSLCommerz configuration
# Create test file: test_config.php
curl https://nat-test.ku.ac.bd/intake/test_payment_config.php

# Expected response should include:
# - SSLCZ_STORE_ID: set
# - SSLCZ_MODE: live  
# - SSLCZ_API_DOMAIN: https://securepay.sslcommerz.com
```

### **Step 5.3: Registration Flow Test (Offline Payment)**
```bash
# Test offline payment first (fallback mechanism)
curl -X POST https://nat-test.ku.ac.bd/intake/register.php \
  -F "full_name=Test User" \
  -F "email=test@example.com" \
  -F "mobile=+880171234567" \
  -F "address=Dhaka, Bangladesh" \
  -F "dob=1990/01/01" \
  -F "gender=male" \
  -F "nationality=Bangladeshi" \
  -F "payment_method=offline" \
  -F "exam_level=N5" \
  -F "test_date=2026/12/15" \
  -F "website="

# Expected response:
# {
#   "success": true,
#   "message": "Registration submitted successfully",
#   "data": {
#     "id": "uuid",
#     "payment_status": "unpaid"
#   }
# }
```

### **Step 5.4: SSLCommerz Integration Test**
```bash
# Test online payment (creates SSLCommerz session)
curl -X POST https://nat-test.ku.ac.bd/intake/register.php \
  -F "full_name=Payment Test" \
  -F "email=paymenttest@example.com" \
  -F "mobile=+880171234568" \
  -F "address=Dhaka, Bangladesh" \
  -F "dob=1990/01/01" \
  -F "gender=male" \
  -F "nationality=Bangladeshi" \
  -F "payment_method=online" \
  -F "exam_level=N5" \
  -F "test_date=2026/12/15" \
  -F "website="

# Expected response:
# {
#   "success": true,
#   "message": "Registration submitted successfully",
#   "data": {
#     "id": "uuid",
#     "redirect_url": "https://securepay.sslcommerz.com/...",
#     "payment_status": "unpaid"
#   }
# }
```

### **Step 5.5: Admin Panel Test**
```bash
# Test admin payment dashboard
curl https://nat-test.ku.ac.bd/admin/pages/payments.php

# Expected: Admin login page (if not authenticated)
# After login: Payment dashboard with payment statistics
```

### **Step 5.6: Payment Result Pages Test**
```bash
# Test payment result pages
curl -I https://nat-test.ku.ac.bd/payment-success.html
curl -I https://nat-test.ku.ac.bd/payment-failed.html
curl -I https://nat-test.ku.ac.bd/payment-cancelled.html

# Expected: 200 OK responses for all pages
```

### **Step 5.7: End-to-End Payment Test** (Manual)
```bash
# 1. Open registration form in browser
#    https://nat-test.ku.ac.bd/registration.html

# 2. Fill out complete registration form
#    Select "Online Payment" method
#    Select exam level(s)

# 3. Submit form
#    Expected: Redirect to SSLCommerz payment gateway

# 4. Complete test payment (use small amount: 10 BDT)
#    Use test card if available
#    Complete payment process

# 5. Verify payment completion
#    Expected: Redirect to payment-success.html
#    Database: payment_status = 'paid'

# 6. Check admin panel
#    Expected: Payment appears in admin dashboard
#    Status: "Paid"
#    Amount: Correct amount paid
```

---

## 🔍 **Phase 6: Post-Deployment Verification** (15 minutes)

### **Step 6.1: Database Verification**
```bash
# Check recent registrations with payment data
mysql -u nattest_reg -p nattest_regs << 'EOF'
SELECT 
    id, 
    full_name, 
    email, 
    payment_status, 
    total_amount_paid, 
    payment_time,
    created_at 
FROM registrations 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at DESC;
EOF

# Expected: Recent registrations show payment data
```

### **Step 6.2: SSLCommerz Transaction Verification**
```bash
# Check if SSLCommerz transaction IDs are being stored
mysql -u nattest_reg -p nattest_regs << 'EOF'
SELECT 
    sslcommerz_transaction_id,
    payment_status,
    total_amount_paid,
    payment_time
FROM registrations 
WHERE sslcommerz_transaction_id IS NOT NULL
LIMIT 5;
EOF

# Expected: Records with SSLCommerz transaction IDs
```

### **Step 6.3: Error Log Check**
```bash
# Check for any PHP errors
tail -50 /path/to/frontend/intake/logs/php_errors.log

# Check for any activity issues
tail -50 /path/to/frontend/intake/logs/activity.log

# Expected: No critical errors
# Some SSLCommerz API logs are normal
```

### **Step 6.4: Performance Verification**
```bash
# Test registration endpoint performance
time curl -X POST https://nat-test.ku.ac.bd/intake/register.php \
  -F "full_name=Performance Test" \
  -F "email=perf@example.com" \
  -F "payment_method=offline"

# Expected: <2 seconds response time
```

---

## 🚨 **Phase 7: Rollback Procedures** (If Issues Occur)

### **Rollback Option 1: Code Rollback**
```bash
# If code issues occur, rollback immediately:
cd /path/to/project
git reset --hard HEAD~1  # Revert last commit

# Or restore from backup
tar -xzf code_backup_before_sslcommerz_YYYYMMDD_HHMMSS.tar.gz

# Redeploy rolled-back code
```

### **Rollback Option 2: Database Rollback**
```bash
# If database issues occur, restore from backup:
mysql -u nattest_reg -p nattest_regs < backup_before_sslcommerz_YYYYMMDD_HHMMSS.sql

# Verify rollback
mysql -u nattest_reg -p nattest_regs -e "DESCRIBE registrations;"
```

### **Rollback Option 3: Complete Rollback**
```bash
# If complete failure needed:
# 1. Stop services (if applicable)
# 2. Restore database from backup
# 3. Restore code from backup
# 4. Verify system functionality
# 5. Restart services
```

---

## 📊 **Phase 8: Monitoring & Validation** (Ongoing)

### **Step 8.1: Monitor First 48 Hours**
```bash
# Check payment success rate
mysql -u nattest_reg -p nattest_regs << 'EOF'
SELECT 
    COUNT(*) as total_registrations,
    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
    SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
    SUM(total_amount_paid) as total_revenue
FROM registrations 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR);
EOF

# Expected: Payment success rate >80%
```

### **Step 8.2: Monitor SSLCommerz IPNs**
```bash
# Check IPN delivery status
mysql -u nattest_reg -p nattest_regs << 'EOF'
SELECT 
    COUNT(*) as total_payments,
    SUM(CASE WHEN payment_ipn_received = TRUE THEN 1 ELSE 0 END) as ipn_received,
    SUM(CASE WHEN payment_ipn_received = FALSE THEN 1 ELSE 0 END) as ipn_missing
FROM registrations 
WHERE payment_status = 'paid' 
AND created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR);
EOF

# Expected: 95%+ IPN delivery rate
```

### **Step 8.3: Monitor Error Rates**
```bash
# Check for payment failures
mysql -u nattest_reg -p nattest_regs << 'EOF'
SELECT 
    payment_status,
    COUNT(*) as count,
    AVG(total_amount_paid) as avg_amount
FROM registrations 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
GROUP BY payment_status;
EOF

# Expected: Failed payments <10%
```

---

## ✅ **Deployment Success Criteria**

### **Phase Completion Checklist:**
- [ ] Phase 1: Pre-Deployment Safety ✅
- [ ] Phase 2: Database Migration ✅
- [ ] Phase 3: SSLCommerz Configuration ✅
- [ ] Phase 4: Code Deployment ✅
- [ ] Phase 5: Testing & Validation ✅
- [ ] Phase 6: Post-Deployment Verification ✅

### **Functionality Validation:**
- [ ] Offline payments still work
- [ ] Online payments work correctly
- [ ] SSLCommerz redirect functions
- [ ] Payment success page displays
- [ ] Payment failed page displays
- [ ] Admin panel shows payments
- [ ] Database stores payment data
- [ ] No critical errors in logs

### **Performance Validation:**
- [ ] Registration responds <2 seconds
- [ ] Payment gateway responds <3 seconds
- [ ] Admin panel loads <1 second
- [ ] No database performance issues

---

## 🎯 **Post-Deployment Actions**

### **Immediate (Day 1):**
- [ ] Monitor all payment transactions
- [ ] Check error logs every 2 hours
- [ ] Be ready to rollback if issues occur
- [ ] Communicate deployment status to stakeholders

### **Short-term (Days 2-7):**
- [ ] Continue monitoring payment success rates
- [ ] Optimize based on real usage data
- [ ] Address any user-reported issues
- [ ] Update documentation with actual data

### **Long-term (Ongoing):**
- [ ] Monitor weekly payment statistics
- [ ] Review SSLCommerz transaction fees
- [ ] Optimize payment flow based on data
- [ ] Plan future enhancements

---

## 📞 **Support & Troubleshooting**

### **Common Issues:**

**Issue:** Payment gateway not responding
```bash
# Solution: Check SSLCommerz credentials
# Verify SSLCZ_STORE_ID and SSLCZ_STORE_PASSWORD are correct
# Check SSLCommerz dashboard for account status
```

**Issue:** IPN not being received
```bash
# Solution: Verify IPN URL in SSLCommerz dashboard
# Check server firewall allows SSLCommerz IPs
# Verify payment-ipn.php is accessible
curl -X POST https://nat-test.ku.ac.bd/intake/payment-ipn.php
```

**Issue:** Registration fails after deployment
```bash
# Solution: Check database migration completed
mysql -u nattest_reg -p nattest_regs -e "DESCRIBE registrations;"
# Verify all 38 columns exist
```

---

## 🎉 **Deployment Completion**

**When Deployment is Successful:**
- ✅ SSLCommerz payment integration fully functional
- ✅ Users can pay online via SSLCommerz
- ✅ Admin can manage payments via dashboard
- ✅ Payment retry functionality available
- ✅ Complete payment workflow operational

**Your NAT-TEST registration system now has professional online payment processing!** 🚀

---

**Deployment Guide Version:** 1.0  
**Last Updated:** June 11, 2026  
**Compatible With:** HEAD (16 commits ahead of origin/main)