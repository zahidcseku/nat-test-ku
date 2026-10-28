# Payment Flow Testing Guide

**Overview:** Comprehensive testing guide for SSLCommerz payment gateway integration

**Date:** June 10, 2026  
**Status:** Ready for Server Testing  
**Environment:** Khulna University Server (PHP/MySQL)

---

## Testing Prerequisites

### Server Access
- [ ] SSH access to `nat-test.ku.ac.bd`
- [ ] Database access to `nattest_regs`
- [ ] SSLCommerz sandbox account credentials

### Test Environment Setup
```bash
# SSH into server
ssh user@nat-test.ku.ac.bd

# Navigate to intake directory
cd /path/to/frontend/intake

# Create test environment file
cp .env.example .env.test

# Edit .env.test with sandbox credentials
nano .env.test
```

### Required Test Credentials
- SSLCommerz Sandbox Store ID: `test_box_store_id`
- SSLCommerz Sandbox Password: `test_box_password`
- Test Database: `nattest_regs_test`

---

## Test Suite Structure

### 1. Unit Tests (Server-Side PHP)
**File:** `test_payment_flow.php`

Tests individual components:
- Database connection and payment fields
- SSLCommerz configuration
- Payment calculations (base, fee, total)
- Retry token generation
- Retry expiry calculation
- Payment gateway class initialization
- IPN signature verification logic
- File upload directory structure
- Environment variables
- Payment status enum values

**Run:** `php test_payment_flow.php`

### 2. Integration Tests (Browser-Based)
**File:** `test_payment_integration.html`

Tests complete payment flow:
- Registration form with payment selection
- Payment redirect to SSLCommerz
- Payment success/failure scenarios
- IPN webhook handling
- Retry functionality
- Admin payment dashboard

### 3. Manual Test Cases
**File:** `PAYMENT_MANUAL_TEST_CASES.md`

Step-by-step manual testing procedures

---

## Automated Test Execution

### Server-Side Tests
```bash
# SSH to server
ssh user@nat-test.ku.ac.bd

# Navigate to intake directory
cd /path/to/frontend/intake

# Run test suite
php test_payment_flow.php

# Check results
cat logs/test_results.log
```

### Expected Output
```
========================================
Payment Flow Test Suite
========================================

Running tests...

✓ [PASS] Environment Variables
✓ [PASS] Database Connection  
✓ [PASS] SSLCommerz Configuration
✓ [PASS] Payment Calculation
✓ [PASS] Retry Token Generation
✓ [PASS] Retry Expiry Calculation
✓ [PASS] Payment Gateway Class
✓ [PASS] IPN Signature Verification
✓ [PASS] File Upload Structure
✓ [PASS] Payment Status Enum

========================================
Test Summary
========================================
Total Tests: 10
Passed: 10
Failed: 0
Skipped: 0
Success Rate: 100.00%
```

---

## Browser-Based Integration Testing

### Test 1: Complete Payment Flow (Success)
1. Navigate to registration form
2. Fill out all required fields
3. Select "Online Payment" method
4. Verify fee calculation: Base + Transaction Fee
5. Submit form
6. Verify redirect to SSLCommerz sandbox
7. Complete test payment
8. Verify redirect to success page
9. Check database: payment_status = 'paid'
10. Verify email confirmation sent

### Test 2: Payment Failure
1. Start registration process
2. During SSLCommerz payment, use failing test card
3. Verify redirect to failure page
4. Check database: payment_status = 'failed'
5. Verify retry link available

### Test 3: Payment Cancellation
1. Start payment process
2. Cancel payment on SSLCommerz page
3. Verify redirect to cancellation page
4. Check database: payment_status = 'unpaid'

### Test 4: Retry Functionality
1. Create failed payment registration
2. Access retry link from email/failure page
3. Verify retry page loads with correct amount
4. Complete payment via retry
5. Verify database updated: payment_status = 'paid', payment_retry_count = 1

### Test 5: IPN Webhook Handling
1. Create test IPN payload
2. Send POST request to payment-ipn.php
3. Verify signature validation
4. Check database: payment_ipn_received = true
5. Verify payment status updated

### Test 6: Admin Payment Dashboard
1. Login to admin panel
2. Navigate to Payments page
3. Verify payment statistics display
4. Test payment filters (status, date range)
5. Test payment search functionality
6. Verify CSV export works
7. Test retry email functionality

---

## Manual Test Procedures

### Pre-Test Setup
1. **Database Backup**
   ```bash
   mysqldump -u nattest_reg -p nattest_regs > backup_before_tests.sql
   ```

