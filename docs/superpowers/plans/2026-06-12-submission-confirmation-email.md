# Submission-Confirmation Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every applicant automatically receives either a payment confirmation (online payment confirmed, or receipt attached) or an application receipt with payment options (pay link + QR for online; bank details + receipt instructions + QR for offline).

**Architecture:** A self-contained intake mailer (`frontend/intake/mailer.php`): pure `buildRegistrationEmail()` (data → subject/HTML) + `sendRegistrationEmail()` wrapping PHP `mail()`, logging to `email_log` when available and to the activity log always, never throwing. Called from register.php's success path and payment-ipn.php's paid branch. The SSLCommerz QR ships as a hosted PNG + PDF under `/resources/`.

**Tech Stack:** PHP 8 (no deps, `mail()`), macOS `sips` for the one-time PDF→PNG render. PHP CLI at `/opt/homebrew/bin/php`. Local MariaDB available for runtime checks (`brew services start mariadb`, DB `nattest_regs`, user `nattest_reg` / `localtest123`; create `frontend/intake/.env` per Task 4 and DELETE it afterwards).

**Spec:** `docs/superpowers/specs/2026-06-12-submission-confirmation-email-design.md`

---

### Task 1: Hosted QR assets + config constants

**Files:**
- Create: `frontend/resources/sslcommerz-payment-qr.png` (rendered)
- Create: `frontend/resources/sslcommerz-payment-qr.pdf` (copy)
- Modify: `frontend/intake/config.php` (add two defines after the SSLCommerz section)

- [ ] **Step 1: Render the QR PDF to PNG and copy the PDF**

```bash
cd /Users/zahid/projects/NAT_TEST_KU
sips -s format png -Z 700 "dev_resources/QR for SSLCOMMERZ.pdf" --out frontend/resources/sslcommerz-payment-qr.png
cp "dev_resources/QR for SSLCOMMERZ.pdf" frontend/resources/sslcommerz-payment-qr.pdf
file frontend/resources/sslcommerz-payment-qr.png   # expect: PNG image data
```

If `sips` cannot read the PDF, fall back to:
```bash
qlmanage -t -s 700 -o /tmp "dev_resources/QR for SSLCOMMERZ.pdf" && mv "/tmp/QR for SSLCOMMERZ.pdf.png" frontend/resources/sslcommerz-payment-qr.png
```

- [ ] **Step 2: Add mail constants to config.php**

In `frontend/intake/config.php`, directly after the `SSLCZ_IPN_WHITELIST` define block, add:

```php
// ============================================
// Automated email configuration
// ============================================

// Sender for automated applicant emails
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'no-reply@nat-test.ku.ac.bd');

// Where applicants email payment proof (bank deposit / QR payments)
define('RECEIPT_EMAIL', 'money_receipt@nat-test.ku.ac.bd');
```

- [ ] **Step 3: Lint and commit**

