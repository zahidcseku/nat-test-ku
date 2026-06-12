<?php
/**
 * Common utility functions for admin panel
 */

// Sanitize output
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// Require authentication
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    // Check session timeout
    if (isset($_SESSION['last_activity'])) {
        $inactive = time() - $_SESSION['last_activity'];
        if ($inactive > SESSION_LIFETIME) {
            session_destroy();
            header('Location: ' . BASE_URL . '/index.php?timeout=1');
            exit;
        }
    }

    $_SESSION['last_activity'] = time();
}

// Check if user is super admin
function isSuperAdmin() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'super_admin';
}

// Generate CSRF token
function generateCsrfToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

// Validate CSRF token
function validateCsrfToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// Log audit trail
function logAudit($action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $stmt = $conn->prepare("
        INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $userId = $_SESSION['user_id'] ?? null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $oldJson = $oldValues ? json_encode($oldValues) : null;
    $newJson = $newValues ? json_encode($newValues) : null;

    return $stmt->bind_param('ississss', $userId, $action, $tableName, $recordId, $oldJson, $newJson, $ipAddress, $userAgent) &&
           $stmt->execute();
}

// Send email
function sendEmail($to, $subject, $body, $registrationId = null, $emailType = 'confirmation') {
    $conn = getDbConnection();
    if (!$conn) return false;

    // Log email attempt
    $stmt = $conn->prepare("
        INSERT INTO email_log (registration_id, email_type, recipient_email, subject, body, sent_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $sentBy = $_SESSION['user_id'] ?? null;
    $status = 'sent';
    $errorMsg = null;

    // Send email using PHP mail() (can be upgraded to PHPMailer later)
    $headers = [
        'From' => SMTP_FROM,
        'Content-Type' => 'text/html; charset=UTF-8',
        'MIME-Version' => '1.0'
    ];

    $headersStr = '';
    foreach ($headers as $key => $value) {
        $headersStr .= "$key: $value\r\n";
    }

    $success = mail($to, $subject, $body, $headersStr);

    if (!$success) {
        $status = 'failed';
        $errorMsg = error_get_last()['message'] ?? 'Unknown error';
    }

    $stmt->bind_param('issssis', $registrationId, $emailType, $to, $subject, $body, $sentBy, $status);
    $stmt->execute();

    if ($errorMsg) {
        $stmt = $conn->prepare("UPDATE email_log SET error_message = ? WHERE id = ?");
        $stmt->bind_param('si', $errorMsg, $conn->insert_id);
        $stmt->execute();
    }

    return $success;
}

/**
 * Permanently delete a registration: DB row first, then its uploaded files.
 * Files are only unlinked when they resolve inside an intake uploads
 * directory — a tampered row must never delete arbitrary server files.
 *
 * @return array ['success' => bool, 'message' => string]
 */
function deleteRegistrationCompletely($id) {
    // mysqli throws on PHP >= 8.1 (e.g. when the DB user lacks the DELETE
    // privilege) — catch everything so failures surface as flash messages,
    // never bare 500s.
    try {
    $conn = getDbConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection failed'];
    }

    $stmt = $conn->prepare("
        SELECT full_name, email, mobile, exam_level, test_date, payment_status,
               approved, photo_storage_path, id_storage_path, payment_receipt_storage_path
        FROM registrations WHERE id = ?
    ");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        return ['success' => false, 'message' => 'Registration not found'];
    }
    $row = $result->fetch_assoc();
    $stmt->close();

    // DB row first: if this fails, no files have been touched
    $del = $conn->prepare("DELETE FROM registrations WHERE id = ?");
    $del->bind_param('s', $id);
    if (!$del->execute()) {
        $err = $del->error;
        $del->close();
        return ['success' => false, 'message' => 'Database delete failed: ' . $err];
    }
    $del->close();

    // Unlink uploads after the DB delete succeeded
    $fileNotes = [];
    foreach (['photo_storage_path', 'id_storage_path', 'payment_receipt_storage_path'] as $field) {
        $path = $row[$field] ?? '';
        if ($path === '' || $path === null) {
            continue;
        }
        $real = realpath($path);
        if ($real === false) {
            $fileNotes[] = basename($path) . ' (already missing)';
            continue;
        }
        $uploadsMarker = DIRECTORY_SEPARATOR . 'intake' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        if (strpos($real, $uploadsMarker) === false) {
            error_log("Registration delete: refused to unlink path outside uploads: {$real}");
            $fileNotes[] = basename($path) . ' (skipped: outside uploads)';
            continue;
        }
        if (!@unlink($real)) {
            error_log("Registration delete: failed to unlink {$real}");
            $fileNotes[] = basename($path) . ' (could not delete)';
        }
    }

    logAudit('delete_registration', 'registrations', $id, $row, null);

    $message = 'Registration deleted permanently';
    if (!empty($fileNotes)) {
        $message .= ' — file notes: ' . implode(', ', $fileNotes);
    }
    return ['success' => true, 'message' => $message];

    } catch (Throwable $e) {
        error_log('Registration delete failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
    }
}

// Format currency
function formatCurrency($amount) {
    return 'BDT ' . number_format($amount, 2);
}

// Format date
function formatDate($date, $format = 'F j, Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

// Check password strength
function isStrongPassword($password) {
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return false;
    }

    // Must contain uppercase, lowercase, and number
    return preg_match('/[A-Z]/', $password) &&
           preg_match('/[a-z]/', $password) &&
           preg_match('/[0-9]/', $password);
}

// Generate random password
function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 1, $length);
}

// Get login attempts
function getLoginAttempts($username) {
    $conn = getDbConnection();
    if (!$conn) return 0;

    $window = date('Y-m-d H:i:s', time() - LOGIN_ATTEMPT_WINDOW);

    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM login_attempts
        WHERE username = ? AND attempted_at > ? AND success = 0
    ");

    $stmt->bind_param('ss', $username, $window);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return (int)($result['count'] ?? 0);
}

// Log login attempt
function logLoginAttempt($username, $success) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $stmt = $conn->prepare("
        INSERT INTO login_attempts (username, ip_address, success)
        VALUES (?, ?, ?)
    ");

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

    return $stmt->bind_param('ssi', $username, $ipAddress, $success) && $stmt->execute();
}

// Check if account is locked
function isAccountLocked($username) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $stmt = $conn->prepare("
        SELECT locked_until FROM admin_users
        WHERE username = ? AND locked_until > NOW()
    ");

    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    return $result !== null;
}

// Lock account
function lockAccount($username, $minutes = 15) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $stmt = $conn->prepare("
        UPDATE admin_users
        SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
        WHERE username = ?
    ");

    return $stmt->bind_param('is', $minutes, $username) && $stmt->execute();
}

// Reset login attempts
function resetLoginAttempts($username) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $stmt = $conn->prepare("
        UPDATE admin_users
        SET login_attempts = 0, locked_until = NULL
        WHERE username = ?
    ");

    return $stmt->bind_param('s', $username) && $stmt->execute();
}

// Get flash message
function getFlashMessage() {
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }

    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    return $message;
}

// Set flash message
function setFlashMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

// Redirect with message
function redirect($url, $message = null, $type = 'success') {
    if ($message) {
        setFlashMessage($message, $type);
    }
    header('Location: ' . $url);
    exit;
}

// Check if file upload is valid
function validateFileUpload($file, $allowedTypes = null) {
    if ($allowedTypes === null) {
        $allowedTypes = ['application/pdf', 'application/zip'];
    }

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['valid' => false, 'error' => 'Invalid file upload'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'Upload error: ' . $file['error']];
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['valid' => false, 'error' => 'File too large. Max size: ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $allowedTypes)) {
        return ['valid' => false, 'error' => 'Invalid file type: ' . $mimeType];
    }

    return ['valid' => true];
}

// Generate ticket number
function generateTicketNumber() {
    return 'NT' . date('Y') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

// Convert filesystem path to web URL for intake uploads
function intakePathToUrl($filesystemPath) {
    if (empty($filesystemPath)) {
        return '';
    }

    // Convert filesystem path to web URL
    $url = str_replace('/home/nattest/public_html/', 'https://nat-test.ku.ac.bd/', $filesystemPath);
    $url = str_replace('/frontend/intake/', '/intake/', $url);

    return $url;
}
