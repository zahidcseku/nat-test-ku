# SSLCommerz Payment Gateway Integration Design

**Date:** 2026-06-10  
**Project:** NAT-TEST Khulna University Registration System  
**Type:** Payment Gateway Integration  
**Status:** Approved Design  
**Implementation Plan:** TBD

---

## Overview

Integrate SSLCommerz payment gateway into the existing NAT-TEST registration system to enable secure online payments for registration fees. The integration will support all major payment methods in Bangladesh including credit/debit cards, mobile banking (bKash, Nagad, Rocket), and online bank transfers.

---

## Current System Architecture

### Existing Registration Flow
```
User fills form → Submit → /intake/register.php → MySQL → Admin reviews → Email confirmation
```

### Current Payment Structure
- Fee: 4000 BDT per exam level (multi-level registration supported)
- Payment methods: Bank deposit (manual) + Online payment (placeholder)
- Form has 4 steps: Personal Info → Exam Details → Payment Method → Document Uploads
- Technology: PHP 8.0+, MySQL, vanilla JavaScript
- Services: `/frontend/intake/` (intake service), `/frontend/admin/` (admin panel)

### Hard Constraints
- /frontend must never write to database
- /intake must never expose reads over HTTP
- /admin must always require authentication
- No third-party data stores (Supabase, Firebase, etc.)
- No JS frameworks in /frontend
- Server runs PHP only (no Python support)

---

## New Architecture with Payment Gateway

### Enhanced Registration Flow
```
User fills form → Submit → /intake/register.php (saves as 'unpaid')
                    → Redirect to SSLCommerz → User pays
                    → SSLCommerz IPN webhook → /intake/payment-ipn.php
                    → Update payment status to 'paid'
                    → Admin reviews → Email confirmation
```

### Key Design Decisions

1. **Payment After Save:** Registration saved first, then payment processed
   - Better UX: Form data safe even if payment fails
   - Retry capability: Users can retry payment without re-entering data
   - Audit trail: Complete record of all registration attempts

2. **Payment Status States:**
   - `unpaid` - Registration created, payment pending/failed
   - `paid` - Payment successful, awaiting admin review
   - `failed` - Payment failed, can retry
   - `refunded` - Payment refunded (manual admin action)

3. **Transaction Fee Handling:**
   - Base fee: 4000 BDT per level
   - Online fee: 2.5% (Visa/MC/Banks), 3.5% (AMEX)
   - User pays total amount (registration fee + transaction fee)
   - Example: 2 levels = 8000 + 200 (2.5%) = 8200 BDT

4. **Payment Verification:** IPN/Webhook only (most reliable method)
   - Server-to-server notification from SSLCommerz
   - Works even if user closes browser
   - Signature verification for security

5. **Failed Payment Handling:** Keep registrations for follow-up
   - Status remains 'unpaid' for retry
   - Automated retry emails sent
   - Public retry page for manual retry

---

## Database Schema Changes

### New Columns for `registrations` Table

```sql
-- Payment gateway integration fields
ALTER TABLE registrations ADD COLUMN payment_status ENUM('unpaid', 'paid', 'failed', 'refunded') DEFAULT 'unpaid' AFTER payment_method;
ALTER TABLE registrations ADD COLUMN sslcommerz_transaction_id VARCHAR(100) NULL AFTER payment_status;
ALTER TABLE registrations ADD COLUMN sslcommerz_session_id VARCHAR(100) NULL AFTER sslcommerz_transaction_id;
ALTER TABLE registrations ADD COLUMN base_amount DECIMAL(10,2) NULL AFTER sslcommerz_session_key;
ALTER TABLE registrations ADD COLUMN transaction_fee DECIMAL(10,2) NULL AFTER base_amount;
ALTER TABLE registrations ADD COLUMN total_amount_paid DECIMAL(10,2) NULL AFTER transaction_fee;
ALTER TABLE registrations ADD COLUMN payment_method_detail ENUM('card', 'bkash', 'nagad', 'rocket', 'bank', 'other') NULL AFTER total_amount_paid;
ALTER TABLE registrations ADD COLUMN payment_time DATETIME NULL AFTER payment_method_detail;
ALTER TABLE registrations ADD COLUMN payment_ipn_received BOOLEAN DEFAULT FALSE AFTER payment_time;
ALTER TABLE registrations ADD COLUMN payment_retry_token VARCHAR(50) NULL AFTER payment_ipn_received;
ALTER TABLE registrations ADD COLUMN payment_retry_expires DATETIME NULL AFTER payment_retry_token;
ALTER TABLE registrations ADD COLUMN payment_retry_count INT DEFAULT 0 AFTER payment_retry_expires;
```

