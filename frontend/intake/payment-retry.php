<?php
/**
 * Payment Retry Lookup Endpoint
 *
 * Shows payment status for a registration and, for verified requesters, a
 * retry link. Two access paths, both POST-only (multipart) behind
 * initSecurity() — rate limited, generic errors, attempt logging:
 *
 *  - token: the retry token from the applicant's emailed retry link
 *    (bearer by possession — display only; tokens are never minted or
 *    re-issued through this path)
 *  - email + dob: both must match the registration, mirroring the
 *    application-lookup posture. This replaced the old email-only and
 *    registration-id lookups, which let anyone enumerate applicants by
 *    email or sequential ID and mint bearer retry tokens.
 *
 * The raw retry token is never included in any response.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

// POST-only, multipart, rate limited (5/min, 20/day), security headers
initSecurity();

try {
    $email    = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $dobInput = is_string($_POST['dob'] ?? null) ? trim($_POST['dob']) : '';
    $token    = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Service temporarily unavailable', 500);
    }

    if ($token !== '') {
        // Direct retry-link flow
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            errorResponse('Invalid or expired retry link', 404);
        }
        $stmt = $conn->prepare("
            SELECT id, full_name, email, base_amount, transaction_fee, total_amount_paid,
                   payment_status, payment_retry_token, payment_retry_expires
            FROM registrations
            WHERE payment_retry_token = ?
        ");
        $stmt->bind_param('s', $token);
    } else {
        // Verified lookup flow: email + dob must both match
        $dobOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dobInput, $m)
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);

        if ($email === '' || !$dobOk) {
            logActivity('Payment retry lookup rejected: incomplete input from ' . hashIp(getClientIp()), 'info');
            errorResponse('No registration found with those details', 404);
        }

        $stmt = $conn->prepare("
            SELECT id, full_name, email, base_amount, transaction_fee, total_amount_paid,
                   payment_status, payment_retry_token, payment_retry_expires
            FROM registrations
            WHERE LOWER(email) = LOWER(?) AND dob = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->bind_param('ss', $email, $dobInput);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        logActivity('Payment retry lookup: no match for ' . hashIp(getClientIp()), 'info');
        errorResponse('No registration found with those details', 404);
    }

    $registration = $result->fetch_assoc();
    $stmt->close();

    $canRetry = in_array($registration['payment_status'], ['unpaid', 'failed'], true);
    $retryLink = null;
    $linkExpired = false;

    if ($canRetry) {
        $hasToken  = !empty($registration['payment_retry_token']);
        $tokenLive = $hasToken && strtotime($registration['payment_retry_expires']) > time();

        if ($token !== '') {
            // Token flow: display only. If the link expired, tell the
            // applicant to look up their registration with email + dob to
            // get a fresh link — never mint one for an anonymous caller.
            if (!$tokenLive) {
                $linkExpired = true;
            }
        } elseif ($hasToken) {
            // Verified email+dob requester may receive — or renew — the link
            $activeToken = $registration['payment_retry_token'];
            if (!$tokenLive) {
                $activeToken = generateRetryToken();
                $newExpiry = generateRetryExpiry();
                $updateStmt = $conn->prepare("
                    UPDATE registrations
                    SET payment_retry_token = ?, payment_retry_expires = ?
                    WHERE id = ?
                ");
                $updateStmt->bind_param('sss', $activeToken, $newExpiry, $registration['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
            $retryLink = SITE_URL . '/payment-retry.html?token=' . $activeToken;
        }
    }
    $conn->close();

    successResponse([
        'found'           => true,
        'registration_id' => $registration['id'],
        // Stored text is HTML-encoded by registration sanitization; decode
        // for display — the client renders with textContent only
        'full_name'       => html_entity_decode($registration['full_name'], ENT_QUOTES, 'UTF-8'),
        'email'           => $registration['email'],
        'base_amount'     => (float)$registration['base_amount'],
        'transaction_fee' => (float)$registration['transaction_fee'],
        'total_amount'    => (float)$registration['total_amount_paid'],
        'payment_status'  => $registration['payment_status'],
        'can_retry'       => $canRetry,
        'retry_link'      => $retryLink,
        'link_expired'    => $linkExpired,
        'expires_at'      => $registration['payment_retry_expires'],
    ], 'Registration found');

} catch (Throwable $e) {
    logActivity('Payment retry lookup exception: ' . $e->getMessage(), 'error');
    errorResponse('Service temporarily unavailable', 500);
}
