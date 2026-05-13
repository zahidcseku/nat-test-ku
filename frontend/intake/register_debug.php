<?php
/**
 * NAT-TEST Intake Service - Registration Endpoint (DEBUG VERSION)
 * This version provides detailed error logging and responses
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start debugging
$debug = [];
$debug['timestamp'] = date('Y-m-d H:i:s');
$debug['php_version'] = PHP_VERSION;

// Load dependencies
try {
    require_once __DIR__ . '/config.php';
    $debug['config_loaded'] = true;
} catch (Exception $e) {
    $debug['config_error'] = $e->getMessage();
    echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Failed to load config']);
    exit;
}

// Check request method
$debug['request_method'] = $_SERVER['REQUEST_METHOD'];
$debug['content_type'] = $_SERVER['CONTENT_TYPE'] ?? 'not set';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Only POST allowed']);
    exit;
}

try {
    // Check upload directory
    $dirValidation = validateUploadDirectory();
    $debug['upload_dir_valid'] = $dirValidation['valid'];
    $debug['upload_dir_error'] = $dirValidation['error'] ?? null;

    if (!$dirValidation['valid']) {
        echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Upload directory error: ' . $dirValidation['error']]);
        exit;
    }

    // Get request data
    $postData = $_POST ?? [];
    $filesData = $_FILES ?? [];

    $debug['post_fields'] = array_keys($postData);
    $debug['files_received'] = array_keys($filesData);
    $debug['file_errors'] = [];

    foreach ($filesData as $field => $file) {
        $debug['file_errors'][$field] = $file['error'] ?? 'no error code';
    }

    // Validate required fields
    $required_fields = ['full_name', 'email', 'mobile', 'address', 'dob', 'gender', 'nationality', 'payment_method', 'exam_level', 'test_date'];
    $missing_fields = [];

    foreach ($required_fields as $field) {
        if (empty($postData[$field])) {
            $missing_fields[] = $field;
        }
    }

    if (!empty($missing_fields)) {
        $debug['missing_fields'] = $missing_fields;
        echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Missing required fields: ' . implode(', ', $missing_fields)]);
        exit;
    }

    // Check honeypot
    require_once __DIR__ . '/security.php';
    $honeypotCheck = checkHoneypot($postData);
    $debug['honeypot_tripped'] = $honeypotCheck['tripped'];

    // Validate data
    require_once __DIR__ . '/validate.php';
    $validation = validateRegistrationData($postData);
    $debug['validation_valid'] = $validation['valid'];
    $debug['validation_errors'] = $validation['errors'];

    if (!$validation['valid']) {
        echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Validation failed', 'errors' => $validation['errors']]);
        exit;
    }

    // Handle file uploads
    require_once __DIR__ . '/upload.php';
    $uploadResult = handleFileUploads($filesData);
    $debug['upload_success'] = $uploadResult['success'];
    $debug['upload_errors'] = $uploadResult['errors'];
    $debug['uploaded_files'] = array_keys($uploadResult['files']);

    if (!$uploadResult['success'] || !empty($uploadResult['errors'])) {
        // Clean up uploaded files
        foreach ($uploadResult['files'] as $file) {
            if (isset($file['storage_path'])) {
                deleteUploadedFile($file['storage_path']);
            }
        }

        echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'File upload failed', 'errors' => $uploadResult['errors']]);
        exit;
    }

    // Get database connection
    $conn = getDbConnection();
    $debug['db_connection'] = ($conn !== null);

    if (!$conn) {
        $debug['db_error'] = 'Database connection failed';
        echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Database connection failed']);
        exit;
    }

    // Prepare data
    $data = $validation['data'];
    $id = generateUuid();
    $ipHash = hashIp(getClientIp());
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $debug['data_to_insert'] = [
        'id' => $id,
        'email' => $data['email'],
        'exam_level' => $data['exam_level']
    ];

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
        $debug['stmt_error'] = $conn->error;
        echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Prepare statement failed: ' . $conn->error]);
        exit;
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
    $debug['insert_success'] = $result;

    if (!$result) {
        $debug['execute_error'] = $stmt->error;
        echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Execute failed: ' . $stmt->error]);
        exit;
    }

    $debug['insert_id'] = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    // Success!
    logActivity("Registration submitted: ID=$id, Email={$data['email']}");

    echo json_encode([
        'success' => true,
        'debug' => $debug,
        'message' => 'Registration submitted successfully',
        'data' => [
            'id' => $id,
            'email' => $data['email'],
            'exam_level' => $data['exam_level'],
            'test_date' => $data['test_date']
        ]
    ]);

} catch (Exception $e) {
    $debug['exception'] = $e->getMessage();
    $debug['trace'] = $e->getTraceAsString();
    echo json_encode(['success' => false, 'debug' => $debug, 'error' => 'Exception: ' . $e->getMessage()]);
}
?>