```bash
php -l frontend/intake/config.php
git add frontend/resources/sslcommerz-payment-qr.png frontend/resources/sslcommerz-payment-qr.pdf frontend/intake/config.php
git commit -m "feat: hosted SSLCommerz QR assets and mail config for applicant emails"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 2: The mailer (TDD)

**Files:**
- Test: create `frontend/intake/test/test_registration_email.php`
- Create: `frontend/intake/mailer.php`

- [ ] **Step 1: Write the failing test**

Create `frontend/intake/test/test_registration_email.php`:

```php
<?php
/**
 * buildRegistrationEmail(): variants, payment sections, escaping.
 * Run: php frontend/intake/test/test_registration_email.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mailer.php';

function baseReg(array $overrides = []): array {
    return array_merge([
        'id' => 'reg-uuid-123',
        'full_name' => 'Test Applicant',
        'email' => 'applicant@example.com',
        'mobile' => '01712345678',
        'address' => '123 Test Road, Khulna',
        'dob' => '2000-01-15',
        'nationality' => 'Bangladeshi',
        'id_document_type' => 'passport',
        'id_document_number' => 'AB1234567',
        'exam_level' => '1Q,3Q',
        'test_date' => '2026-08-15',
        'total_amount' => 8000.0,
        'payment_method' => 'online',
        'payment_status' => 'unpaid',
        'retry_token' => 'abcdef0123456789abcdef0123456789',
        'has_receipt' => false,
        'bank_tran_id' => '',
    ], $overrides);
}

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// --- Application receipt, ONLINE: pay link + QR, no bank details
$m = buildRegistrationEmail(baseReg(), 'submission_receipt');
$check('online receipt: has subject', is_string($m['subject']) && $m['subject'] !== '');
$check('online receipt: contains registration id', strpos($m['body'], 'reg-uuid-123') !== false);
$check('online receipt: contains applicant data', strpos($m['body'], 'Test Applicant') !== false
    && strpos($m['body'], 'AB1234567') !== false && strpos($m['body'], '1Q,3Q') !== false);
$check('online receipt: contains pay link', strpos($m['body'], '/payment-retry.html?token=abcdef0123456789abcdef0123456789') !== false);
$check('online receipt: contains hosted QR image', strpos($m['body'], '/resources/sslcommerz-payment-qr.png') !== false);
$check('online receipt: links the QR PDF', strpos($m['body'], '/resources/sslcommerz-payment-qr.pdf') !== false);
$check('online receipt: NO bank account number', strpos($m['body'], '0200025673722') === false);

// --- Application receipt, OFFLINE (no receipt): bank details + receipt email + QR
$m = buildRegistrationEmail(baseReg(['payment_method' => 'offline']), 'submission_receipt');
$check('offline receipt: bank details present', strpos($m['body'], '0200025673722') !== false
    && strpos($m['body'], 'Agrani Bank') !== false);
$check('offline receipt: receipt email instruction', strpos($m['body'], RECEIPT_EMAIL) !== false);
$check('offline receipt: QR also present (user decision)', strpos($m['body'], '/resources/sslcommerz-payment-qr.png') !== false);

// --- Confirmation, receipt attached (pending verification)
$m = buildRegistrationEmail(baseReg(['payment_method' => 'offline', 'has_receipt' => true]), 'payment_confirmation');
$check('receipt confirmation: pending-verification wording', stripos($m['body'], 'pending verification') !== false);
$check('receipt confirmation: no payment options section', strpos($m['body'], '/resources/sslcommerz-payment-qr.png') === false
    && strpos($m['body'], '0200025673722') === false);

// --- Confirmation, paid via gateway
$m = buildRegistrationEmail(baseReg(['payment_status' => 'paid', 'bank_tran_id' => 'BANKREF99']), 'payment_confirmation');
$check('paid confirmation: payment received wording', stripos($m['body'], 'payment received') !== false);
$check('paid confirmation: transaction reference', strpos($m['body'], 'BANKREF99') !== false);

// --- Escaping and hygiene
$m = buildRegistrationEmail(baseReg(['full_name' => '<script>alert(1)</script>']), 'submission_receipt');
$check('applicant values are HTML-escaped', strpos($m['body'], '<script>alert(1)</script>') === false
    && strpos($m['body'], '&lt;script&gt;') !== false);
$check('no data: URIs', strpos($m['body'], 'src="data:') === false);

exit($pass ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php frontend/intake/test/test_registration_email.php`
Expected: fatal — mailer.php does not exist yet.

- [ ] **Step 3: Create the mailer**

Create `frontend/intake/mailer.php`:

```php
<?php
/**
 * NAT-TEST Intake Service - Applicant Email
 *
 * Automated submission/payment emails. Self-contained by design: the intake
 * service must not import anything from /admin. Sending uses PHP mail(),
 * the same transport the admin panel uses.
 *
 * RULE: email must never break a registration. sendRegistrationEmail()
 * never throws — every failure degrades to a logged warning.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

// Bank details shown for offline payment (must match registration.html)
const BANK_ACCOUNT_NAME = 'Test Site Director';
const BANK_ACCOUNT_NUMBER = '0200025673722';
const BANK_NAME = 'Agrani Bank Plc.';
const BANK_BRANCH = 'Khulna University';

/**
 * Build the subject and HTML body for an applicant email.
 *
 * @param array $registration Field values: id, full_name, email, mobile,
 *   address, dob, nationality, id_document_type, id_document_number,
 *   exam_level, test_date, total_amount, payment_method, payment_status,
 *   retry_token, has_receipt, bank_tran_id
 * @param string $variant 'submission_receipt' | 'payment_confirmation'
 * @return array ['subject' => string, 'body' => string]
 */
