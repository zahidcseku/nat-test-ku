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