### Field Explanations

- **`payment_status`** - Tracks payment lifecycle (unpaid → paid → refunded)
- **`sslcommerz_transaction_id`** - SSLCommerz transaction reference for tracking
- **`sslcommerz_session_id`** - Session key for SSLCommerz payment session
- **`base_amount`** - Registration fee only (4000 × number of levels)
- **`transaction_fee`** - SSLCommerz fee (2.5% or 3.5%)
- **`total_amount_paid`** - Final amount charged (base + fee)
- **`payment_method_detail`** - Specific method used (bkash, card, etc.)
- **`payment_time`** - When payment was completed
- **`payment_ipn_received`** - Did we get the webhook callback?
- **`payment_retry_token`** - Secure token for retry page access
- **`payment_retry_expires`** - Retry link expiration (7 days)
- **`payment_retry_count`** - Track retry attempts for analytics

---

## File Structure & Components

### New Files to Create

```
frontend/intake/
├── payment-ipn.php           # SSLCommerz IPN webhook handler
├── payment-gateway.php       # SSLCommerz API integration class
├── payment-retry.php         # Public retry page backend
└── migrations/
    └── add_payment_gateway_fields.sql  # Database migration

frontend/
├── payment-retry.html         # Public payment retry page
└── js/
    └── payment-retry.js       # Retry page functionality

frontend/admin/
├── pages/
│   └── payments.php          # Payment management page (new admin tab)
└── api/
    └── payments/
        ├── list.php           # Get payments with filters
        ├── retry-email.php    # Send retry email to user
        └── analytics.php     # Payment analytics data
```

### Modified Files

```
frontend/intake/
├── register.php              # Add SSLCommerz redirect logic
├── validate.php              # Add payment validation
└── config.php                # Add SSLCommerz config constants

frontend/registration.html     # Add transaction fee display
frontend/js/registration.js    # Add payment calculation logic

frontend/admin/pages/
    └── registrations.php       # Show payment status columns
```

---

## API Endpoints

### 1. POST `/intake/payment-ipn.php` - SSLCommerz IPN Webhook

**Purpose:** Receive server-to-server callbacks when payment status changes

**Request Format:**
```
POST /intake/payment-ipn.php
Content-Type: application/x-www-form-urlencoded

tran_id=REG_UUID&
card_type=VISA&
card_amount=8200.00&
card_no=4XXXXXX1234&
bank_tran_id=SSL12345&
currency=BDT&
status=SUCCESS&
error_code=000&
store_amount=7905.00
```

**Response:**
- `200 OK` - IPN received and processed
- `400 Bad Request` - Invalid signature/data
- `500 Internal Error` - Database error

**Logic:**
1. Verify SSLCommerz signature (prevent fraud)
2. Find registration by `tran_id`
3. Validate amount matches expected
4. Update payment status to 'paid'
5. Record transaction details
6. Set `payment_ipn_received = TRUE`

### 2. GET `/intake/payment-retry.php?token=XXX` - Retry Lookup

**Purpose:** Check if registration ID/email is eligible for payment retry

**Request:**
```
GET /intake/payment-retry.php?email=user@example.com
OR
GET /intake/payment-retry.php?registration_id=REG-UUID
```

**Response:**
```json
{
    "found": true,
    "registration_id": "uuid",
    "email": "user@example.com",
    "full_name": "John Doe",
    "base_amount": 8000,
    "transaction_fee": 200,
    "total_amount": 8200,
    "payment_status": "unpaid",
    "retry_token": "abc123...",
    "retry_link": "https://sslcommerz/pay/abc123...",
    "expires_at": "2026-06-17 23:59:59",
    "can_retry": true
}
```

### 3. GET `/admin/api/payments/list.php` - Payment Analytics

**Purpose:** Get payment statistics and filtered payment lists

**Request:**
```
GET /admin/api/payments/list.php?status=unpaid&date_from=2026-06-01
```

**Response:**
```json
{
    "stats": {
        "total_registrations": 150,
        "paid_count": 120,
        "unpaid_count": 30,
        "revenue": 984000,
        "pending_revenue": 240000
    },
    "payments": [
        {
            "id": "uuid",
            "full_name": "John Doe",
            "email": "john@example.com",
            "base_amount": 8000,
            "transaction_fee": 200,
            "total_amount": 8200,
            "payment_status": "paid",
            "payment_method": "online",
            "payment_time": "2026-06-10 14:30:00"
        }
    ]
}
```

### 4. POST `/admin/api/payments/retry-email.php` - Send Retry Email

**Purpose:** Send payment retry email to specific user

