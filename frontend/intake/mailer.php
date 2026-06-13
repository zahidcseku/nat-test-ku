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
            $subject = 'NAT-TEST Registration - Payment Received';
        } else {
            $banner = '<p style="font-size:15px;background:#fef7e0;border-left:4px solid #f9ab00;padding:10px 14px;">'
                . '<strong>Payment receipt received — pending verification.</strong> '
                . 'We received your payment receipt with your application; our team will verify it during review.</p>';
            $subject = 'NAT-TEST Registration Received - Receipt Pending Verification';
        }
        $middle = $banner;
    } else {
        $subject = 'NAT-TEST Application Received - Payment Required';
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
 * Send one message over authenticated SMTP (STARTTLS by default).
 *
 * Used when SMTP_HOST/SMTP_USER/SMTP_PASS are configured — required for
 * deliverability because nat-test.ku.ac.bd's mail is Google-hosted and the
 * web server is not an authorized sender (no SPF/DKIM), so plain mail()
 * gets rejected by Gmail. SMTP_SECURE: 'starttls' (default), 'ssl'
 * (implicit TLS, port 465), or 'none' (local testing only).
 *
 * @return bool True if the server accepted the message
 */
function smtpSendMail(string $to, string $subject, string $body, string $fromEmail): bool {
    $host = getenv('SMTP_HOST');
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $user = getenv('SMTP_USER');
    $pass = getenv('SMTP_PASS');
    $secure = strtolower(getenv('SMTP_SECURE') ?: 'starttls');

    if (!$host || !$user || !$pass) {
        return false;
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) {
        logActivity("SMTP connect to {$host}:{$port} failed: {$errstr}", 'warning');
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = static function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $data;
    };
    $cmd = static function (?string $c, int $expect) use ($fp, $read) {
        if ($c !== null) {
            fwrite($fp, $c . "\r\n");
        }
        $resp = $read();
        if (strpos($resp, (string)$expect) !== 0) {
            throw new RuntimeException('SMTP unexpected response: ' . trim(substr($resp, 0, 200)));
        }
        return $resp;
    };

    try {
        $cmd(null, 220);
        $cmd('EHLO nat-test.ku.ac.bd', 250);

        if ($secure === 'starttls') {
            $cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS negotiation failed');
            }
            $cmd('EHLO nat-test.ku.ac.bd', 250);
        }

        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode($user), 334);
        $cmd(base64_encode($pass), 235);
        $cmd('MAIL FROM:<' . $fromEmail . '>', 250);
        $cmd('RCPT TO:<' . $to . '>', 250);
        $cmd('DATA', 354);

        $headers = 'From: ' . $fromEmail . "\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n";
        // Dot-stuffing per RFC 5321
        $stuffed = preg_replace('/^\./m', '..', $body);
        $cmd($headers . "\r\n" . $stuffed . "\r\n.", 250);
        $cmd('QUIT', 221);
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        logActivity('SMTP send failed: ' . $e->getMessage(), 'warning');
        if (is_resource($fp)) {
            @fclose($fp);
        }
        return false;
    }
}

/**
 * Build and send an applicant email. Never throws.
 *
 * @return bool True if the transport accepted the message
 */
function sendRegistrationEmail(array $registration, string $variant): bool {
    try {
        $mail = buildRegistrationEmail($registration, $variant);
        $to = $registration['email'] ?? '';
        if ($to === '') {
            logActivity('Applicant email skipped: no recipient address', 'warning');
            return false;
        }

        // Authenticated SMTP when configured (required for Gmail
        // deliverability); plain mail() as the unconfigured fallback
        if (getenv('SMTP_HOST') && getenv('SMTP_USER') && getenv('SMTP_PASS')) {
            $success = smtpSendMail($to, $mail['subject'], $mail['body'], MAIL_FROM);
        } else {
            $headers = 'From: ' . MAIL_FROM . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n";
            $success = @mail($to, $mail['subject'], $mail['body'], $headers);
        }

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