function buildRegistrationEmail(array $registration, string $variant): array {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $idTypeLabels = ['passport' => 'Passport', 'national_id' => 'National ID'];
    $idTypeLabel = $idTypeLabels[$registration['id_document_type'] ?? ''] ?? '—';
    $amount = number_format((float)($registration['total_amount'] ?? 0), 2);

    // The submitted information (data only — never uploaded images)
    $rows = [
        'Registration ID' => $registration['id'] ?? '',
        'Full Name' => $registration['full_name'] ?? '',
        'Email' => $registration['email'] ?? '',
        'Mobile' => $registration['mobile'] ?? '',
        'Address' => $registration['address'] ?? '',
        'Date of Birth' => $registration['dob'] ?? '',
        'Nationality' => $registration['nationality'] ?? '',
        'ID Document' => $idTypeLabel . ' · ' . ($registration['id_document_number'] ?? ''),
        'Exam Level(s)' => $registration['exam_level'] ?? '',
        'Test Date' => $registration['test_date'] ?? '',
        'Registration Fee' => $amount . ' BDT',
        'Payment Method' => ($registration['payment_method'] ?? '') === 'online' ? 'Online Payment' : 'Bank Deposit',
    ];

    $table = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:560px;font-size:14px;">';
    foreach ($rows as $label => $value) {
        $table .= '<tr>'
            . '<td style="border:1px solid #d8dde3;background:#f4f6f8;font-weight:bold;width:38%;">' . $e($label) . '</td>'
            . '<td style="border:1px solid #d8dde3;">' . $e($value) . '</td>'
            . '</tr>';
    }
    $table .= '</table>';

    $nextSteps = '<h3 style="color:#002147;">Next steps</h3>'
        . '<ol style="font-size:14px;">'
        . '<li>Our team reviews your application and payment.</li>'
        . '<li>You will receive an approval email once the review is complete.</li>'
        . '<li>Your admission ticket will be emailed to you before the exam.</li>'
        . '</ol>';

    $qrSection = '<p style="font-size:14px;">You can also pay by scanning our SSLCommerz QR code '
        . '(all cards &amp; mobile wallets):</p>'
        . '<p><img src="' . $e(SITE_URL . '/resources/sslcommerz-payment-qr.png') . '" alt="SSLCommerz payment QR code" width="280" style="max-width:280px;"></p>'
        . '<p style="font-size:13px;"><a href="' . $e(SITE_URL . '/resources/sslcommerz-payment-qr.pdf') . '">Download the QR code (PDF)</a></p>'
        . '<p style="font-size:13px;color:#555;">If you pay via the QR code, please email your payment proof to '
        . '<a href="mailto:' . $e(RECEIPT_EMAIL) . '">' . $e(RECEIPT_EMAIL) . '</a> '
        . 'mentioning your Registration ID so we can match your payment.</p>';

    if ($variant === 'payment_confirmation') {
        $paid = ($registration['payment_status'] ?? '') === 'paid';
        if ($paid) {
            $banner = '<p style="font-size:15px;background:#e6f4ea;border-left:4px solid #1e8e3e;padding:10px 14px;">'
                . '<strong>Payment received.</strong> Your online payment was successful'
                . (!empty($registration['bank_tran_id'])
                    ? ' (transaction reference: ' . $e($registration['bank_tran_id']) . ')'
                    : '')
                . '.</p>';
            $subject = 'NAT-TEST Registration — Payment Received';
        } else {
            $banner = '<p style="font-size:15px;background:#fef7e0;border-left:4px solid #f9ab00;padding:10px 14px;">'
                . '<strong>Payment receipt received — pending verification.</strong> '
                . 'We received your payment receipt with your application; our team will verify it during review.</p>';
            $subject = 'NAT-TEST Registration Received — Receipt Pending Verification';
        }
        $middle = $banner;
    } else {
        $subject = 'NAT-TEST Application Received — Payment Required';
        $intro = '<p style="font-size:15px;background:#fef7e0;border-left:4px solid #f9ab00;padding:10px 14px;">'
            . '<strong>Your application is saved but not yet paid.</strong> '
            . 'Please complete the payment of <strong>' . $e($amount) . ' BDT</strong> using one of the options below.</p>';

        if (($registration['payment_method'] ?? '') === 'online') {
            $payLink = SITE_URL . '/payment-retry.html?token=' . ($registration['retry_token'] ?? '');
            $options = '<h3 style="color:#002147;">Pay online</h3>'
                . '<p style="font-size:14px;"><a href="' . $e($payLink) . '" '
                . 'style="display:inline-block;background:#002147;color:#ffffff;padding:10px 22px;border-radius:6px;text-decoration:none;">'
                . 'Complete your payment</a></p>'
                . '<p style="font-size:13px;color:#555;">Or open this link: <a href="' . $e($payLink) . '">' . $e($payLink) . '</a></p>'
                . $qrSection;
        } else {
            $options = '<h3 style="color:#002147;">Pay by bank deposit</h3>'
                . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:14px;">'
                . '<tr><td style="border:1px solid #d8dde3;background:#f4f6f8;font-weight:bold;">Account Name</td><td style="border:1px solid #d8dde3;">' . $e(BANK_ACCOUNT_NAME) . '</td></tr>'
                . '<tr><td style="border:1px solid #d8dde3;background:#f4f6f8;font-weight:bold;">Account Number</td><td style="border:1px solid #d8dde3;">' . $e(BANK_ACCOUNT_NUMBER) . '</td></tr>'
                . '<tr><td style="border:1px solid #d8dde3;background:#f4f6f8;font-weight:bold;">Bank</td><td style="border:1px solid #d8dde3;">' . $e(BANK_NAME) . '</td></tr>'
                . '<tr><td style="border:1px solid #d8dde3;background:#f4f6f8;font-weight:bold;">Branch</td><td style="border:1px solid #d8dde3;">' . $e(BANK_BRANCH) . '</td></tr>'
                . '</table>'
                . '<p style="font-size:13px;color:#555;">After depositing, email your deposit receipt to '
                . '<a href="mailto:' . $e(RECEIPT_EMAIL) . '">' . $e(RECEIPT_EMAIL) . '</a> '
                . 'mentioning your Registration ID. Do not make partial payments; deposits are non-refundable.</p>'
                . $qrSection;
        }
        $middle = $intro . $options;
    }

    $body = '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
        . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
        . '<p style="font-size:14px;">Dear ' . $e($registration['full_name'] ?? 'Applicant') . ',</p>'
        . $middle
        . '<h3 style="color:#002147;">Your submitted information</h3>'
        . $table
        . $nextSteps
        . '<p style="font-size:13px;color:#555;">Questions? Contact '
        . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>. '
        . 'This is an automated message — please do not reply to this address.</p>'
        . '</body></html>';

    return ['subject' => $subject, 'body' => $body];
}

