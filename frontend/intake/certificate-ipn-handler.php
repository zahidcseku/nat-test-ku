<?php
/**
 * Certificate Payment IPN Handler (included from payment-ipn.php).
 *
 * Same lifecycle as the registrations IPN flow but targets the
 * certificate_requests table. Payment-ipn.php already verified the
 * SSLCommerz signature before dispatch — we only handle routing and
 * the table-specific update + email here.
 *
 * On VALID/VALIDATED: server-side validation via SSLCommerz API,
 * amount check (200 BDT ±1 tolerance), mark paid, fire the
 * certificate_requested email. On FAILED/CANCELLED/etc: mark failed.
 *
 * Idempotent: if the row is already 'paid', returns success without
 * re-processing.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

/**
 * @param array    $ipnData     Raw $_POST from SSLCommerz
 * @param SSLCommerz $sslcz     Initialized gateway (for validateTransaction)
 * @param string   $transactionId  tran_id (guaranteed 'CRT' prefix)
 * @param string   $status      IPN status string
 * @param string   $bankTranId  bank_tran_id from IPN
 * @param string   $currency    Currency from IPN
 * @param string   $cardType    Card type from IPN
 */
function handleCertificateIPN(array $ipnData, $sslcz, string $transactionId, string $status, string $bankTranId, string $currency, string $cardType): void {

    $conn = getDbConnection();
    if (!$conn) {
        logActivity('Database connection failed in certificate IPN handler', 'error');
        errorResponse('Database error', 500);
    }

    // Find certificate row by transaction id.
    $stmt = $conn->prepare("
        SELECT id, registration_id, exam_date_id, reg_no, amount,
               recipient_name, recipient_phone, house_street, area_thana,
               district, postal_code, payment_status, sslcommerz_bank_transaction_id
        FROM certificate_requests
        WHERE sslcommerz_transaction_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        logActivity('Prepare failed (cert IPN): ' . $conn->error, 'error');
        errorResponse('Database error', 500);
    }
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $cert = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cert) {
        logActivity('Certificate not found for tran_id: ' . $transactionId, 'warning');
        errorResponse('Certificate request not found', 404);
    }

    // Idempotency.
    if ($cert['payment_status'] === 'paid') {
        logActivity('Duplicate cert IPN for already-paid transaction: ' . $transactionId, 'info');
        successResponse([], 'Already processed');
    }

    $newStatus = 'unpaid';

    if ($status === 'VALID' || $status === 'VALIDATED') {
        $valId = $ipnData['val_id'] ?? '';
        if ($valId === '') {
            logActivity('Cert IPN missing val_id for ' . $transactionId, 'security');
            errorResponse('Missing validation ID', 400);
        }

        $validation = $sslcz->validateTransaction($valId);
        if ($validation['status'] !== 'VALID' && $validation['status'] !== 'VALIDATED') {
            logActivity('Cert validation rejected ' . $transactionId . ': ' . $validation['status'], 'security');
            errorResponse('Transaction validation failed', 400);
        }
        if ($validation['tran_id'] !== $transactionId) {
            logActivity('Cert validation tran_id mismatch: IPN=' . $transactionId . ' API=' . $validation['tran_id'], 'security');
            errorResponse('Transaction validation failed', 400);
        }
        $validatedCurrency = $validation['currency'] !== '' ? $validation['currency'] : $currency;
        if ($validatedCurrency !== 'BDT') {
            logActivity('Unexpected currency for cert ' . $transactionId . ': ' . $validatedCurrency, 'security');
            errorResponse('Currency validation failed', 400);
        }

        // Amount check against the stored amount (1 BDT tolerance).
        if (abs((float)$validation['amount'] - (float)$cert['amount']) > 1.0) {
            logActivity('Amount mismatch for cert ' . $transactionId . ': expected=' . $cert['amount'] . ' validated=' . $validation['amount'], 'security');
            errorResponse('Amount validation failed', 400);
        }

        $bankTranId = $validation['bank_tran_id'] !== '' ? $validation['bank_tran_id'] : $bankTranId;
        $newStatus = 'paid';

        logActivity('Certificate payment verified for ' . $transactionId . ' amount=' . $validation['amount']);

    } elseif (in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED', 'UNATTEMPTED'], true)) {
        $newStatus = 'failed';
        logActivity('Certificate payment ' . $status . ' for ' . $transactionId);
    } else {
        logActivity('Unknown cert payment status: ' . $status . ' for ' . $transactionId, 'warning');
        errorResponse('Unknown payment status', 400);
    }

    // Update certificate_requests.
    $update = $conn->prepare("
        UPDATE certificate_requests
        SET payment_status = ?,
            sslcommerz_bank_transaction_id = ?,
            payment_time = NOW()
        WHERE id = ?
          AND payment_status <> 'paid'
    ");
    if (!$update) {
        logActivity('Prepare failed (cert IPN update): ' . $conn->error, 'error');
        errorResponse('Database error', 500);
    }
    $update->bind_param('sss', $newStatus, $bankTranId, $cert['id']);
    if (!$update->execute()) {
        logActivity('Cert IPN update failed: ' . $update->error, 'error');
        errorResponse('Database update failed', 500);
    }
    $update->close();

    // Fire certificate_requested email on success.
    if ($newStatus === 'paid') {
        // Look up the examinee's full_name + exam_date for the email.
        $joinStmt = $conn->prepare("
            SELECT r.full_name, ed.exam_date
            FROM registrations r
            LEFT JOIN exam_dates ed ON ed.id = ?
            WHERE r.id = ?
            LIMIT 1
        ");
        $fullName = '';
        $examDateRaw = '';
        if ($joinStmt) {
            $joinStmt->bind_param('ss', $cert['exam_date_id'], $cert['registration_id']);
            $joinStmt->execute();
            $j = $joinStmt->get_result()->fetch_assoc();
            $joinStmt->close();
            if ($j) {
                $fullName = html_entity_decode($j['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
                $examDateRaw = $j['exam_date'] ?? '';
            }
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', $examDateRaw);
        $examDateDisplay = $dateObj ? $dateObj->format('F j, Y') : $examDateRaw;

        // Recipient email: pull fresh from registrations (the request row
        // doesn't store email — it has recipient_name but not email).
        $emailStmt = $conn->prepare("SELECT email FROM registrations WHERE id = ? LIMIT 1");
        $recipientEmail = '';
        if ($emailStmt) {
            $emailStmt->bind_param('s', $cert['registration_id']);
            $emailStmt->execute();
            $er = $emailStmt->get_result()->fetch_assoc();
            $emailStmt->close();
            $recipientEmail = $er['email'] ?? '';
        }

        sendCertificateEmail('certificate_requested', $recipientEmail, [
            'id'              => $cert['id'],
            'registration_id' => $cert['registration_id'],
            'full_name'       => $fullName,
            'reg_no'          => $cert['reg_no'],
            'exam_date'       => $examDateDisplay,
            'recipient_name'  => $cert['recipient_name'],
            'recipient_phone' => $cert['recipient_phone'],
            'house_street'    => $cert['house_street'],
            'area_thana'      => $cert['area_thana'],
            'district'        => $cert['district'],
            'postal_code'     => $cert['postal_code'] ?? '',
            'bank_tran_id'    => $bankTranId,
        ]);
    }

    logActivity('Certificate IPN processed for ' . $transactionId . ' -> ' . $newStatus);

    successResponse([
        'transaction_id' => $transactionId,
        'status'         => $newStatus,
    ], 'Certificate IPN processed');
}
