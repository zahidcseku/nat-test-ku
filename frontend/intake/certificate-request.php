<?php
/**
 * Certificate Request — Create + Initiate Payment
 *
 * Re-verifies eligibility, dedupes, captures a structured shipping
 * address, generates an SSLCommerz session for 200 BDT, inserts a
 * certificate_requests row with payment_status='unpaid', and returns
 * the gateway redirect URL. Mirrors register.php's payment flow.
 *
 * Transaction IDs use the 'CRT' prefix so the IPN handler can route
 * certificate payments separately from registration payments ('NAT').
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lookup-lib.php';

// Initialize security (rate limiting, headers, multipart enforcement)
initSecurity();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

/**
 * Certificate fee is fixed at 200 BDT (postage + handling).
 */
function certificateFee(): float {
    return 200.00;
}

try {
    // Read input (strings only — arrays are coerced to '' by is_string checks).
    $registrationId = is_string($_POST['registration_id'] ?? null) ? trim($_POST['registration_id']) : '';
    $examDateId     = is_string($_POST['exam_date_id']     ?? null) ? trim($_POST['exam_date_id'])    : '';
    $xlsxId         = is_string($_POST['xlsx_id']         ?? null) ? trim($_POST['xlsx_id'])         : '';

    $recipientName  = is_string($_POST['recipient_name']  ?? null) ? trim($_POST['recipient_name'])  : '';
    $recipientPhone = is_string($_POST['recipient_phone'] ?? null) ? trim($_POST['recipient_phone']) : '';
    $houseStreet    = is_string($_POST['house_street']    ?? null) ? trim($_POST['house_street'])    : '';
    $areaThana      = is_string($_POST['area_thana']      ?? null) ? trim($_POST['area_thana'])      : '';
    $district       = is_string($_POST['district']        ?? null) ? trim($_POST['district'])        : '';
    $postalCode     = is_string($_POST['postal_code']     ?? null) ? trim($_POST['postal_code'])     : '';

    // Basic format gate — keeps malformed input out of the DB.
    $uuidOk = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $registrationId) === 1
           && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $examDateId) === 1;
    $xlsxIdOk = strlen($xlsxId) >= 4 && strlen($xlsxId) <= 50
        && preg_match('/^[A-Za-z0-9._-]+$/', $xlsxId) === 1;
    $phoneOk = preg_match('/^(\+?880|0)?1[3-9]\d{8}$/', $recipientPhone) === 1;
    $lengthOk = strlen($recipientName) >= 2 && strlen($recipientName) <= 200
        && strlen($houseStreet) >= 3 && strlen($houseStreet) <= 300
        && strlen($areaThana) >= 2 && strlen($areaThana) <= 200
        && strlen($district) >= 2 && strlen($district) <= 100
        && strlen($postalCode) <= 20;

    if (!$uuidOk || !$xlsxIdOk || !$phoneOk || !$lengthOk) {
        logActivity('Certificate request rejected: invalid input from ' . hashIp(getClientIp()), 'info');
        errorResponse('Please check the address fields and try again.', 422);
    }

    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Service temporarily unavailable', 500);
    }

    // Re-verify eligibility server-side. Never trust the client.
    $eligStmt = $conn->prepare("
        SELECT r.id, r.email, r.full_name
        FROM registrations r
        INNER JOIN registration_sheet_numbers rsn ON rsn.registration_id = r.id
        INNER JOIN score_reports sr ON sr.reg_no = rsn.reg_no AND sr.exam_date_id = ?
        WHERE r.id = ? AND sr.xlsx_id = ?
        LIMIT 1
    ");
    if (!$eligStmt) {
        logActivity('Prepare failed (certificate-request elig): ' . $conn->error, 'error');
        errorResponse('Service temporarily unavailable', 500);
    }
    $eligStmt->bind_param('sss', $examDateId, $registrationId, $xlsxId);
    $eligStmt->execute();
    $eligRow = $eligStmt->get_result()->fetch_assoc();
    $eligStmt->close();

    if (!$eligRow) {
        logActivity('Certificate request eligibility failed for xlsx_id=' . $xlsxId, 'warning');
        errorResponse('Eligibility check failed. Please contact the test center.', 403);
    }

    // Dedupe: already a paid request for this (registration, exam_date)?
    $dupStmt = $conn->prepare("
        SELECT id, payment_status, certificate_status
        FROM certificate_requests
        WHERE registration_id = ? AND exam_date_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    if ($dupStmt) {
        $dupStmt->bind_param('ss', $registrationId, $examDateId);
        $dupStmt->execute();
        $dup = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();

        if ($dup && $dup['payment_status'] === 'paid' && $dup['certificate_status'] === 'requested') {
            logActivity('Duplicate certificate request blocked for registration ' . $registrationId, 'info');
            errorResponse('You have already requested a certificate for this exam.', 409);
        }
        // unpaid/failed rows: treat as retry — fall through and create a new request.
        // (Old unpaid rows linger for audit but do not block a fresh attempt.)
    }

    // Generate IDs.
    $id = generateUuid();
    $sslczTranId = 'CRT' . date('YmdHis') . substr(md5(uniqid($id, true)), 0, 8);

    // Build SSLCommerz session.
    require_once __DIR__ . '/payment-gateway.php';
    $sslcz = new SSLCommerz();

    $totalAmount = certificateFee();

    $sslczData = [
        'total_amount' => $totalAmount,
        'currency'     => 'BDT',
        'tran_id'      => $sslczTranId,
        'cus_name'     => $recipientName,
        'cus_email'    => $eligRow['email'],
        'cus_phone'    => $recipientPhone,
        'cus_add1'     => $houseStreet . ', ' . $areaThana . ', ' . $district,
    ];

    $sslczSessionId = null;
    $redirectUrl = null;

    try {
        $response = $sslcz->createPayment($sslczData);
        if ($response['status'] === 'SUCCESS') {
            $sslczSessionId = $response['sessionkey'];
            $redirectUrl    = $response['GatewayPageURL'];
            logActivity("SSLCommerz session created for certificate request {$id}");
        } else {
            logActivity('SSLCommerz session creation failed (certificate): ' . $response['error'], 'error');
            errorResponse('Payment gateway unavailable. Please try again in a few minutes.', 503);
        }
    } catch (Throwable $gwErr) {
        logActivity('SSLCommerz exception (certificate): ' . $gwErr->getMessage(), 'error');
        errorResponse('Payment gateway unavailable. Please try again in a few minutes.', 503);
    }

    // Insert certificate_requests row.
    $ipHash = hashIp(getClientIp());
    $amountStr = (string)$totalAmount; // DECIMAL bind

    $insert = $conn->prepare("
        INSERT INTO certificate_requests (
            id, registration_id, exam_date_id, xlsx_id,
            recipient_name, recipient_phone, house_street, area_thana, district, postal_code,
            amount, payment_status, sslcommerz_transaction_id, sslcommerz_session_id,
            ip_address
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?)
    ");
    if (!$insert) {
        logActivity('Prepare failed (certificate insert): ' . $conn->error, 'error');
        errorResponse('Service temporarily unavailable', 500);
    }

    $insert->bind_param(
        'ssssssssssdsss',
        $id,
        $registrationId,
        $examDateId,
        $xlsxId,
        $recipientName,
        $recipientPhone,
        $houseStreet,
        $areaThana,
        $district,
        $postalCode,
        $amountStr,
        $sslczTranId,
        $sslczSessionId,
        $ipHash
    );

    if (!$insert->execute()) {
        logActivity('Certificate insert failed: ' . $insert->error, 'error');
        errorResponse('Failed to save certificate request', 500);
    }
    $insert->close();

    logActivity('Certificate request created: id=' . $id . ', xlsx_id=' . $xlsxId . ', tran_id=' . $sslczTranId);

    successResponse([
        'id'           => $id,
        'redirect_url' => $redirectUrl,
    ], 'Certificate request initiated');

} catch (Throwable $e) {
    logActivity('Certificate request exception: ' . $e->getMessage(), 'error');
    errorResponse('Service temporarily unavailable', 500);
}