2. **Clear Test Data**
   ```sql
   DELETE FROM registrations WHERE email LIKE '%@test.com';
   ```

3. **Create Test User**
   ```bash
   php scripts/create_test_user.php
   ```

### Test Case 1: Single Level Payment
**Objective:** Test payment for single exam level (N5)

**Steps:**
1. Fill registration form with N5 level
2. Select online payment
3. Verify fee breakdown:
   - Base: 4000 BDT
   - Fee: 100 BDT (2.5%)
   - Total: 4100 BDT
4. Complete payment
5. Verify success

**Expected Result:** Registration created, payment status 'paid'

### Test Case 2: Multiple Level Payment
**Objective:** Test payment for multiple exam levels (N5, N4)

**Steps:**
1. Fill registration form with N5 + N4 levels
2. Select online payment
3. Verify fee breakdown:
   - Base: 8000 BDT (4000 × 2)
   - Fee: 200 BDT (2.5%)
   - Total: 8200 BDT
4. Complete payment

**Expected Result:** Registration created with payment_status 'paid', total_amount_paid = 8200

### Test Case 3: Payment Retry After Expiry
**Objective:** Test retry link expiration

**Steps:**
1. Create failed payment registration
2. Wait 7 days
3. Attempt to use retry link
4. Verify error message

**Expected Result:** Retry link expired, error displayed

### Test Case 4: IPN Duplicate Handling
**Objective:** Test duplicate IPN handling

**Steps:**
1. Send successful payment IPN
2. Verify database updated
3. Send identical IPN again
4. Verify no duplicate processing

**Expected Result:** Second IPN ignored, no double payment

---

## Performance Testing

### Load Testing
```bash
# Using Apache Bench
ab -n 100 -c 10 https://nat-test.ku.ac.bd/intake/register.php

# Expected: <2 seconds per request, 0 failures
```

### Database Query Performance
```sql
-- Test payment status query performance
EXPLAIN SELECT * FROM registrations 
WHERE payment_status = 'paid' 
ORDER BY created_at DESC 
LIMIT 100;

-- Expected: Using index, <100ms execution time
```

---

## Security Testing

### SQL Injection Prevention
```bash
# Test with malicious input
curl -X POST https://nat-test.ku.ac.bd/intake/register.php \
  -F "email=test@test.com'; DROP TABLE registrations; --"

# Expected: Input sanitized, no SQL execution
```

### XSS Prevention
```bash
# Test with script tags
curl -X POST https://nat-test.ku.ac.bd/intake/register.php \
  -F "full_name=<script>alert('XSS')</script>"

# Expected: Input escaped, no script execution
```

### CSRF Protection
```bash
# Test without CSRF token
curl -X POST https://nat-test.ku.ac.bd/intake/register.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "email=test@test.com"

# Expected: Request rejected
```

---

## Troubleshooting

### Common Issues

**Issue:** Database connection failed
**Solution:** Check .env credentials, verify MySQL service running

**Issue:** SSLCommerz API timeout
**Solution:** Check network connectivity, verify SSLCZ_API_DOMAIN

**Issue:** IPN not received
**Solution:** Check SSLCommerz dashboard IPN URL configuration

**Issue:** Payment status not updated
**Solution:** Check payment-ipn.php logs, verify IPN signature

### Debug Mode
Enable debug logging in .env:
```env
APP_DEBUG=true
LOG_LEVEL=debug
```

---

## Test Results Documentation

### Results Format
```json
{
  "test_date": "2026-06-10",
  "test_environment": "sandbox",
  "total_tests": 25,
  "passed": 24,
  "failed": 1,
  "skipped": 0,
  "test_cases": [
    {
      "name": "Payment Flow Success",
      "status": "PASS",
      "duration": "45 seconds",
      "notes": "Payment completed successfully"
    }
  ]
}
```

### Sign-off Criteria
- [ ] All unit tests pass (100%)
- [ ] All integration tests pass (95%+)
- [ ] Security tests pass (100%)
- [ ] Performance tests meet criteria
- [ ] Manual tests completed successfully
- [ ] No critical bugs identified
- [ ] Documentation verified

---

## Next Steps After Testing

1. **Fix any identified issues**
2. **Update documentation with findings**
3. **Create production deployment plan**
4. **Schedule production deployment window**
5. **Prepare monitoring and alerting**

---

## Contact Information

**Technical Support:** tech-support@nat-test.ku.ac.bd  
**SSLCommerz Support: support@sslcommerz.com  
**Project Documentation:** See CLAUDE.md for architecture

**Test Coordinator:** [Name]  
**Test Completion Date:** [Date]