/**
 * Build and send an applicant email. Never throws.
 *
 * @return bool True if mail() accepted the message
 */
function sendRegistrationEmail(array $registration, string $variant): bool {
    try {
        $mail = buildRegistrationEmail($registration, $variant);
        $to = $registration['email'] ?? '';
        if ($to === '') {
            logActivity('Applicant email skipped: no recipient address', 'warning');
            return false;
        }

        $headers = 'From: ' . MAIL_FROM . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n";

        $success = @mail($to, $mail['subject'], $mail['body'], $headers);

        logActivity(($success ? 'Applicant email sent' : 'Applicant email FAILED')
            . " ({$variant}) for registration " . ($registration['id'] ?? 'unknown'), $success ? 'info' : 'warning');

        // Best-effort log into the admin email_log table (system send: sent_by NULL)
        try {
            $conn = getDbConnection();
            if ($conn) {
                $stmt = $conn->prepare('INSERT INTO email_log (registration_id, email_type, recipient_email, subject, body, sent_by, status) VALUES (?, ?, ?, ?, ?, NULL, ?)');
                if ($stmt) {
                    $regId = $registration['id'] ?? null;
                    $status = $success ? 'sent' : 'failed';
                    $stmt->bind_param('ssssss', $regId, $variant, $to, $mail['subject'], $mail['body'], $status);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } catch (Throwable $logErr) {
            logActivity('email_log insert skipped: ' . $logErr->getMessage(), 'warning');
        }

        return (bool)$success;
    } catch (Throwable $e) {
        logActivity('Applicant email exception: ' . $e->getMessage(), 'warning');
        return false;
    }
}
```

Note on the email_log insert: 6 `?` placeholders, types `ssssss`.
`email_log.registration_id` is INT in the admin schema while
registrations.id is a UUID string — binding as `s` lets MySQL coerce; if the
insert errors (missing table, enum value, type), it is caught and logged,
never fatal. (The enum migration is Task 3.)

- [ ] **Step 4: Run test to verify it passes**

```bash
php frontend/intake/test/test_registration_email.php
php -l frontend/intake/mailer.php
```
Expected: all PASS (15 checks), exit 0; lint clean.

- [ ] **Step 5: Commit**

```bash
git add frontend/intake/mailer.php frontend/intake/test/test_registration_email.php
git commit -m "feat: applicant email builder and sender for intake service"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 3: Wire into register.php and payment-ipn.php + email_log migration

**Files:**
- Modify: `frontend/intake/register.php` (success path, after line ~317 `logActivity("Registration submitted: ID=$id...`)
- Modify: `frontend/intake/payment-ipn.php` (registration SELECT ~line 53, paid branch ~line 179)
- Create: `frontend/intake/migrations/extend_email_log_types.sql`

- [ ] **Step 1: Send from register.php**

In `frontend/intake/register.php`, add `require_once __DIR__ . '/mailer.php';` with the other requires at the top. Then after:

```php
    logActivity("Registration submitted: ID=$id, Email={$data['email']}, IP=$ipHash");
```

insert:

```php
    // Automated applicant email: confirmation if a payment receipt was
    // attached, otherwise an application receipt with payment options.
    // A mail failure must never affect the registration response.
    $emailVariant = $receipt ? 'payment_confirmation' : 'submission_receipt';
    sendRegistrationEmail([
        'id' => $id,
        'full_name' => $data['full_name'],
        'email' => $data['email'],
        'mobile' => $data['mobile'],
        'address' => $data['address'],
        'dob' => $data['dob'],
        'nationality' => $data['nationality'],
        'id_document_type' => $data['id_document_type'],
        'id_document_number' => $data['id_document_number'],
        'exam_level' => $data['exam_level'],
        'test_date' => $data['test_date'],
        'total_amount' => $totalAmountPaidValue,
        'payment_method' => $data['payment_method'],
        'payment_status' => 'unpaid',
        'retry_token' => $retryTokenValue,
        'has_receipt' => (bool)$receipt,
        'bank_tran_id' => '',
    ], $emailVariant);
```

(`$receipt`, `$totalAmountPaidValue`, `$retryTokenValue` already exist in scope at that point. Note: `$conn->close()` has already run — `sendRegistrationEmail`'s email_log insert reopens via `getDbConnection()`, whose static singleton returns the closed handle; if the insert fails for that reason it is caught and logged. Acceptable: the email still sends.)

- [ ] **Step 2: Widen the IPN registration SELECT**

In `frontend/intake/payment-ipn.php`, change:

```php
        SELECT id, email, full_name, total_amount_paid, payment_status
        FROM registrations
        WHERE sslcommerz_transaction_id = ?
```

to:

```php
        SELECT id, email, full_name, mobile, address, dob, nationality,
               id_document_type, id_document_number, exam_level, test_date,
               total_amount_paid, payment_method, payment_status,
               payment_retry_token
        FROM registrations
        WHERE sslcommerz_transaction_id = ?
```

- [ ] **Step 3: Send the paid confirmation from the IPN**

Add `require_once __DIR__ . '/mailer.php';` next to the existing requires at the top of payment-ipn.php. Then replace:

```php
    // Send confirmation email for successful payments
    if ($newStatus === 'paid') {
        // Email will be sent by admin review process
        logActivity("Payment confirmation queued for transaction {$transactionId}");
    }
```

with:

```php
    // Send confirmation email for successful payments
    if ($newStatus === 'paid') {
        sendRegistrationEmail([
            'id' => $registration['id'],
            'full_name' => $registration['full_name'],
            'email' => $registration['email'],
            'mobile' => $registration['mobile'],
            'address' => $registration['address'],
            'dob' => $registration['dob'],
            'nationality' => $registration['nationality'],
            'id_document_type' => $registration['id_document_type'],
            'id_document_number' => $registration['id_document_number'],
            'exam_level' => $registration['exam_level'],
            'test_date' => $registration['test_date'],
            'total_amount' => $registration['total_amount_paid'],
            'payment_method' => $registration['payment_method'],
            'payment_status' => 'paid',
            'retry_token' => $registration['payment_retry_token'],
            'has_receipt' => false,
            'bank_tran_id' => $bankTranId,
        ], 'payment_confirmation');
    }
```

⚠️ The IPN closes `$conn` BEFORE this branch (`$conn->close()` around line 175). Move the email block ABOVE the `$conn->close();` line, or accept the email_log degradation — prefer moving it above the close so email_log works. The UPDATE has already executed at that point, so the email reflects committed state.

- [ ] **Step 4: Migration for email_log enum**

Create `frontend/intake/migrations/extend_email_log_types.sql`:

```sql
-- Allow system-sent applicant emails in the admin email_log.
-- Run ONLY where the admin schema (email_log table) is installed:
--   mysql -u nattest_reg -p nattest_regs < extend_email_log_types.sql
-- The intake mailer degrades gracefully if this has not been run.

ALTER TABLE email_log
MODIFY email_type ENUM('confirmation', 'rejection', 'admission_ticket', 'resend',
                       'submission_receipt', 'payment_confirmation') NOT NULL;

-- System-sent emails have no admin user
ALTER TABLE email_log
MODIFY sent_by INT NULL;
```

- [ ] **Step 5: Lint + tests + commit**

```bash
php -l frontend/intake/register.php && php -l frontend/intake/payment-ipn.php
php frontend/intake/test/test_registration_email.php
php frontend/intake/test/test_id_document_fields.php
git add frontend/intake/register.php frontend/intake/payment-ipn.php frontend/intake/migrations/extend_email_log_types.sql
git commit -m "feat: send applicant emails on submission and on confirmed payment"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 4: Runtime verification (controller runs this — local DB + browser)

**Files:** none (verification only; temporary `frontend/intake/.env`)

- [ ] **Step 1: Stand up the local environment**

```bash
brew services start mariadb
cat > frontend/intake/.env <<'EOF'
# LOCAL VERIFICATION ONLY — delete after testing. Never deploy.
DB_HOST=localhost
DB_NAME=nattest_regs
DB_USER=nattest_reg
DB_PASS=localtest123
IP_SALT=local_test_salt
ALLOWED_ORIGINS=*
APP_ENV=development
SITE_URL=http://localhost:8096
SSLCZ_STORE_ID=localtest_fake_store
SSLCZ_STORE_PASSWORD=localtest_fake_password
SSLCZ_MODE=sandbox
EOF
```

- [ ] **Step 2: Exercise each trigger via curl** (multipart submissions per the
patterns used in the 2026-06-12 registration-flow verification: offline
without receipt, offline with `payment_receipt` file, online). After each,
check `frontend/intake/logs/activity.log` for `Applicant email sent` /
`Applicant email FAILED` lines (no local MTA → FAILED is the expected
graceful outcome) and confirm the HTTP responses are unchanged (200 with
the same JSON shapes as before).

- [ ] **Step 3: IPN paid email attempt** — give a row a transaction ID, send a
correctly-signed FAILED IPN first (no email expected), then verify the paid
path can only be fully exercised in deployment (validation API needs real
credentials); confirm by code-trace that the email block sits before
`$conn->close()`.

- [ ] **Step 4: Clean up and report**

```bash
rm frontend/intake/.env
```
Report results; deployment notes: run `extend_email_log_types.sql` where the
admin schema exists; deploy mailer.php + register.php + payment-ipn.php +
config.php + the two `/resources/` QR assets together; set `MAIL_FROM` env
if a different sender is wanted; the first real deployment test should
include checking spam placement of no-reply@nat-test.ku.ac.bd mail.