**Request:**
```
POST /admin/api/payments/retry-email.php
{
    "registration_id": "uuid"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Retry email sent successfully"
}
```

---

## Payment Flow - Complete User Journey

### Step-by-Step Process

**Step 1: User Fills Registration Form**
- Personal info, exam details, documents (existing flow)
- Chooses "Online Payment" method
- System calculates: Base fee + Transaction fee
  - Example: 2 levels × 4000 = 8000 + 200 (2.5%) = 8200 BDT
- Shows final total: "8200 BDT (includes 2.5% online fee)"

**Step 2: Form Submission**
- POST to `/intake/register.php`
- Validate all data (existing validation)
- Upload files (existing upload logic)
- Save to database with:
  - `payment_status = 'unpaid'`
  - `base_amount = 8000`
  - `transaction_fee = 200`
  - `total_amount_paid = 8200`
  - `payment_retry_token = random_secure_token()`
  - `payment_retry_expires = NOW() + 7 days`
- Generate SSLCommerz session with redirect URLs

**Step 3: Redirect to SSLCommerz**
- User redirected to SSLCommerz payment page
- SSLCommerz shows: 8200 BDT
- User pays (bkash/card/bank/etc.)
- Payment processing...

**Step 4A: Payment Success**
- SSLCommerz redirects to success page OR IPN webhook fires
- `payment_status = 'paid'`
- Admin reviews & approves registration

**Step 4B: Payment Failed/Abandoned**
- Status remains 'unpaid'
- User gets email with retry link
- Can visit payment-retry.html to retry

---

## Security & Error Handling

### Security Measures

1. **SSLCommerz Signature Verification**
   - MD5 signature validation to prevent fraud
   - Verify POST data authenticity

2. **IPN Whitelist**
   - Restrict webhook calls to SSLCommerz IP addresses
   - Prevent fake IPN calls

3. **Amount Validation**
   - Prevent payment manipulation
   - Verify received amount matches expected

4. **Idempotency - Duplicate IPN Handling**
   - Prevent double payment updates
   - Check current status before updating

5. **Secure Retry Token Generation**
   - Use `bin2hex(random_bytes(32))` for secure tokens
   - Hash verification for access control

### Error Handling Scenarios

1. **SSLCommerz Timeout/No Response**
   - Log error but save registration
   - User can retry via payment-retry page

2. **IPN Webhook Never Arrives**
   - Admin can manually verify via SSLCommerz API
   - Check transactions older than 1 hour without IPN

3. **User Abandons Payment Page**
   - Cron job sends reminder emails
   - Retry links valid for 7 days

4. **Payment Fails (Insufficient Funds, etc.)**
   - Status set to 'failed'
   - Automatic retry email sent
   - Increment retry counter

---

## Admin Panel - Payment Management

### New Payment Management Page (`/admin/pages/payments.php`)

**Features:**
- Payment statistics cards (revenue, counts, pending)
- Filter options (status, date range, search, exam level)
- Action buttons (send retry email, export CSV, view registration)

**Payment Status Indicators:**
- Paid ✓ (green badge)
- Unpaid ⏳ (yellow badge)
- Failed ✗ (red badge)
- Refunded ↺ (blue badge)

### Enhanced Registration Table

Add payment columns to existing registrations table:
- Payment Status
- Amount (total with fees)
- Payment Method (Online/Bank Deposit)
- Payment Time
- Actions (Send Retry Email, View Transaction)

### Email Templates

**Payment Retry Email Template:**
```php
Subject: Complete Your NAT-TEST Registration Payment

Dear {full_name},

Your registration for NAT-TEST is pending payment completion.

Registration Details:
• Registration ID: {registration_id}
• Name: {full_name}
• Email: {email}
• Exam Levels: {exam_levels}
• Test Date: {test_date}
• Total Amount: {total_amount} BDT (includes {transaction_fee} BDT online fee)

PAYMENT STATUS: Unpaid
To complete your registration, please make the payment using the link below:

👉 {payment_retry_link} 👈

This secure payment link will expire in 7 days (expires: {retry_expiry}).

After successful payment:
• Your registration will be automatically marked as paid
• You will receive a payment confirmation email
• Your application will be reviewed by our admin team
• Admission ticket will be sent after approval

Payment Methods Available:
• bKash, Nagad, Rocket (Mobile Banking)
• Credit/Debit Cards (Visa, MasterCard, AMEX)
• Online Bank Transfer (Supports all major banks)

Need Help?
• Email: info@nat-test.ku.ac.bd
• Phone: [Your Phone Number]
• Office Hours: Sun-Thu, 9am-5pm

Don't let this opportunity pass! Complete your payment today.

Best regards,
NAT-TEST Administration Team
Khulna University
```

