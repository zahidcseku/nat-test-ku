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
            payment_method, exam_level, test_date,
            photo_filename, photo_storage_path, photo_size_bytes,
            id_filename, id_storage_path, id_size_bytes,
            payment_receipt_filename, payment_receipt_storage_path, payment_receipt_size_bytes,
            ip_hash, user_agent, honeypot_tripped, honeypot_value
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        logActivity("Prepare statement failed: " . $conn->error, 'error');
        errorResponse('Database error', 500);
    }

    $photo = $uploadResult['files']['photo'];
    $idDoc = $uploadResult['files']['id_document'];
    $receipt = $uploadResult['files']['payment_receipt'] ?? null;

    $stmt->bind_param(
        'sssssssssssssssssssssis',
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
        $data['test_date'],
        $photo['filename'],
        $photo['storage_path'],
        $photo['size_bytes'],
        $idDoc['filename'],
        $idDoc['storage_path'],
        $idDoc['size_bytes'],
        $receipt['filename'] ?? null,
        $receipt['storage_path'] ?? null,
        $receipt['size_bytes'] ?? null,
        $ipHash,
        $userAgent,
        $honeypotCheck['tripped'] ? 1 : 0,
        $honeypotCheck['value']
    );

    $result = $stmt->execute();

    // DEBUG: Log database result
    file_put_contents(__DIR__ . '/logs/debug_db.json', json_encode([
        'success' => $result,
        'insert_id' => $stmt->insert_id,
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

    $stmt->close();
    $conn->close();

    // Log successful registration
    logActivity("Registration submitted: ID=$id, Email={$data['email']}, IP=$ipHash");

    // Send success response
    successResponse([
        'id' => $id,
        'email' => $data['email'],
        'exam_level' => $data['exam_level'],
        'test_date' => $data['test_date']
    ], 'Registration submitted successfully');

} catch (Exception $e) {
    logActivity("Exception: " . $e->getMessage(), 'error');
    errorResponse('Server error', 500);
}
