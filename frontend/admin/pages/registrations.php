<?php
/**
 * Registration Management Page
 * View, filter, approve/reject registrations
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Registrations';
$currentPage = 'registrations';

$conn = getDbConnection();

// Get filter parameters
$status = $_GET['status'] ?? '';
$examDate = $_GET['exam_date'] ?? '';
$examLevel = $_GET['exam_level'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where = ['1=1'];
$params = [];
$types = '';

if (!empty($status)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
    $types .= 's';
}

if (!empty($examDate)) {
    $where[] = 'r.test_date = ?';
    $params[] = $examDate;
    $types .= 's';
}

if (!empty($examLevel)) {
    $where[] = 'r.exam_level = ?';
    $params[] = $examLevel;
    $types .= 's';
}

if (!empty($dateFrom)) {
    $where[] = 'DATE(r.submitted_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if (!empty($dateTo)) {
    $where[] = 'DATE(r.submitted_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

if (!empty($search)) {
    $where[] = '(r.full_name LIKE ? OR r.email LIKE ? OR r.mobile LIKE ?)';
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$whereClause = implode(' AND ', $where);

// Get registrations
$query = "
    SELECT
        r.*,
        ed.exam_date
    FROM registrations r
    LEFT JOIN exam_dates ed ON r.test_date = ed.exam_date
    WHERE $whereClause
    ORDER BY r.submitted_at DESC
    LIMIT 50
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get exam dates for filter
$stmt = $conn->prepare("SELECT DISTINCT exam_date FROM exam_dates ORDER BY exam_date ASC");
$stmt->execute();
$examDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get status counts
$statusCounts = [
    'all' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM registrations GROUP BY status");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $statusCounts[$row['status']] = $row['count'];
    $statusCounts['all'] += $row['count'];
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Registrations</h1>
    <p class="page-subtitle">Manage registration applications</p>
</div>

<!-- Status Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px;">
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php" class="btn <?php echo $status === '' ? 'btn-primary' : 'btn-secondary'; ?>">
        All (<?php echo $statusCounts['all']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=pending" class="btn <?php echo $status === 'pending' ? 'btn-primary' : 'btn-secondary'; ?>">
        Pending (<?php echo $statusCounts['pending']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=approved" class="btn <?php echo $status === 'approved' ? 'btn-primary' : 'btn-secondary'; ?>">
        Approved (<?php echo $statusCounts['approved']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=rejected" class="btn <?php echo $status === 'rejected' ? 'btn-primary' : 'btn-secondary'; ?>">
        Rejected (<?php echo $statusCounts['rejected']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/api/registrations/export.php" class="btn btn-secondary" style="margin-left: auto;">
        📥 Export CSV
    </a>
</div>

<!-- Filters -->
<div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Search</label>
            <input type="text" name="search" placeholder="Name, email, mobile..." value="<?php echo e($search); ?>"
                   style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam Date</label>
            <select name="exam_date" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">All Dates</option>
                <?php foreach ($examDates as $date): ?>
                    <option value="<?php echo e($date['exam_date']); ?>" <?php echo $examDate === $date['exam_date'] ? 'selected' : ''; ?>>
                        <?php echo e(formatDate($date['exam_date'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam Level</label>
            <select name="exam_level" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">All Levels</option>
                <?php foreach (['1Q', '2Q', '3Q', '4Q', '5Q'] as $level): ?>
                    <option value="<?php echo e($level); ?>" <?php echo $examLevel === $level ? 'selected' : ''; ?>>
                        <?php echo e($level); ?>
                    </option>
                <?php endforeach; ?>
            </select>
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

<!-- Registrations Table -->
<?php if (!empty($registrations)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">ID</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Name</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Email</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Level</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Test Date</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Status</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Submitted</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $reg): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0; hover:bg-gray-50;">
                        <td style="padding: 12px 16px; font-size: 14px;">#<?php echo e($reg['id']); ?></td>
                        <td style="padding: 12px 16px; font-size: 14px; font-weight: 500;">
                            <?php echo e($reg['full_name']); ?>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;">
                            <a href="mailto:<?php echo e($reg['email']); ?>" style="color: #667eea; text-decoration: none;">
                                <?php echo e($reg['email']); ?>
                            </a>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;"><?php echo e($reg['exam_level']); ?></td>
                        <td style="padding: 12px 16px; font-size: 14px;"><?php echo e(formatDate($reg['test_date'])); ?></td>
                        <td style="padding: 12px 16px;">
                            <?php
                            $statusColors = [
                                'pending' => '#ed8936',
                                'approved' => '#48bb78',
                                'rejected' => '#f56565'
                            ];
                            ?>
                            <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?php echo $statusColors[$reg['status']]; ?>20; color: <?php echo $statusColors[$reg['status']]; ?>;">
                                <?php echo e(ucfirst($reg['status'])); ?>
                            </span>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px; color: #718096;">
                            <?php echo e(date('M j, Y', strtotime($reg['submitted_at']))); ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <a href="<?php echo BASE_URL; ?>/pages/registration-detail.php?id=<?php echo e($reg['id']); ?>"
                               class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                Review
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
        <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Registrations Found</h3>
        <p style="color: #718096; font-size: 14px;">Try adjusting your filters or check back later.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