---

## Testing & Deployment

### Development Workflow

**Phase 1: Local Development (Days 1-2)**
- Setup environment with SSLCommerz sandbox credentials
- Create all new files
- Run database migration locally
- Implement core payment logic

**Phase 2: Integration Testing (Day 3)**
- Test successful payment flow
- Test failed payment scenarios
- Test retry functionality
- Test admin payment management
- Test security measures

**Phase 3: Production Deployment (Day 4-5)**
- Backup production database
- Run migration
- Deploy new files
- Update production .env with live credentials
- Monitor first 24 hours

### Test Cases

✅ **Test 1: Successful Payment Flow**
✅ **Test 2: Failed Payment**
✅ **Test 3: Abandoned Payment**
✅ **Test 4: Multi-Level Registration**
✅ **Test 5: Admin Payment Management**
✅ **Test 6: Security Tests**

### Go-Live Monitoring

**First 24 Hours:**
- Monitor error logs
- Check IPN delivery rate
- Verify payment status updates
- Monitor admin panel for errors

**First Week:**
- Daily revenue reconciliation
- Check for unpaid registrations
- Monitor retry link click rates
- Track payment success rates

### Rollback Plan

If critical issues arise:
1. Revert database changes
2. Remove payment integration files
3. Revert register.php to backup
4. Notify users of temporary issue

---

## Configuration

### Environment Variables (.env)

```bash
# SSLCommerz Configuration
SSLCZ_STORE_ID=your_test_store_id
SSLCZ_STORE_PASSWORD=your_test_password
SSLCZ_MODE=sandbox  # sandbox | live
SSLCZ_SUCCESS_URL=https://nat-test.ku.ac.bd/payment-success.html
SSLCZ_FAIL_URL=https://nat-test.ku.ac.bd/payment-failed.html
SSLCZ_CANCEL_URL=https://nat-test.ku.ac.bd/payment-cancelled.html
SSLCZ_IPN_URL=https://nat-test.ku.ac.bd/intake/payment-ipn.php
```

---

## Success Criteria

### Technical Success
- ✅ SSLCommerz payment processing functional
- ✅ IPN webhook reliability >99%
- ✅ Payment status updates accurate
- ✅ Retry functionality working
- ✅ Admin payment management functional

### Business Success
- ✅ Payment success rate >90%
- ✅ User experience seamless
- ✅ Revenue tracking accurate
- ✅ Failed payment recovery effective
- ✅ Admin workload reduced

### Security Success
- ✅ No fraudulent payments processed
- ✅ All signature validations passing
- ✅ No SQL injection vulnerabilities
- ✅ Proper error handling in place

---

## Timeline

| Phase | Duration | Deliverable |
|-------|----------|-------------|
| Development | 2-3 days | All code files complete |
| Testing | 1 day | All test cases passing |
| Deployment | 1 day | Live payment gateway working |
| Monitoring | 1 week | Stable payment processing |
| **Total** | **5-7 days** | **Production-ready system** |

---

## Risks & Mitigation

### Risk 1: SSLCommerz API Changes
**Mitigation:** Use official SDK, version lock dependencies, monitor API changelog

### Risk 2: IPN Webhook Delivery Failures
**Mitigation:** Manual admin verification option, retry email fallback, monitoring alerts

### Risk 3: Payment Gateway Downtime
**Mitigation:** Clear error messaging, keep bank deposit option available, status page

### Risk 4: Security Vulnerabilities
**Mitigation:** Code review, penetration testing, SSLCommerz security best practices

### Risk 5: User Payment Confusion
**Mitigation:** Clear instructions, help documentation, support contact information

---

## Post-Launch Tasks

**Week 1:**
- Monitor payment success rates (target: >90%)
- Fix any bugs reported by users
- Optimize retry email timing based on data

**Week 2:**
- Analyze payment method distribution
- Update admin training documentation
- Create FAQ for payment-related questions

**Month 1:**
- Revenue reconciliation report
- Payment gateway cost analysis
- User satisfaction survey
- Plan improvements based on data

---

## Conclusion

This design provides a comprehensive, secure, and user-friendly payment gateway integration that enhances the existing NAT-TEST registration system. The approach prioritizes reliability, security, and maintainability while providing excellent user experience for both applicants and administrators.

**Next Steps:**
1. Create detailed implementation plan
2. Begin development following approved design
3. Conduct thorough testing
4. Deploy with monitoring
5. Gather feedback and optimize

---

**Design Approved By:** User  
**Date:** 2026-06-10  
**Status:** Ready for Implementation Planning