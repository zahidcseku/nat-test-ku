<?php
/**
 * Registration Detail Page
 * Review individual registration, approve/reject with email
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Review Registration';
$currentPage = 'registrations';

$id = $_GET['id'] ?? '';

if (empty($id)) {
    die('Registration ID is required');
}

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
$stmt->bind_param('s', $id);
$stmt->execute();
$registration = $stmt->get_result()->fetch_assoc();

if (!$registration) {
    die('Registration not found');
}

// Handle form actions

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reasons = $_POST['rejection_reasons'] ?? '';

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('Invalid CSRF token', 'error');
    } elseif ($action === 'update') {
        // Handle registration information update
        $updateFields = [
            'full_name' => $_POST['full_name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'mobile' => $_POST['mobile'] ?? '',
            'address' => $_POST['address'] ?? '',
            'dob' => $_POST['dob'] ?? '',
            'gender' => $_POST['gender'] ?? '',
            'nationality' => $_POST['nationality'] ?? '',
            'exam_level' => $_POST['exam_level'] ?? '',
            'test_date' => $_POST['test_date'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? ''
        ];

        // Validate required fields
        $required = ['full_name', 'email', 'mobile', 'address', 'dob', 'gender', 'nationality', 'exam_level', 'test_date', 'payment_method'];
        foreach ($required as $field) {
            if (empty($updateFields[$field])) {
                setFlashMessage("Field '$field' is required", 'error');
                header('Location: ' . BASE_URL . '/pages/registration-detail.php?id=' . $id);
                exit;
            }
        }

        // Process optional file uploads (photo / ID document).
        // Validated before anything is moved or saved — on error, redirect.
        $photoResult = null;
        $idResult = null;
        $fileErrors = [];

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $photoResult = processApplicantFileUpload($_FILES['photo'], 'photos', APPLICANT_IMAGE_TYPES);
            if (!$photoResult['success']) {
                $fileErrors[] = 'Photo: ' . $photoResult['error'];
                $photoResult = null;
            }
        }

        if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] !== UPLOAD_ERR_NO_FILE) {
            $idResult = processApplicantFileUpload(
                $_FILES['id_document'],
                'ids',
                array_merge(APPLICANT_IMAGE_TYPES, APPLICANT_PDF_TYPES)
            );
            if (!$idResult['success']) {
                $fileErrors[] = 'ID document: ' . $idResult['error'];
                $idResult = null;
            }
        }

        if (!empty($fileErrors)) {
            // Clean up any files already moved before the error was hit
            if ($photoResult) {
                safeUnlinkIntakeUpload($photoResult['storage_path']);
            }
            if ($idResult) {
                safeUnlinkIntakeUpload($idResult['storage_path']);
            }
            setFlashMessage('Could not upload files: ' . implode('; ', $fileErrors), 'error');
            header('Location: ' . BASE_URL . '/pages/registration-detail.php?id=' . $id);
            exit;
        }

        // Store old values for audit
        $oldValues = [];
        foreach ($updateFields as $field => $value) {
            $oldValues[$field] = $registration[$field] ?? '';
        }
        $oldPhotoPath = $registration['photo_storage_path'] ?? '';
        $oldIdPath = $registration['id_storage_path'] ?? '';
        if ($photoResult) {
            $oldValues['photo_filename'] = $registration['photo_filename'] ?? '';
        }
        if ($idResult) {
            $oldValues['id_filename'] = $registration['id_filename'] ?? '';
        }

        // Build update query
        $setClause = [];
        $types = '';
        $params = [];

        foreach ($updateFields as $field => $value) {
            if (strlen($value) > 0) {
                $setClause[] = "$field = ?";
                $params[] = $value;
                $types .= 's';
            }
        }

        // Append file columns when new uploads were provided
        $newValues = $updateFields;
        if ($photoResult) {
            $setClause[] = "photo_filename = ?";
            $setClause[] = "photo_storage_path = ?";
            $setClause[] = "photo_size_bytes = ?";
            $params[] = $photoResult['filename'];
            $params[] = $photoResult['storage_path'];
            $params[] = $photoResult['size_bytes'];
            $types .= 'ssi';
            $newValues['photo_filename'] = $photoResult['filename'];
        }
        if ($idResult) {
            $setClause[] = "id_filename = ?";
            $setClause[] = "id_storage_path = ?";
            $setClause[] = "id_size_bytes = ?";
            $params[] = $idResult['filename'];
            $params[] = $idResult['storage_path'];
            $params[] = $idResult['size_bytes'];
            $types .= 'ssi';
            $newValues['id_filename'] = $idResult['filename'];
        }

        if (!empty($setClause)) {
            $params[] = $id; // Add ID for WHERE clause
            $types .= 's';

            $sql = "UPDATE registrations SET " . implode(', ', $setClause) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                if ($photoResult) { safeUnlinkIntakeUpload($photoResult['storage_path']); }
                if ($idResult) { safeUnlinkIntakeUpload($idResult['storage_path']); }
                setFlashMessage('Failed to prepare statement: ' . $conn->error, 'error');
            } else {
                $stmt->bind_param($types, ...$params);

                if ($stmt->execute()) {
                    // DB now points at the new files — safe to remove the old ones
                    if ($photoResult) {
                        safeUnlinkIntakeUpload($oldPhotoPath);
                    }
                    if ($idResult) {
                        safeUnlinkIntakeUpload($oldIdPath);
                    }

                    // Log audit
                    logAudit('update_registration', 'registrations', $id, $oldValues, $newValues);

                    $fileNote = '';
                    if ($photoResult && $idResult) {
                        $fileNote = ' (photo and ID document replaced)';
                    } elseif ($photoResult) {
                        $fileNote = ' (photo replaced)';
                    } elseif ($idResult) {
                        $fileNote = ' (ID document replaced)';
                    }

                    setFlashMessage('Registration information updated successfully' . $fileNote, 'success');
                    header('Location: ' . BASE_URL . '/pages/registration-detail.php?id=' . $id);
                    exit;
                } else {
                    // DB unchanged — remove the newly uploaded files to avoid orphans
                    if ($photoResult) { safeUnlinkIntakeUpload($photoResult['storage_path']); }
                    if ($idResult) { safeUnlinkIntakeUpload($idResult['storage_path']); }
                    setFlashMessage('Failed to update registration: ' . $stmt->error, 'error');
                }
            }
        } else {
            setFlashMessage('No changes to update', 'error');
        }

    } elseif ($action === 'approve') {
        // Update status
        $stmt = $conn->prepare("UPDATE registrations SET approved = 1, approved_at = NOW() WHERE id = ?");
        $stmt->bind_param('s', $id);
        $stmt->execute();

        // Log audit
        logAudit('approve_registration', 'registrations', $id, ['approved' => 0], ['approved' => 1]);

        // Send confirmation email
        require_once __DIR__ . '/../lib/email-templates.php';
        $email = renderEmailTemplate('confirmation', [
            'full_name'  => $registration['full_name'],
            'exam_level' => $registration['exam_level'],
            'test_date'  => formatDate($registration['test_date']),
        ]);
        sendEmail($registration['email'], $email['subject'], $email['body'], $id, 'confirmation');

        setFlashMessage('Registration approved and confirmation email sent', 'success');
        header('Location: ' . BASE_URL . '/pages/registrations.php');
        exit;

    } elseif ($action === 'reject') {
        // For rejection, we'll keep approved = 0 but could add notes
        // For now, let's just redirect with a message since rejection tracking needs schema update
        setFlashMessage('Rejection tracking requires schema updates. Please contact applicant directly.', 'error');
        header('Location: ' . BASE_URL . '/pages/registrations.php');
        exit;

    } elseif ($action === 'delete') {
        // Destructive: super admins only (UI hides the button, but enforce here)
        if (!isSuperAdmin()) {
            setFlashMessage('Only a super admin can delete registrations', 'error');
            header('Location: ' . BASE_URL . '/pages/registration-detail.php?id=' . $id);
            exit;
        }
        // The helper audit-logs the deletion with the row's key fields
        $deleteResult = deleteRegistrationCompletely($id);
        setFlashMessage($deleteResult['message'], $deleteResult['success'] ? 'success' : 'error');
        header('Location: ' . BASE_URL . '/pages/registrations.php');
        exit;
    }
}

require_once __DIR__ . '/../templates/header.php';

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
                    <div style="font-size: 15px; color: #1a202c;"><?php echo e(date('F j, Y g:i A', strtotime($registration['submitted_at']))); ?></div>
                </div>
            </div>
        </div>

        <!-- Uploaded Documents -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                📎 Uploaded Documents
            </h2>

            <div style="display: grid; gap: 16px;">
                <!-- Photo + ID side by side -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <!-- Photo -->
                    <div>
                        <div style="font-size: 14px; font-weight: 500; color: #1a202c; margin-bottom: 8px;">Student Photo</div>
                        <?php if (!empty($registration['photo_storage_path'])): ?>
                            <a href="<?php echo e(intakePathToUrl($registration['photo_storage_path'])); ?>" target="_blank">
                                <img src="<?php echo e(intakePathToUrl($registration['photo_storage_path'])); ?>"
                                     alt="Student Photo"
                                     style="max-width: 100%; max-height: 300px; border: 2px solid #e2e8f0; border-radius: 8px; object-fit: contain;"
                                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext fill=\'%23999\' x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\'%3EImage not found%3C/text%3E%3C/svg%3E'">
                            </a>
                        <?php else: ?>
                            <p style="color: #f56565; font-size: 14px;">No photo uploaded</p>
                        <?php endif; ?>
                    </div>

                    <!-- ID Document -->
                    <div>
                        <div style="font-size: 14px; font-weight: 500; color: #1a202c; margin-bottom: 8px;">ID Document</div>
                        <?php
                        $idTypeLabels = ['passport' => 'Passport', 'national_id' => 'National ID'];
                        $idTypeLabel = $idTypeLabels[$registration['id_document_type'] ?? ''] ?? null;
                        ?>
                        <div style="font-size: 13px; color: #4a5568; margin-bottom: 8px;">
                            <?php if ($idTypeLabel): ?>
                                <?php echo e($idTypeLabel); ?> &middot; <?php echo e($registration['id_document_number'] ?? ''); ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($registration['id_storage_path'])): ?>
                            <?php
                            $idExt = strtolower(pathinfo($registration['id_storage_path'], PATHINFO_EXTENSION));
                            $idUrl = intakePathToUrl($registration['id_storage_path']);
                            if (in_array($idExt, ['jpg', 'jpeg', 'png', 'gif'], true)): ?>
                                <a href="<?php echo e($idUrl); ?>" target="_blank">
                                    <img src="<?php echo e($idUrl); ?>"
                                         alt="ID Document"
                                         style="max-width: 100%; max-height: 300px; border: 2px solid #e2e8f0; border-radius: 8px; object-fit: contain;"
                                         onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3Crect fill=\'%23ddd\' width=\'200\' height=\'200\'/%3E%3Ctext fill=\'%23999\' x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\'%3EImage not found%3C/text%3E%3C/svg%3E'">
                                </a>
                            <?php else: ?>
                                <iframe src="<?php echo e($idUrl); ?>"
                                        style="width: 100%; height: 300px; border: 2px solid #e2e8f0; border-radius: 8px;"></iframe>
                                <a href="<?php echo e($idUrl); ?>" target="_blank"
                                   class="btn btn-secondary" style="display: inline-block; margin-top: 8px; padding: 6px 12px; font-size: 13px;">
                                    Open in new tab
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="color: #f56565; font-size: 14px;">No ID document uploaded</p>
                        <?php endif; ?>
                    </div>
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

        <!-- Edit Registration Form -->
        <div id="edit" style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
                <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin: 0;">
                    ✏️ Edit Registration Information
                </h2>
                <button type="button" onclick="toggleEditForm()" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                    Show/Hide Form
                </button>
            </div>

            <form id="editForm" method="POST" enctype="multipart/form-data" style="display: none; margin-top: 16px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="action" value="update">

                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Full Name *</label>
                        <input type="text" name="full_name" value="<?php echo e($registration['full_name']); ?>" required
                               style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Email *</label>
                        <input type="email" name="email" value="<?php echo e($registration['email']); ?>" required
                               style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Mobile *</label>
                        <input type="text" name="mobile" value="<?php echo e($registration['mobile']); ?>" required
                               style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Date of Birth *</label>
                        <input type="date" name="dob" value="<?php echo e($registration['dob']); ?>" required
                               style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Gender *</label>
                        <select name="gender" required style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                            <option value="male" <?php echo $registration['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo $registration['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo $registration['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Nationality *</label>
                        <input type="text" name="nationality" value="<?php echo e($registration['nationality']); ?>" required
                               style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam Level *</label>
                        <select name="exam_level" required style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                            <?php foreach (['1Q/N1', '2Q/N2', '3Q/N3', '4Q/N4', '5Q/N5'] as $level): ?>
                                <option value="<?php echo e($level); ?>" <?php echo $registration['exam_level'] === $level ? 'selected' : ''; ?>>
                                    <?php echo e($level); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Test Date *</label>
                        <input type="date" name="test_date" value="<?php echo e($registration['test_date']); ?>" required
                               style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Payment Method *</label>
                        <select name="payment_method" required style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                            <option value="online" <?php echo $registration['payment_method'] === 'online' ? 'selected' : ''; ?>>Online Payment</option>
                            <option value="offline" <?php echo $registration['payment_method'] === 'offline' ? 'selected' : ''; ?>>Offline Payment</option>
                        </select>
                    </div>

                    <div style="grid-column: span 2;">
                        <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Address *</label>
                        <textarea name="address" rows="3" required
                                  style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; resize: vertical;"><?php echo e($registration['address']); ?></textarea>
                    </div>

                    <div style="grid-column: span 2; padding-top: 16px; margin-top: 8px; border-top: 1px solid #e2e8f0;">
                        <div style="font-size: 14px; font-weight: 600; color: #1a202c; margin-bottom: 12px;">Replace Uploaded Files (optional)</div>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Replace Photo</label>
                                <input type="file" name="photo" id="edit_photo" accept="image/jpeg,image/png,image/jpg"
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                                <div style="font-size: 12px; color: #718096; margin-top: 4px;">JPG or PNG, max 5MB. Leave empty to keep current photo.</div>
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Replace ID Document</label>
                                <input type="file" name="id_document" id="edit_id_document" accept="image/jpeg,image/png,image/jpg,application/pdf"
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                                <div style="font-size: 12px; color: #718096; margin-top: 4px;">JPG, PNG, or PDF, max 5MB. Leave empty to keep current document.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                    <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-size: 14px; font-weight: 600;"
                            onclick="return confirmUpdate()">
                        💾 Save Changes
                    </button>
                    <button type="button" onclick="toggleEditForm()" class="btn btn-secondary" style="padding: 10px 20px; font-size: 14px;">
                        Cancel
                    </button>
                </div>
            </form>
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

            <?php if (isSuperAdmin()): ?>
            <form method="POST" onsubmit="return confirmDelete()" style="margin-top: 8px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <button type="submit" name="action" value="delete" class="btn btn-danger"
                        style="width: 100%; padding: 12px; font-size: 15px; font-weight: 600; background: #c53030;">
                    🗑 Delete Registration Permanently
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Auto-open edit form if URL has #edit hash
if (window.location.hash === '#edit') {
    document.addEventListener('DOMContentLoaded', function() {
        toggleEditForm();
    });
}

function toggleEditForm() {
    const form = document.getElementById('editForm');
    if (form.style.display === 'none') {
        form.style.display = 'block';
        // Scroll to form if it was just opened
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        form.style.display = 'none';
    }
}

function confirmUpdate() {
    var msg = 'Are you sure you want to update this registration information?\n\nThe changes will be logged in the audit trail.';
    var photo = document.getElementById('edit_photo');
    var idDoc = document.getElementById('edit_id_document');
    if ((photo && photo.files.length) || (idDoc && idDoc.files.length)) {
        msg += '\n\nSelected files will replace the existing uploads.';
    }
    return confirm(msg);
}

function confirmDelete() {
    return confirm('Permanently delete this registration and its uploaded files? This cannot be undone.');
}

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
