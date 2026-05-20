<?php
/**
 * Resend Email API
 */

require_once __DIR__ . '/../../auth/middleware.php';

$conn = getDbConnection();

$emailId = $_POST['email_id'] ?? 0;

// Get original email
$stmt = $conn->prepare("
    SELECT el.*, r.full_name, r.email, r.exam_level, r.test_date
    FROM email_log el
    LEFT JOIN registrations r ON el.registration_id = r.id
    WHERE el.id = ?
");
$stmt->bind_param('i', $emailId);
$stmt->execute();
$originalEmail = $stmt->get_result()->fetch_assoc();

if (!$originalEmail) {
    setFlashMessage('Email not found', 'error');
    header('Location: ' . BASE_URL . '/pages/emails.php');
    exit;
}

// Resend email
$success = sendEmail(
    $originalEmail['recipient_email'],
    $originalEmail['subject'],
    $originalEmail['body'],
    $originalEmail['registration_id'],
    'resend'
);

if ($success) {
    setFlashMessage('Email resent successfully', 'success');
} else {
    setFlashMessage('Failed to resend email', 'error');
}

header('Location: ' . BASE_URL . '/pages/emails.php');
