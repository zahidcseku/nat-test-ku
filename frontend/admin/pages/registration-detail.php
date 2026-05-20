<?php
/**
 * Registration Detail Page
 * Review individual registration, approve/reject with email
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Review Registration';
$currentPage = 'registrations';

$id = $_GET['id'] ?? 0;

$conn = getDbConnection();

if (!$conn) {
    die('Database connection failed');
}

// Get registration details
$stmt = $conn->prepare("
    SELECT r.*, ed.exam_date
    FROM registrations r
    LEFT JOIN exam_dates ed ON r.test_date = ed.exam_date
    WHERE r.id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$registration = $stmt->get_result()->fetch_assoc();

if (!$registration) {
    die('Registration not found');
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reasons = $_POST['rejection_reasons'] ?? '';

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('Invalid CSRF token', 'error');
    } elseif ($action === 'approve') {
        // Update status
        $stmt = $conn->prepare("UPDATE registrations SET approved = 1, approved_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        // Log audit
        logAudit('approve_registration', 'registrations', $id, ['approved' => 0], ['approved' => 1]);

        // Send confirmation email
        $emailBody = renderEmailTemplate('confirmation', $registration);
        sendEmail($registration['email'], 'NAT-TEST Registration Approved', $emailBody, $id, 'confirmation');

        setFlashMessage('Registration approved and confirmation email sent', 'success');
        header('Location: ' . BASE_URL . '/pages/registrations.php');
        exit;

    } elseif ($action === 'reject') {
        // For rejection, we'll keep approved = 0 but could add notes
        // For now, let's just redirect with a message since rejection tracking needs schema update
        setFlashMessage('Rejection tracking requires schema updates. Please contact applicant directly.', 'error');
        header('Location: ' . BASE_URL . '/pages/registrations.php');
        exit;
    }
}

require_once __DIR__ . '/../templates/header.php';

// Helper function to render email templates
function renderEmailTemplate($type, $data, $reasons = '') {
    ob_start();
    if ($type === 'confirmation') {
        ?>
        <h2 style="color: #1a202c; font-size: 24px; font-weight: 700; margin-bottom: 16px;">Registration Approved! 🎉</h2>
        <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">Dear <?php echo e($data['full_name']); ?>,</p>
        <p style="color: #4a5568; font-size: 16px; line-height: 1.6; margin: 16px 0;">
            Your registration for the Japanese NAT-TEST has been <strong>approved</strong>.
        </p>
        <div style="background: #f7fafc; border-left: 4px solid #48bb78; padding: 16px; margin: 24px 0;">
            <p style="margin: 0;"><strong>Exam Details:</strong></p>
            <p style="margin: 8px 0;">Level: <?php echo e($data['exam_level']); ?></p>
            <p style="margin: 8px 0;">Date: <?php echo e(formatDate($data['test_date'])); ?></p>
        </div>
        <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">
            Your admission ticket will be emailed to you a few days before the exam.
        </p>
        <?php
    } elseif ($type === 'rejection') {
        ?>
        <h2 style="color: #1a202c; font-size: 24px; font-weight: 700; margin-bottom: 16px;">Action Required: Registration Issues</h2>
        <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">Dear <?php echo e($data['full_name']); ?>,</p>
        <p style="color: #4a5568; font-size: 16px; line-height: 1.6; margin: 16px 0;">
            Your registration requires corrections. Please review the following issues:
        </p>
        <div style="background: #fed7d7; border-left: 4px solid #f56565; padding: 16px; margin: 24px 0;">
            <strong>Issues Found:</strong>
            <div style="margin-top: 8px;"><?php echo nl2br(e($reasons)); ?></div>
        </div>
        <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">
            Please reply to this email with the corrections and we'll update your application.
        </p>
        <?php
    }
    return ob_get_clean();
}
?>

<div class="page-header">
    <h1 class="page-title">Review Registration #<?php echo e($registration['id']); ?></h1>
    <p class="page-subtitle">
        <a href="<?php echo BASE_URL; ?>/pages/registrations.php" style="color: #667eea;">← Back to Registrations</a>
    </p>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <!-- Main Content -->
    <div>
        <!-- Personal Information -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                👤 Personal Information
            </h2>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Full Name</div>
                    <div style="font-size: 15px; font-weight: 500; color: #1a202c;"><?php echo e($registration['full_name']); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Email</div>
                    <div style="font-size: 15px; color: #1a202c;">
                        <a href="mailto:<?php echo e($registration['email']); ?>" style="color: #667eea;">
                            <?php echo e($registration['email']); ?>
                        </a>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Mobile</div>
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e($registration['mobile']); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Date of Birth</div>
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e(formatDate($registration['dob'], 'F j, Y')); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Gender</div>
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e(ucfirst($registration['gender'])); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Nationality</div>
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e($registration['nationality']); ?></div>
                </div>
                <div style="grid-column: span 2;">
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Address</div>
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e($registration['address']); ?></div>
                </div>
            </div>
        </div>

        <!-- Exam Details -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                📝 Exam Details
            </h2>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Exam Level</div>
                    <div style="font-size: 15px; font-weight: 500; color: #1a202c;"><?php echo e($registration['exam_level']); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Test Date</div>
                    <div style="font-size: 15px; font-weight: 500; color: #1a202c;"><?php echo e(formatDate($registration['test_date'])); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Payment Method</div>
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e(ucfirst($registration['payment_method'])); ?></div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #718096; margin-bottom: 4px;">Submitted</div>
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e(date('F j, Y g:i A', strtotime($registration['created_at']))); ?></div>
                </div>
            </div>
        </div>

        <!-- Uploaded Documents -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                📎 Uploaded Documents
            </h2>

            <div style="display: grid; gap: 16px;">
                <!-- Photo -->
                <div>
                    <div style="font-size: 14px; font-weight: 500; color: #1a202c; margin-bottom: 8px;">Student Photo</div>
                    <?php if (!empty($registration['photo_storage_path'])): ?>
                        <img src="<?php echo e(intakePathToUrl($registration['photo_storage_path'])); ?>"
                             alt="Student Photo"
                             style="max-width: 200px; border: 2px solid #e2e8f0; border-radius: 8px;"
                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext fill=\'%23999\' x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\'%3EImage not found%3C/text%3E%3C/svg%3E'">
                    <?php else: ?>
                        <p style="color: #f56565; font-size: 14px;">No photo uploaded</p>
                    <?php endif; ?>
                </div>

                <!-- ID Document -->
                <div>
                    <div style="font-size: 14px; font-weight: 500; color: #1a202c; margin-bottom: 8px;">ID Document</div>
                    <?php if (!empty($registration['id_storage_path'])): ?>
                        <a href="<?php echo e(intakePathToUrl($registration['id_storage_path'])); ?>" target="_blank"
                           class="btn btn-secondary" style="padding: 8px 16px; font-size: 14px;">
                            📄 View ID Document
                        </a>
                    <?php else: ?>
                        <p style="color: #f56565; font-size: 14px;">No ID document uploaded</p>
                    <?php endif; ?>
                </div>

                <!-- Payment Receipt -->
                <div>
                    <div style="font-size: 14px; font-weight: 500; color: #1a202c; margin-bottom: 8px;">Payment Receipt</div>
                    <?php if (!empty($registration['payment_receipt_storage_path'])): ?>
                        <a href="<?php echo e(intakePathToUrl($registration['payment_receipt_storage_path'])); ?>" target="_blank"
                           class="btn btn-secondary" style="padding: 8px 16px; font-size: 14px;">
                            💰 View Payment Receipt
                        </a>
                    <?php else: ?>
                        <p style="color: #ed8936; font-size: 14px;">No payment receipt uploaded</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar: Actions -->
    <div>
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; position: sticky; top: 80px;">
            <h3 style="font-size: 16px; font-weight: 600; color: #1a202c; margin-bottom: 16px;">Review Decision</h3>

            <form id="reviewForm" method="POST" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 8px;">
                        Check all that apply:
                    </label>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="check_photo" id="check_photo">
                            <span>✓ Photo meets specifications</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="check_id" id="check_id">
                            <span>✓ ID document valid and readable</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="check_payment" id="check_payment">
                            <span>✓ Payment verified</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="check_info" id="check_info">
                            <span>✓ All information correct</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label for="rejection_reasons" style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 8px;">
                        Rejection Reasons (if rejecting):
                    </label>
                    <textarea id="rejection_reasons" name="rejection_reasons" rows="5"
                              placeholder="List the issues that need to be corrected..."
                              style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; resize: vertical;"></textarea>
                </div>

                <div style="border-top: 1px solid #e2e8f0; padding-top: 16px; margin-top: 8px;">
                    <button type="submit" name="action" value="approve"
                            class="btn btn-primary"
                            style="width: 100%; padding: 12px; font-size: 15px; font-weight: 600; margin-bottom: 8px;"
                            onclick="return confirmApprove()">
                        ✓ Approve Registration
                    </button>
                    <button type="submit" name="action" value="reject"
                            class="btn btn-danger"
                            style="width: 100%; padding: 12px; font-size: 15px; font-weight: 600;"
                            onclick="return confirmReject()">
                        ✗ Reject Registration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmApprove() {
    const checks = ['check_photo', 'check_id', 'check_payment', 'check_info'];
    const allChecked = checks.every(id => document.getElementById(id).checked);

    if (!allChecked) {
        alert('Please verify all checkboxes before approving:\n• Photo meets specifications\n• ID document valid\n• Payment verified\n• All information correct');
        return false;
    }

    return confirm('Are you sure you want to approve this registration?\n\nA confirmation email will be sent to the applicant.');
}

function confirmReject() {
    const reasons = document.getElementById('rejection_reasons').value.trim();

    if (!reasons) {
        alert('Please provide rejection reasons explaining what needs to be corrected.');
        return false;
    }

    return confirm('Are you sure you want to reject this registration?\n\nAn email will be sent to the applicant with the rejection reasons.');
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
