<?php
/**
 * NAT-TEST Intake Service - Registration Endpoint
 *
 * Main POST endpoint for receiving and storing registration applications.
 * Integrates validation, security, file uploads, and database storage.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lookup-lib.php';

// Initialize security
initSecurity();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    // Validate upload directory
    $dirValidation = validateUploadDirectory();
    if (!$dirValidation['valid']) {
        logActivity("Upload directory error: " . $dirValidation['error'], 'error');
        errorResponse('Server configuration error', 500);
    }

    // Get request data
    $postData = $_POST ?? [];
    $filesData = $_FILES ?? [];

    // Check honeypot
    $honeypotCheck = checkHoneypot($postData);
    if (!$honeypotCheck['valid']) {
        // Return success but don't actually process (honeypot tripped)
        logActivity("Honeypot triggered from IP: " . getRequestIp(), 'warning');
        successResponse([
            'id' => generateUuid()
        ], 'Registration submitted successfully');
    }

    // Validate CSRF token (optional, can be enabled later)
    // $csrfToken = $postData['csrf_token'] ?? '';
    // if (!validateCsrfToken($csrfToken)) {
    //     errorResponse('Invalid CSRF token', 403);
    // }

    // Validate form data
    $validation = validateRegistrationData($postData);
    if (!$validation['valid']) {
        logActivity("Validation failed for IP: " . getRequestIp(), 'warning');
        errorResponse('Validation failed', 400, $validation['errors']);
    }

    $data = $validation['data'];

    // ============================================
    // REGISTRATION CAP ENFORCEMENT (per exam_date + level)
    // ============================================
    // Block new submissions for any selected (date, level) that has already
    // reached its cap of PAID registrations. Fail-open: a query error must
    // never block a legitimate registration. Runs before file uploads so a
    // full level doesn't cost the applicant a useless upload.
    try {
        $capConn = getDbConnection();
        if ($capConn && !empty($data['exam_levels_array'])) {
            $capStmt = $capConn->prepare("
                SELECT el.registration_cap,
                       (SELECT COUNT(*) FROM registrations r
                          WHERE r.test_date = ?
                            AND r.payment_status = 'paid'
                            AND FIND_IN_SET(?, r.exam_level)) AS paid_count
                  FROM exam_levels el
                  JOIN exam_dates ed ON ed.id = el.exam_date_id
                 WHERE ed.exam_date = ? AND el.level = ?
            ");
            if ($capStmt) {
                $fullLevels = [];
                foreach ($data['exam_levels_array'] as $level) {
                    $capStmt->bind_param('ssss', $data['test_date'], $level, $data['test_date'], $level);
                    $capStmt->execute();
                    $row = $capStmt->get_result()->fetch_assoc();
                    // Skip if (date, level) has no exam_levels row (not offered)
                    // or registration_cap is NULL (unlimited).
                    if ($row && $row['registration_cap'] !== null
                        && (int)$row['paid_count'] >= (int)$row['registration_cap']) {
                        $fullLevels[] = $level;
                    }
                }
                $capStmt->close();

                if (!empty($fullLevels)) {
                    $msg = 'Registration is full for ' . implode(', ', $fullLevels)
                         . ' on ' . $data['test_date']
                         . '. Please choose another date or level.';
                    logActivity('Cap blocked submission for ' . $data['email']
                        . ' on ' . $data['test_date'] . ': ' . implode(',', $fullLevels), 'warning');
                    jsonResponse(['success' => false, 'error' => $msg], 422);
                }
            }
        }
    } catch (Throwable $e) {
        logActivity('Cap check query failed (fail-open): ' . $e->getMessage(), 'warning');
    }

    // Handle file uploads
    $uploadResult = handleFileUploads($filesData);

    if (!$uploadResult['success'] || !empty($uploadResult['errors'])) {
        // Clean up any uploaded files if there were errors
        foreach ($uploadResult['files'] as $file) {
            if (isset($file['storage_path'])) {
                deleteUploadedFile($file['storage_path']);
            }
        }

        errorResponse('File upload failed', 400, $uploadResult['errors']);
    }

    // ============================================
    // DUPLICATE GUARD
    // ============================================
    // Block re-submitting modules already registered for the same person
    // (email + DOB) and exam date. Prevents accidental double registration
    // (back-button re-submit, double-click). Runs before any gateway session.
    // Fail-open: a query error must never block a legitimate registration.
    try {
        $dupConn = getDbConnection();
        if ($dupConn) {
            $dupStmt = $dupConn->prepare("
                SELECT exam_level, payment_status, payment_retry_token
                FROM registrations
                WHERE LOWER(email) = LOWER(?) AND dob = ? AND test_date = ?
                ORDER BY submitted_at DESC
            ");
            $dupStmt->bind_param('sss', $data['email'], $data['dob'], $data['test_date']);
            $dupStmt->execute();
            $dupResult = $dupStmt->get_result();

            $paidOverlap = [];
            $unpaidOverlap = [];
            $unpaidRetryToken = null;
            while ($existing = $dupResult->fetch_assoc()) {
                $overlap = findModuleOverlap($data['exam_level'], $existing['exam_level']);
                if (empty($overlap)) {
                    continue;
                }
                if ($existing['payment_status'] === 'paid') {
                    $paidOverlap = array_values(array_unique(array_merge($paidOverlap, $overlap)));
                } elseif ($unpaidRetryToken === null) {
                    $unpaidOverlap = $overlap;
                    $unpaidRetryToken = $existing['payment_retry_token'] ?: null;
                }
            }
            $dupStmt->close();

            if (!empty($paidOverlap)) {
                logActivity("Duplicate blocked (paid overlap) for {$data['email']} on {$data['test_date']}: " . implode(',', $paidOverlap), 'warning');
                jsonResponse([
                    'success' => false,
                    'duplicate' => true,
                    'error' => 'You are already registered and paid for ' . implode(', ', $paidOverlap)
                        . ' on this test date. To add or change modules, email info@nat-test.ku.ac.bd.'
                ], 409);
            }

            if (!empty($unpaidOverlap)) {
                logActivity("Duplicate blocked (unpaid overlap) for {$data['email']} on {$data['test_date']}: " . implode(',', $unpaidOverlap), 'warning');
                $resp = [
                    'success' => false,
                    'duplicate' => true,
                    'error' => 'You already have an application for ' . implode(', ', $unpaidOverlap)
                        . ' on this test date that is not paid yet. Please complete that payment instead.'
                ];
                if ($unpaidRetryToken) {
                    $resp['payment_retry_url'] = SITE_URL . '/payment-retry.html?token=' . $unpaidRetryToken;
                }
                jsonResponse($resp, 409);
            }
        }
    } catch (Throwable $dupErr) {
        logActivity('Duplicate guard skipped (error): ' . $dupErr->getMessage(), 'warning');
    }

    // ============================================
    // PAYMENT GATEWAY INTEGRATION
    // ============================================

    // Generate registration ID up front so the payment session and its logs
    // can reference it
    $id = generateUuid();

    // Check if payment method is 'online'
    $paymentMethod = $data['payment_method'] ?? 'offline';
    $isOnlinePayment = ($paymentMethod === 'online');

    // Calculate payment amounts — charge is per selected exam level/module
    // (validate.php returns the selected levels as 'exam_levels_array')
    $levelCount = isset($data['exam_levels_array']) ? count($data['exam_levels_array']) : 1;
    $paymentAmounts = calculatePaymentAmount($levelCount, false);
    $baseAmount = $paymentAmounts['base'];
    $transactionFee = $paymentAmounts['fee'];
    $totalAmount = $paymentAmounts['total'];

    // Generate retry token
    $retryToken = generateRetryToken();
    $retryExpires = generateRetryExpiry();

    // If online payment, create SSLCommerz session
    $sslczTranId = null;
    $sslczSessionId = null;
    $redirectUrl = null;
    $gatewayUnavailable = false;

    if ($isOnlinePayment) {
        try {
            require_once __DIR__ . '/payment-gateway.php';
            $sslcz = new SSLCommerz();

            // Generate SSLCommerz-compatible transaction ID
            // Use timestamp + random suffix for uniqueness
            $sslczTranId = 'NAT' . date('YmdHis') . substr(md5(uniqid($id, true)), 0, 8);

            // Prepare SSLCommerz payment data
            $sslczData = [
                'total_amount' => $totalAmount,
                'currency' => 'BDT',
                'tran_id' => $sslczTranId,
                'cus_name' => $data['full_name'],
                'cus_email' => $data['email'],
                'cus_phone' => $data['mobile'],
                'cus_add1' => $data['address']
            ];

            // Create SSLCommerz session
            $sslczResponse = $sslcz->createPayment($sslczData);

            if ($sslczResponse['status'] === 'SUCCESS') {
                $sslczSessionId = $sslczResponse['sessionkey'];
                $redirectUrl = $sslczResponse['GatewayPageURL'];
                logActivity("SSLCommerz session created for registration {$id}");
            } else {
                // SSLCommerz session creation failed, save as unpaid anyway
                logActivity("SSLCommerz session creation failed: " . $sslczResponse['error'], 'error');
                $isOnlinePayment = false; // Fallback to offline
                $sslczTranId = null; // No gateway session exists for this ID
                $gatewayUnavailable = true;
            }

        } catch (Exception $e) {
            logActivity("SSLCommerz exception: " . $e->getMessage(), 'error');
            $isOnlinePayment = false; // Fallback to offline
            $sslczTranId = null; // No gateway session exists for this ID
            $gatewayUnavailable = true;
        }
    }

    // ============================================
    // END PAYMENT GATEWAY INTEGRATION
    // ============================================

    // Get database connection
    $conn = getDbConnection();
    if (!$conn) {
        logActivity("Database connection failed", 'error');
        errorResponse('Database connection failed', 500);
    }

    // Prepare data for database
    $ipHash = hashIp(getClientIp());
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Insert into database
    $stmt = $conn->prepare("
        INSERT INTO registrations (
            id, full_name, email, mobile, address, dob, gender, nationality,
            payment_method, exam_level, total_amount, test_date,
            photo_filename, photo_storage_path, photo_size_bytes,
            id_filename, id_storage_path, id_size_bytes,
            id_document_type, id_document_number,
            payment_receipt_filename, payment_receipt_storage_path, payment_receipt_size_bytes,
            submitted_at, ip_hash, user_agent, honeypot_tripped, honeypot_value,
            approved, approved_at, approved_by, created_at,
            payment_status, sslcommerz_transaction_id, sslcommerz_session_id, base_amount, transaction_fee, total_amount_paid, payment_retry_token, payment_retry_expires
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        logActivity("Prepare statement failed: " . $conn->error, 'error');
        errorResponse('Database error', 500);
    }

    $photo = $uploadResult['files']['photo'];
    $idDoc = $uploadResult['files']['id_document'];
    $receipt = $uploadResult['files']['payment_receipt'] ?? null;

    // Prepare variables for binding (required for mysqli reference passing)
    $p_size = (int)$photo['size_bytes'];
    $id_size = (int)$idDoc['size_bytes'];
    $idDocType = $data['id_document_type'];
    $idDocNumber = $data['id_document_number'];
    $r_name = $receipt['filename'] ?? null;
    $r_path = $receipt['storage_path'] ?? null;
    $r_size = isset($receipt['size_bytes']) ? (int)$receipt['size_bytes'] : null;
    $hp_tripped = $honeypotCheck['tripped'] ? 1 : 0;

    // Additional fields for approval tracking
    $submitted_at = date('Y-m-d H:i:s');
    $approved = 0; // Not approved by default
    $approved_at = null;
    $approved_by = null;
    $created_at = date('Y-m-d H:i:s');

    // Payment gateway fields
    $payment_status = $isOnlinePayment ? 'unpaid' : 'unpaid'; // Both start unpaid
    $baseAmountValue = $baseAmount;
    $transactionFeeValue = $transactionFee;
    $totalAmountPaidValue = $isOnlinePayment ? $totalAmount : $baseAmount; // Online includes fee
    $sslczSessionIdValue = $sslczSessionId;
    $retryTokenValue = $retryToken;
    $retryExpiresValue = $retryExpires;

    $stmt->bind_param(
        'ssssssssssisssississssisssisissssssdddss',
        $id,
        $data['full_name'],
        $data['email'],
        $data['mobile'],
        $data['address'],
        $data['dob'],
        $data['gender'],
        $data['nationality'],
        $data['payment_method'],
        $data['exam_level'],
        $data['total_amount'],
        $data['test_date'],
        $photo['filename'],
        $photo['storage_path'],
        $p_size,
        $idDoc['filename'],
        $idDoc['storage_path'],
        $id_size,
        $idDocType,
        $idDocNumber,
        $r_name,
        $r_path,
        $r_size,
        $submitted_at,
        $ipHash,
        $userAgent,
        $hp_tripped,
        $honeypotCheck['value'],
        $approved,
        $approved_at,
        $approved_by,
        $created_at,
        $payment_status,
        $sslczTranId,
        $sslczSessionIdValue,
        $baseAmountValue,
        $transactionFeeValue,
        $totalAmountPaidValue,
        $retryTokenValue,
        $retryExpiresValue
    );

    $result = $stmt->execute();

    if (!$result) {
        logActivity("Execute failed: " . $stmt->error, 'error');

        // Clean up uploaded files
        deleteUploadedFile($photo['storage_path']);
        deleteUploadedFile($idDoc['storage_path']);
        if ($receipt) {
            deleteUploadedFile($receipt['storage_path']);
        }

        errorResponse('Failed to save registration', 500);
    }

    // Verify the insertion was successful by checking affected rows
    $affectedRows = $stmt->affected_rows;
    if ($affectedRows !== 1) {
        logActivity("Insert verification failed: Expected 1 row, got $affectedRows", 'error');

        // Clean up uploaded files
        deleteUploadedFile($photo['storage_path']);
        deleteUploadedFile($idDoc['storage_path']);
        if ($receipt) {
            deleteUploadedFile($receipt['storage_path']);
        }

        errorResponse('Failed to verify registration save', 500);
    }

    // Additional verification: Query the record to confirm it exists
    $verifyStmt = $conn->prepare("SELECT id FROM registrations WHERE id = ?");
    $verifyStmt->bind_param('s', $id);
    $verifyStmt->execute();
    $verifyResult = $verifyStmt->get_result();

    if ($verifyResult->num_rows !== 1) {
        logActivity("Post-insert verification failed: Record not found for ID $id", 'error');

        // Clean up uploaded files
        deleteUploadedFile($photo['storage_path']);
        deleteUploadedFile($idDoc['storage_path']);
        if ($receipt) {
            deleteUploadedFile($receipt['storage_path']);
        }

        $verifyStmt->close();
        errorResponse('Failed to verify registration in database', 500);
    }

    $verifyStmt->close();

    logActivity("✅ Registration verified in database: ID=$id, Email={$data['email']}");

    $stmt->close();

    // Log successful registration
    logActivity("Registration submitted: ID=$id, Email={$data['email']}, IP=$ipHash");

    // Automated applicant email: confirmation if a payment receipt was
    // attached, otherwise an application receipt with payment options.
    // Sent before $conn->close() so the email_log insert can reuse the
    // connection. A mail failure must never affect the registration response.
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

    $conn->close();

    // If online payment, redirect to SSLCommerz
    if ($isOnlinePayment && $redirectUrl) {
        // Return success response with redirect URL
        $responseData = [
            'id' => $id,
            'email' => $data['email'],
            'exam_level' => $data['exam_level'],
            'test_date' => $data['test_date'],
            'total_amount' => $totalAmountPaidValue,
            'payment_status' => 'unpaid',
            'redirect_url' => $redirectUrl,
            'message' => 'Registration saved. Redirecting to payment gateway...'
        ];

        if (isset($data['exam_levels_array']) && is_array($data['exam_levels_array'])) {
            $responseData['exam_levels'] = $data['exam_levels_array'];
            $responseData['level_count'] = count($data['exam_levels_array']);
        }

        successResponse($responseData, 'Registration submitted. Redirecting to payment gateway...');
    } else {
        // Offline payment - return success as usual
        $responseData = [
            'id' => $id,
            'email' => $data['email'],
            'exam_level' => $data['exam_level'],
            'test_date' => $data['test_date'],
            'total_amount' => $totalAmountPaidValue
        ];

        if (isset($data['exam_levels_array']) && is_array($data['exam_levels_array'])) {
            $responseData['exam_levels'] = $data['exam_levels_array'];
            $responseData['level_count'] = count($data['exam_levels_array']);
        }

        if ($gatewayUnavailable) {
            // User chose online payment but the gateway session could not be
            // created — give them a retry link so they can complete payment
            $responseData['payment_status'] = 'unpaid';
            $responseData['payment_retry_url'] = SITE_URL . '/payment-retry.html?token=' . $retryTokenValue;
            successResponse($responseData, 'Registration saved, but the payment gateway could not be reached. Please use the payment link to complete your payment.');
        }

        successResponse($responseData, 'Registration submitted successfully');
    }

} catch (Throwable $e) {
    // Throwable, not Exception: a TypeError/Error would otherwise escape
    // and die as a bare fatal with no JSON response
    logActivity("Exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}