<?php
/**
 * Send Retry Email API Endpoint
 * Sends payment retry email to a specific user
 */

// Require authentication
require_once __DIR__ . '/../../auth/middleware.php';

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$registrationId = $input['registration_id'] ?? '';

if (empty($registrationId)) {
    echo json_encode(['success' => false, 'error' => 'Registration ID required']);
    exit;
}

try {
    $conn = getDbConnection();
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }

    // Get registration details
    $stmt = $conn->prepare("
        SELECT full_name, email, base_amount, transaction_fee, total_amount_paid,
               exam_level, test_date, payment_retry_token, payment_retry_expires
        FROM registrations
        WHERE id = ?
    ");
    $stmt->bind_param('s', $registrationId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Registration not found']);
        exit;
    }

    $reg = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    // Generate retry link
    $retryLink = SITE_URL . '/payment-retry.html?token=' . $reg['payment_retry_token'];

    // Send email (implement email sending logic)
    $subject = 'Complete Your NAT-TEST Registration Payment';
    $message = "
Dear {$reg['full_name']},

Your registration for NAT-TEST is pending payment completion.

Registration Details:
• Registration ID: {$registrationId}
• Name: {$reg['full_name']}
• Email: {$reg['email']}
• Exam Level: {$reg['exam_level']}
• Test Date: {$reg['test_date']}
• Total Amount: {$reg['total_amount_paid']} BDT (includes {$reg['transaction_fee']} BDT online fee)

PAYMENT STATUS: Unpaid
To complete your registration, please make the payment using the link below:

{$retryLink}

This secure payment link will expire in 7 days.

Need Help? Email: info@nat-test.ku.ac.bd
";

    // Send email
    $emailResult = sendEmail($reg['email'], $subject, $message, $registrationId, 'resend');

    if ($emailResult['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Retry email sent successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to send email: ' . $emailResult['error']
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
