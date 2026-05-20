<?php
/**
 * Admission Tickets Management
 * Upload admission tickets and email to participants
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Admission Tickets';
$currentPage = 'participants';

$conn = getDbConnection();

$success = '';
$error = '';

// Handle ticket upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tickets_zip'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token';
    } else {
        $examDateId = $_POST['exam_date_id'] ?? '';
        $examDate = $_POST['exam_date'] ?? '';

        if (empty($examDateId) || empty($examDate)) {
            $error = 'Please select an exam date';
        } else {
            // Validate file upload
            $file = $_FILES['tickets_zip'];
            $validation = validateFileUpload($file, ['application/zip', 'application/x-zip-compressed']);

            if (!$validation['valid']) {
                $error = $validation['error'];
            } else {
                // Create upload directory if it doesn't exist
                $uploadDir = UPLOAD_PATH . 'tickets/' . date('Y-m-d') . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Extract ZIP file
                $zipPath = $file['tmp_name'];
                $zip = new ZipArchive;
                $openResult = $zip->open($zipPath);

                if ($openResult !== TRUE) {
                    $error = 'Failed to open ZIP file. Error code: ' . $openResult;
                } else {
                    $ticketsSent = 0;
                    $ticketsFailed = 0;

                    // Get approved registrations for this exam
                    $stmt = $conn->prepare("
                        SELECT id, full_name, email, exam_level
                        FROM registrations
                        WHERE test_date = ? AND status = 'approved'
                        AND admission_ticket_path IS NULL
                    ");
                    $stmt->bind_param('s', $examDate);
                    $stmt->execute();
                    $registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                    // Extract and process tickets
                    $tempDir = sys_get_temp_dir() . '/tickets_' . time() . '/';
                    mkdir($tempDir, 0755, true);

                    $zip->extractTo($tempDir);
                    $zip->close();

                    // Match tickets to registrations
                    foreach ($registrations as $registration) {
                        // Try to find ticket by email or registration ID
                        $possibleFiles = [
                            $tempDir . strtolower($registration['email']) . '.pdf',
                            $tempDir . $registration['id'] . '.pdf',
                            $tempDir . str_replace(' ', '_', strtolower($registration['email'])) . '.pdf'
                        ];

                        $ticketFile = null;
                        foreach ($possibleFiles as $file) {
                            if (file_exists($file)) {
                                $ticketFile = $file;
                                break;
                            }
                        }

                        if ($ticketFile) {
                            // Move ticket to permanent location
                            $ticketPath = $uploadDir . $registration['id'] . '_ticket.pdf';
                            rename($ticketFile, $ticketPath);

                            // Generate ticket number
                            $ticketNumber = generateTicketNumber();

                            // Update registration
                            $stmt = $conn->prepare("
                                UPDATE registrations
                                SET admission_ticket_path = ?,
                                    ticket_number = ?,
                                    admission_ticket_emailed_at = NOW()
                                WHERE id = ?
                            ");
                            $webPath = str_replace(UPLOAD_PATH, '/uploads/', $ticketPath);
                            $stmt->bind_param('ssi', $webPath, $ticketNumber, $registration['id']);
                            $stmt->execute();

                            // Save ticket record
                            $stmt = $conn->prepare("
                                INSERT INTO admission_tickets (registration_id, exam_date_id, ticket_number, file_path, created_by)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->bind_param('iissi', $registration['id'], $examDateId, $ticketNumber, $webPath, $_SESSION['user_id']);
                            $stmt->execute();

                            // Send email with ticket
                            $emailBody = renderTicketEmail($registration, $ticketNumber, $webPath);
                            if (sendEmail($registration['email'], 'Your NAT-TEST Admission Ticket', $emailBody, $registration['id'], 'admission_ticket')) {
                                $ticketsSent++;
                            } else {
                                $ticketsFailed++;
                            }
                        }
                    }

                    // Cleanup temp directory
                    array_map('unlink', glob($tempDir . '*'));
                    rmdir($tempDir);

                    logAudit('upload_tickets', 'registrations', null, null, ['exam_date' => $examDate, 'sent' => $ticketsSent, 'failed' => $ticketsFailed]);

                    if ($ticketsSent > 0) {
                        $success = "Successfully sent $ticketsSent admission ticket(s)";
                        if ($ticketsFailed > 0) {
                            $success .= ". Failed: $ticketsFailed";
                        }
                    } else {
                        $error = 'No tickets were sent. Make sure ZIP files match registration emails or IDs.';
                    }
                }
            }
        }
    }
}

// Get upcoming exam dates with approved registrations
$stmt = $conn->prepare("
    SELECT
        ed.id,
        ed.exam_date,
        COUNT(CASE WHEN r.status = 'approved' THEN 1 END) as approved_count,
        COUNT(CASE WHEN r.status = 'approved' AND r.admission_ticket_path IS NOT NULL THEN 1 END) as tickets_sent
    FROM exam_dates ed
    LEFT JOIN registrations r ON r.test_date = ed.exam_date
    WHERE ed.exam_date >= CURDATE()
    GROUP BY ed.id, ed.exam_date
    HAVING approved_count > 0
    ORDER BY ed.exam_date ASC
");
$stmt->execute();
$examDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../templates/header.php';

function renderTicketEmail($registration, $ticketNumber, $ticketPath) {
    ob_start();
    ?>
    <h2 style="color: #1a202c; font-size: 24px; font-weight: 700; margin-bottom: 16px;">Your Admission Ticket 🎫</h2>
    <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">Dear <?php echo e($registration['full_name']); ?>,</p>
    <p style="color: #4a5568; font-size: 16px; line-height: 1.6; margin: 16px 0;">
        Your admission ticket for the Japanese NAT-TEST is attached to this email.
    </p>
    <div style="background: #f7fafc; border-left: 4px solid #667eea; padding: 16px; margin: 24px 0;">
        <p style="margin: 0;"><strong>Ticket Number:</strong> <?php echo e($ticketNumber); ?></p>
        <p style="margin: 8px 0;"><strong>Exam Level:</strong> <?php echo e($registration['exam_level']); ?></p>
    </div>
    <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">
        Please print the ticket and bring it with you on the exam day.
    </p>
    <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">
        <strong>Important:</strong> Arrive at least 30 minutes before the exam time.
    </p>
    <?php
    return ob_get_clean();
}
?>

<div class="page-header">
    <h1 class="page-title">Admission Tickets</h1>
    <p class="page-subtitle">Upload and distribute admission tickets to participants</p>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <?php echo e($success); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error">
        <?php echo e($error); ?>
    </div>
<?php endif; ?>

<!-- Upload Section -->
<div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 32px;">
    <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px;">Upload Admission Tickets</h2>

    <form method="POST" enctype="multipart/form-data" style="display: grid; gap: 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 8px;">Select Exam Date *</label>
            <select name="exam_date" required style="width: 100%; max-width: 400px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">Choose exam date...</option>
                <?php foreach ($examDates as $exam): ?>
                    <option value="<?php echo e($exam['exam_date']); ?>" data-id="<?php echo e($exam['id']); ?>">
                        <?php echo e(formatDate($exam['exam_date'])); ?> (<?php echo $exam['approved_count']; ?> approved)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="exam_date_id" id="exam_date_id" value="">
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 8px;">
                Upload ZIP File containing PDF tickets *
            </label>
            <div style="border: 2px dashed #cbd5e0; border-radius: 8px; padding: 32px; text-align: center;">
                <input type="file" name="tickets_zip" accept=".zip,application/zip,application/x-zip-compressed" required
                       style="font-size: 14px;">
                <p style="color: #718096; font-size: 13px; margin-top: 12px;">
                    File naming: <code style="background: #edf2f7; padding: 2px 6px; border-radius: 4px;">email_address.pdf</code> or <code style="background: #edf2f7; padding: 2px 6px; border-radius: 4px;">registration_id.pdf</code>
                </p>
                <p style="color: #718096; font-size: 12px; margin-top: 8px;">
                    Maximum file size: <?php echo (MAX_UPLOAD_SIZE / 1024 / 1024); ?>MB
                </p>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 12px 24px; font-size: 15px; font-weight: 600;">
            📤 Upload & Send Tickets
        </button>
    </form>
</div>

<!-- Exam Dates Status -->
<?php if (!empty($examDates)): ?>
    <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
        <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px;">Ticket Distribution Status</h2>

        <div style="display: grid; gap: 16px;">
            <?php foreach ($examDates as $exam): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; background: #f7fafc; border-radius: 8px;">
                    <div>
                        <div style="font-size: 15px; font-weight: 500; color: #1a202c;">
                            <?php echo e(formatDate($exam['exam_date'])); ?>
                        </div>
                        <div style="font-size: 13px; color: #718096; margin-top: 4px;">
                            <?php echo $exam['approved_count']; ?> approved
                            <?php if ($exam['tickets_sent'] > 0): ?>
                                • <span style="color: #48bb78;"><?php echo $exam['tickets_sent']; ?> tickets sent</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <?php if ($exam['tickets_sent'] < $exam['approved_count']): ?>
                            <span style="display: inline-block; padding: 4px 12px; background: #fed7d7; color: #c53030; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                <?php echo $exam['approved_count'] - $exam['tickets_sent']; ?> pending
                            </span>
                        <?php else: ?>
                            <span style="display: inline-block; padding: 4px 12px; background: #c6f6d5; color: #276749; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                ✓ Complete
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
        <div style="font-size: 48px; margin-bottom: 16px;">🎫</div>
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Upcoming Exams</h3>
        <p style="color: #718096; font-size: 14px;">No exam dates with approved registrations found.</p>
    </div>
<?php endif; ?>

<script>
document.querySelector('select[name="exam_date"]').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const examId = selectedOption.getAttribute('data-id');
    document.getElementById('exam_date_id').value = examId || '';
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
