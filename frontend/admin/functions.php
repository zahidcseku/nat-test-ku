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

    if (!$stmt) {
        // audit_log table missing or schema drift — never fatal the action
        error_log('logAudit prepare failed: ' . $conn->error);
        return false;
    }

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
    if (!$stmt) {
        return ['success' => false, 'message' => 'Delete failed (lookup prepare): ' . $conn->error];
    }
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
    if (!$del) {
        return ['success' => false, 'message' => 'Delete failed (delete prepare): ' . $conn->error];
    }
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

    // Audit is best-effort: the row and files are already gone, so an
    // audit failure must not report the delete itself as failed
    $audited = false;
    try {
        $audited = logAudit('delete_registration', 'registrations', $id, $row, null);
    } catch (Throwable $auditErr) {
        error_log('Registration delete: audit failed: ' . $auditErr->getMessage());
    }

    $message = 'Registration deleted permanently';
    if (!$audited) {
        $message .= ' (warning: audit log entry could not be written)';
    }
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

/**
 * Run a paginated SELECT.
 *
 * @param string $dataQuery  SELECT ... FROM ... WHERE ... ORDER BY ...   (no LIMIT)
 * @param string $countQuery SELECT COUNT(*) AS cnt FROM ... WHERE ...   (same WHERE)
 * @param array  $params     bind params for WHERE (excluding page/perPage)
 * @param string $types      mysqli type string for $params (e.g. "sss")
 * @param int    $page       1-indexed page number
 * @param int    $perPage    rows per page
 * @return array{rows:array, total:int, page:int, perPage:int, totalPages:int}
 */
