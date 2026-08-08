<?php
/**
 * Email Management Page
 * View email history, resend failed emails
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Email Management';
$currentPage = 'emails';

$conn = getDbConnection();

// Get filter parameters
$emailType = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where = ['1=1'];
$params = [];
$types = '';

if (!empty($emailType)) {
    $where[] = 'email_type = ?';
    $params[] = $emailType;
    $types .= 's';
}

if (!empty($dateFrom)) {
    $where[] = 'DATE(sent_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if (!empty($dateTo)) {
    $where[] = 'DATE(sent_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

if (!empty($search)) {
    $where[] = '(recipient_email LIKE ? OR subject LIKE ?)';
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ss';
}

$whereClause = implode(' AND ', $where);

// Paginated email log entries (no LIMIT — paginateQuery adds it).
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$dataQuery = "
    SELECT
        el.*,
        r.full_name,
        r.exam_level,
        au.username as sent_by_username
    FROM email_log el
    LEFT JOIN registrations r ON el.registration_id = r.id
    LEFT JOIN admin_users au ON el.sent_by = au.id
    WHERE $whereClause
    ORDER BY el.sent_at DESC
";
$countQuery = "
    SELECT COUNT(*) AS cnt
    FROM email_log el
    WHERE $whereClause
";

$paginator = paginateQuery($dataQuery, $countQuery, $params, $types, $page, $perPage);
$emails    = $paginator['rows'];

// Get email statistics
$stmt = $conn->prepare("
    SELECT
        email_type,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM email_log
    GROUP BY email_type
");
$stmt->execute();
$stats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate overall stats
$totalSent = 0;
$totalFailed = 0;
foreach ($stats as $stat) {
    $totalSent += $stat['sent'];
    $totalFailed += $stat['failed'];
}
$successRate = ($totalSent + $totalFailed) > 0 ? round(($totalSent / ($totalSent + $totalFailed)) * 100, 1) : 0;

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Email Management</h1>
    <p class="page-subtitle">View email history and resend failed emails</p>
</div>

<!-- Statistics -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 13px; color: #718096; margin-bottom: 4px;">Total Emails</div>
        <div style="font-size: 32px; font-weight: 700; color: #1a202c;"><?php echo number_format($totalSent + $totalFailed); ?></div>
    </div>
    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 13px; color: #718096; margin-bottom: 4px;">Successfully Sent</div>
        <div style="font-size: 32px; font-weight: 700; color: #48bb78;"><?php echo number_format($totalSent); ?></div>
    </div>
    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 13px; color: #718096; margin-bottom: 4px;">Failed</div>
        <div style="font-size: 32px; font-weight: 700; color: #f56565;"><?php echo number_format($totalFailed); ?></div>
    </div>
    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 13px; color: #718096; margin-bottom: 4px;">Success Rate</div>
        <div style="font-size: 32px; font-weight: 700; color: #667eea;"><?php echo $successRate; ?>%</div>
    </div>
</div>

<!-- Filters -->
<div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Email Type</label>
            <select name="type" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">All Types</option>
                <?php
                $typeOptions = [
                    'confirmation'         => 'Confirmation',
                    'rejection'            => 'Rejection',
                    'admission_ticket'     => 'Admission Ticket',
                    'score_report'         => 'Score Report',
                    'broadcast'            => 'Broadcast',
                    'submission_receipt'   => 'Submission Receipt',
                    'payment_confirmation' => 'Payment Confirmation',
                    'certificate_requested'=> 'Certificate Requested',
                    'certificate_posted'   => 'Certificate Posted',
                    'resend'               => 'Resend',
                ];
                foreach ($typeOptions as $value => $label):
                ?>
                    <option value="<?php echo e($value); ?>" <?php echo $emailType === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Search</label>
            <input type="text" name="search" placeholder="Email or subject..." value="<?php echo e($search); ?>"
                   style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">From Date</label>
            <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>"
                   style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">To Date</label>
            <input type="date" name="date_to" value="<?php echo e($dateTo); ?>"
                   style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
        </div>

        <div style="display: flex; align-items: end;">
            <button type="submit" class="btn btn-primary" style="width: 100%;">Apply Filters</button>
        </div>
    </form>
</div>

<!-- Email History Table -->
<?php if (!empty($emails)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Type</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Recipient</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Subject</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Status</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Error</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Sent</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emails as $email): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 16px; font-size: 14px;">
                            <?php
                            $typeColors = [
                                'confirmation'         => '#48bb78',
                                'rejection'            => '#f56565',
                                'admission_ticket'     => '#667eea',
                                'score_report'         => '#9f7aea',
                                'broadcast'            => '#ed64a6',
                                'submission_receipt'   => '#38b2ac',
                                'payment_confirmation' => '#2b6cb0',
                                'certificate_requested'=> '#4299e1',
                                'certificate_posted'   => '#2f855a',
                                'resend'               => '#ed8936',
                            ];
                            $typeLabels = [
                                'confirmation'         => 'Confirmation',
                                'rejection'            => 'Rejection',
                                'admission_ticket'     => 'Admission Ticket',
                                'score_report'         => 'Score Report',
                                'broadcast'            => 'Broadcast',
                                'submission_receipt'   => 'Submission Receipt',
                                'payment_confirmation' => 'Payment Confirmation',
                                'certificate_requested'=> 'Certificate Requested',
                                'certificate_posted'   => 'Certificate Posted',
                                'resend'               => 'Resend',
                            ];
                            ?>
                            <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?php echo $typeColors[$email['email_type']] ?? '#718096'; ?>20; color: <?php echo $typeColors[$email['email_type']] ?? '#718096'; ?>;">
                                <?php echo e($typeLabels[$email['email_type']] ?? ucfirst($email['email_type'])); ?>
                            </span>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;">
                            <a href="mailto:<?php echo e($email['recipient_email']); ?>" style="color: #667eea; text-decoration: none;">
                                <?php echo e($email['recipient_email']); ?>
                            </a>
                            <?php if (!empty($email['full_name'])): ?>
                                <div style="font-size: 12px; color: #718096;"><?php echo e($email['full_name']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;">
                            <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo e($email['subject']); ?>
                            </div>
                        </td>
                        <td style="padding: 12px 16px;">
                            <?php if ($email['status'] === 'sent'): ?>
                                <span style="color: #48bb78; font-size: 13px;">✓ Sent</span>
                            <?php else: ?>
                                <span style="color: #f56565; font-size: 13px;">✗ Failed</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 16px; font-size: 12px; color: #c53030; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($email['error_message'] ?? ''); ?>">
                            <?php echo e($email['error_message'] ?? ''); ?>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px; color: #718096;">
                            <?php echo e(date('M j, Y g:i A', strtotime($email['sent_at']))); ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <?php if ($email['status'] === 'failed'): ?>
                                <form method="POST" action="<?php echo BASE_URL; ?>/api/emails/resend.php" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                    <input type="hidden" name="email_id" value="<?php echo e($email['id']); ?>">
                                    <button type="submit" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                        🔄 Resend
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;"
                                        onclick="alert('Email sent by: <?php echo e($email['sent_by_username'] ?? 'System'); ?>')">
                                    ℹ️ Info
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($email['error_message'])): ?>
                        <tr style="background: #fed7d7;">
                            <td colspan="6" style="padding: 8px 16px; font-size: 12px; color: #c53030;">
                                <strong>Error:</strong> <?php echo e($email['error_message']); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    echo renderPagination(
        $paginator['page'],
        $paginator['totalPages'],
        $paginator['total'],
        $paginator['perPage'],
        BASE_URL . '/pages/emails.php',
        array_filter([
            'type'      => $emailType,
            'search'    => $search,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ])
    );
    ?>
<?php else: ?>
    <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
        <div style="font-size: 48px; margin-bottom: 16px;">📧</div>
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Emails Found</h3>
        <p style="color: #718096; font-size: 14px;">No email history matches your filters.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
