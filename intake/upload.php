<?php
/**
 * NAT-TEST Intake Service - File Upload Handler
 *
 * Handles secure file uploads with validation for file type,
 * size, and magic byte verification.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $allowedTypes, $maxSize = null) {
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = getFileUploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE);
        return [
            'valid' => false,
            'error' => $errorMsg
        ];
    }

    // Check file size
    $maxSize = $maxSize ?: MAX_FILE_SIZE;
    if ($file['size'] > $maxSize) {
        return [
            'valid' => false,
            'error' => 'File size exceeds maximum allowed size of ' . formatBytes($maxSize)
        ];
    }

    // Check file size is not zero
    if ($file['size'] === 0) {
        return [
            'valid' => false,
            'error' => 'File is empty'
        ];
    }

    // Validate file type using magic bytes (finfo)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return [
            'valid' => false,
            'error' => 'Unable to validate file type'
        ];
    }

    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes, true)) {
        return [
            'valid' => false,
            'error' => 'Invalid file type. Allowed types: ' . implode(', ', $allowedTypes)
        ];
    }

    return [
        'valid' => true,
        'error' => null,
        'mime_type' => $mimeType,
        'size' => $file['size']
    ];
}

/**
 * Process file upload
 */
function processFileUpload($file, $category, $allowedTypes) {
    // Validate file
    $validation = validateFileUpload($file, $allowedTypes);
    if (!$validation['valid']) {
        return [
            'success' => false,
            'error' => $validation['error']
        ];
    }

    // Generate secure filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uuid = generateUuid();
    $secureFilename = $uuid . '.' . $extension;

    // Create category directory if it doesn't exist
    $categoryDir = UPLOAD_DIR . '/' . $category;
    if (!is_dir($categoryDir)) {
        if (!mkdir($categoryDir, 0755, true)) {
            return [
                'success' => false,
                'error' => 'Failed to create upload directory'
            ];
        }
    }

    // Set target path
    $targetPath = $categoryDir . '/' . $secureFilename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [
            'success' => false,
            'error' => 'Failed to move uploaded file'
        ];
    }

    // Set file permissions
    chmod($targetPath, 0644);

    logActivity("File uploaded: $secureFilename | Category: $category | Size: {$validation['size']} bytes");

    return [
        'success' => true,
        'filename' => $secureFilename,
        'storage_path' => $targetPath,
        'size_bytes' => $validation['size'],
        'mime_type' => $validation['mime_type']
    ];
}

/**
 * Process photo upload
 */
function processPhotoUpload($file) {
    $allowedTypes = ALLOWED_IMAGE_TYPES;
    return processFileUpload($file, 'photos', $allowedTypes);
}

/**
 * Process ID document upload
 */
function processIdUpload($file) {
    $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_PDF_TYPES);
    return processFileUpload($file, 'ids', $allowedTypes);
}

/**
 * Process payment receipt upload
 */
function processReceiptUpload($file) {
    $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_PDF_TYPES);
    return processFileUpload($file, 'receipts', $allowedTypes);
}

/**
 * Handle all required file uploads
 */
function handleFileUploads($files) {
    $uploadedFiles = [];
    $errors = [];

    // Photo upload (required)
    if (isset($files['photo'])) {
        $result = processPhotoUpload($files['photo']);
        if ($result['success']) {
            $uploadedFiles['photo'] = $result;
        } else {
            $errors['photo'] = $result['error'];
        }
    } else {
        $errors['photo'] = 'Photo is required';
    }

    // ID document upload (required)
    if (isset($files['id_document'])) {
        $result = processIdUpload($files['id_document']);
        if ($result['success']) {
            $uploadedFiles['id_document'] = $result;
        } else {
            $errors['id_document'] = $result['error'];
        }
    } else {
        $errors['id_document'] = 'ID document is required';
    }

    // Payment receipt upload (optional)
    if (isset($files['payment_receipt']) && $files['payment_receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
        $result = processReceiptUpload($files['payment_receipt']);
        if ($result['success']) {
            $uploadedFiles['payment_receipt'] = $result;
        } else {
            $errors['payment_receipt'] = $result['error'];
        }
    }

    return [
        'success' => empty($errors) || (isset($errors['payment_receipt']) && count($errors) === 1),
        'files' => $uploadedFiles,
        'errors' => $errors
    ];
}

/**
 * Get file upload error message
 */
function getFileUploadErrorMessage($errorCode) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
    ];

    return $errors[$errorCode] ?? 'Unknown upload error';
}

/**
 * Format bytes to human-readable format
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Delete uploaded file (for cleanup or admin operations)
 */
function deleteUploadedFile($filePath) {
    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            logActivity("File deleted: $filePath");
            return true;
        } else {
            logActivity("Failed to delete file: $filePath", 'error');
            return false;
        }
    }

    return false;
}

/**
 * Validate directory permissions
 */
function validateUploadDirectory() {
    if (!is_dir(UPLOAD_DIR)) {
        return [
            'valid' => false,
            'error' => 'Upload directory does not exist'
        ];
    }

    if (!is_writable(UPLOAD_DIR)) {
        return [
            'valid' => false,
            'error' => 'Upload directory is not writable'
        ];
    }

    return [
        'valid' => true,
        'error' => null
    ];
}