function paginateQuery($dataQuery, $countQuery, $params = [], $types = '', $page = 1, $perPage = 50) {
    $conn = getDbConnection();
    $page    = max(1, (int) $page);
    $perPage = max(1, min(500, (int) $perPage));

    // Total count
    $stmt = $conn->prepare($countQuery);
    if ($stmt === false) {
        return ['rows' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage, 'totalPages' => 1, 'error' => $conn->error];
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $total = (int) ($row['cnt'] ?? 0);
    $stmt->close();

    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // Page rows
    $stmt = $conn->prepare($dataQuery . ' LIMIT ? OFFSET ?');
    if ($stmt === false) {
        return ['rows' => [], 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'totalPages' => $totalPages, 'error' => $conn->error];
    }
    $pageParams = array_merge($params, [$perPage, $offset]);
    $pageTypes  = $types . 'ii';
    $stmt->bind_param($pageTypes, ...$pageParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'rows'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'perPage'    => $perPage,
        'totalPages' => $totalPages,
    ];
}

/**
 * Render a pagination control as HTML. Returns '' if there's nothing to paginate.
 *
 * @param int    $currentPage
 * @param int    $totalPages
 * @param int    $totalRows
 * @param int    $perPage
 * @param string $basePath    script path, e.g. BASE_URL . '/pages/registrations.php'
 * @param array  $queryParams query params to preserve across page links (page is set automatically)
 * @return string
 */
function renderPagination($currentPage, $totalPages, $totalRows, $perPage, $basePath, $queryParams = []) {
    if ($totalPages <= 1 || $totalRows === 0) {
        return '';
    }

    $startRow = ($currentPage - 1) * $perPage + 1;
    $endRow   = min($currentPage * $perPage, $totalRows);

    // Build a page URL preserving filter params.
    $urlFor = function ($p) use ($basePath, $queryParams) {
        unset($queryParams['page']);
        $params = array_merge($queryParams, ['page' => $p]);
        return $basePath . '?' . http_build_query($params, '', '&amp;');
    };

    // Determine visible page numbers: current ±2, always show 1 and last.
    $range = [];
    $rangeStart = max(1, $currentPage - 2);
    $rangeEnd   = min($totalPages, $currentPage + 2);
    for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
        $range[$i] = true;
    }
    $range[1] = true;
    $range[$totalPages] = true;

    $ordered = array_keys($range);
    sort($ordered);

    ob_start();
    ?>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; padding: 16px; margin-top: 16px; background: white; border: 1px solid #e2e8f0; border-radius: 12px;">
        <div style="font-size: 13px; color: #718096;">
            Showing <strong><?php echo $startRow; ?></strong>&ndash;<strong><?php echo $endRow; ?></strong>
            of <strong><?php echo number_format($totalRows); ?></strong>
        </div>
        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
            <?php if ($currentPage > 1): ?>
                <a href="<?php echo $urlFor($currentPage - 1); ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">&laquo; Prev</a>
            <?php else: ?>
                <span class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px; opacity: 0.4; cursor: not-allowed;">&laquo; Prev</span>
            <?php endif; ?>

            <?php
            $prev = 0;
            foreach ($ordered as $p):
                if ($p - $prev > 1):
                    ?><span style="padding: 6px 8px; color: #a0aec0; font-size: 13px;">&hellip;</span><?php
                endif;
                $prev = $p;
                if ($p === $currentPage):
                    ?><span class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;"><?php echo $p; ?></span><?php
                else:
                    ?><a href="<?php echo $urlFor($p); ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;"><?php echo $p; ?></a><?php
                endif;
            endforeach;
            ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="<?php echo $urlFor($currentPage + 1); ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">Next &raquo;</a>
            <?php else: ?>
                <span class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px; opacity: 0.4; cursor: not-allowed;">Next &raquo;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
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

// ---- Applicant file upload helpers (photo / ID document replacement) ----
// Mirrors intake/upload.php rules so files replaced from admin stay
// consistent with what the intake service writes.

define('APPLICANT_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);
define('APPLICANT_PDF_TYPES', ['application/pdf']);
define('APPLICANT_FILE_MAX_SIZE', 5 * 1024 * 1024); // 5MB — same as intake

// Generate a UUID v4 for secure filenames
function generateFileUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Validate and store an applicant file upload (photo or ID document) into
 * the intake uploads directory. Validation uses finfo magic bytes and the
 * same 5MB cap as the intake service.
 *
 * @param array  $file         $_FILES entry
 * @param string $category     'photos' or 'ids'
 * @param array  $allowedTypes allowed MIME types
 * @return array {success:bool, error:?string, filename:?string, storage_path:?string, size_bytes:?int}
 */
function processApplicantFileUpload($file, $category, $allowedTypes) {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }

    if ($file['size'] > APPLICANT_FILE_MAX_SIZE) {
        return ['success' => false, 'error' => 'File exceeds maximum size of 5MB'];
    }

    if ($file['size'] === 0) {
        return ['success' => false, 'error' => 'File is empty'];
    }

    // Validate real type via magic bytes (never trust the extension)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return ['success' => false, 'error' => 'Unable to validate file type'];
    }
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes, true)) {
        if ($mimeType === 'image/heic' || $mimeType === 'image/heif') {
            return ['success' => false, 'error' => 'iPhone HEIC photos are not supported. Please upload a JPG or PNG.'];
        }
        $pdfNote = in_array('application/pdf', $allowedTypes, true) ? ' or PDF' : '';
        return ['success' => false, 'error' => 'Unsupported file type. Please upload a JPG or PNG' . $pdfNote . '.'];
    }

    $base = INTAKE_UPLOADS_DIR;
    if (!$base || !is_dir($base)) {
        return ['success' => false, 'error' => 'Upload directory is not available'];
    }

    $categoryDir = $base . '/' . $category;
    if (!is_dir($categoryDir)) {
        @mkdir($categoryDir, 0755, true);
    }
    if (!is_writable($categoryDir)) {
        return ['success' => false, 'error' => 'Upload directory is not writable'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = generateFileUuid() . '.' . $extension;
    $targetPath = $categoryDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'Failed to store uploaded file'];
    }

    chmod($targetPath, 0644);

    return [
        'success'      => true,
        'filename'     => $filename,
        'storage_path' => $targetPath,
        'size_bytes'   => (int) $file['size'],
    ];
}

/**
 * Safely delete a file inside the intake uploads directory. Refuses to
 * unlink anything whose realpath is not under /intake/uploads/ so a
 * tampered DB row can never delete arbitrary server files.
 */
function safeUnlinkIntakeUpload($path) {
    if (empty($path)) {
        return false;
    }
    $real = realpath($path);
    if ($real === false) {
        return false; // already gone
    }
    $marker = DIRECTORY_SEPARATOR . 'intake' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    if (strpos($real, $marker) === false) {
        error_log("Admin upload replace: refused to unlink path outside intake uploads: {$real}");
        return false;
    }
    return @unlink($real);
}
