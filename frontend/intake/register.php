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

// Initialize security
initSecurity();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    // DEBUG: Log incoming request
    $debugLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
        'post_fields' => array_keys($_POST ?? []),
        'files' => array_keys($_FILES ?? []),
        'file_count' => count($_FILES ?? []),
        'post_count' => count($_POST ?? [])
    ];
    file_put_contents(__DIR__ . '/logs/debug_request.json', json_encode($debugLog, JSON_PRETTY_PRINT));

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
        file_put_contents(__DIR__ . '/logs/debug_validation.json', json_encode(['valid' => false, 'errors' => $validation['errors']], JSON_PRETTY_PRINT));
        errorResponse('Validation failed', 400, $validation['errors']);
    }

    $data = $validation['data'];

    // DEBUG: Log validated data
    file_put_contents(__DIR__ . '/logs/debug_data.json', json_encode($data, JSON_PRETTY_PRINT));

    // Handle file uploads
    $uploadResult = handleFileUploads($filesData);
    file_put_contents(__DIR__ . '/logs/debug_upload.json', json_encode($uploadResult, JSON_PRETTY_PRINT));

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
    // PAYMENT GATEWAY INTEGRATION
    // ============================================

    // Check if payment method is 'online'
    $paymentMethod = $data['payment_method'] ?? 'offline';
    $isOnlinePayment = ($paymentMethod === 'online');

    // Calculate payment amounts
    $levelCount = isset($data['exam_levels']) ? count($data['exam_levels']) : 1;
    $paymentAmounts = calculatePaymentAmount($levelCount, false);
    $baseAmount = $paymentAmounts['base'];
    $transactionFee = $paymentAmounts['fee'];
    $totalAmount = $paymentAmounts['total'];

    // Generate retry token
    $retryToken = generateRetryToken();
    $retryExpires = generateRetryExpiry();

    // If online payment, create SSLCommerz session
    $sslczSessionId = null;
    $redirectUrl = null;

    if ($isOnlinePayment) {
        try {
            require_once __DIR__ . '/payment-gateway.php';
            $sslcz = new SSLCommerz();

            // Prepare SSLCommerz payment data
            $sslczData = [
                'total_amount' => $totalAmount,
                'currency' => 'BDT',
                'tran_id' => $id,
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
            }

        } catch (Exception $e) {
            logActivity("SSLCommerz exception: " . $e->getMessage(), 'error');
            $isOnlinePayment = false; // Fallback to offline
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
    $id = generateUuid();
    $ipHash = hashIp(getClientIp());
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Insert into database
    $stmt = $conn->prepare("
        INSERT INTO registrations (
            id, full_name, email, mobile, address, dob, gender, nationality,
            payment_method, exam_level, total_amount, test_date,
            photo_filename, photo_storage_path, photo_size_bytes,
            id_filename, id_storage_path, id_size_bytes,
            payment_receipt_filename, payment_receipt_storage_path, payment_receipt_size_bytes,
            submitted_at, ip_hash, user_agent, honeypot_tripped, honeypot_value,
            approved, approved_at, approved_by, created_at,
            payment_status, base_amount, transaction_fee, total_amount_paid, sslcommerz_session_id, payment_retry_token, payment_retry_expires
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        'ssssssssssisssissississsisisssdsss',
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
        $baseAmountValue,
        $transactionFeeValue,
        $totalAmountPaidValue,
        $sslczSessionIdValue,
        $retryTokenValue,
        $retryExpiresValue
    );

    $result = $stmt->execute();

    // DEBUG: Log database result
    file_put_contents(__DIR__ . '/logs/debug_db.json', json_encode([
        'success' => $result,
        'insert_id' => $id,
        'error' => $stmt->error,
        'data' => [
            'id' => $id,
            'email' => $data['email'],
            'exam_level' => $data['exam_level']
        ]
    ], JSON_PRETTY_PRINT));

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
    $conn->close();

    // Log successful registration
    logActivity("Registration submitted: ID=$id, Email={$data['email']}, IP=$ipHash");

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

        if (isset($data['exam_levels']) && is_array($data['exam_levels'])) {
            $responseData['exam_levels'] = $data['exam_levels'];
            $responseData['level_count'] = count($data['exam_levels']);
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

        if (isset($data['exam_levels']) && is_array($data['exam_levels'])) {
            $responseData['exam_levels'] = $data['exam_levels'];
            $responseData['level_count'] = count($data['exam_levels']);
        }

        successResponse($responseData, 'Registration submitted successfully');
    }

} catch (Exception $e) {
    logActivity("Exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}