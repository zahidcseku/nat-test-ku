<?php
/**
 * Registration Management Page
 * View, filter, approve/reject registrations
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Registrations';
$currentPage = 'registrations';

$conn = getDbConnection();

/**
 * Get payment status CSS class for styling
 */
function getPaymentStatusClass($status) {
    switch($status) {
        case 'paid': return 'bg-success-container text-success-dark';
        case 'unpaid': return 'bg-warning-container text-warning-dark';
        case 'failed': return 'bg-error-container text-error-dark';
        case 'refunded': return 'bg-primary-container text-primary-dark';
        default: return 'bg-surface-container text-secondary';
    }
}

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
    if ($status === 'pending') {
        $where[] = '(r.approved IS NULL OR r.approved = 0)';
    } elseif ($status === 'approved') {
        $where[] = 'r.approved = 1';
    } elseif ($status === 'rejected') {
        $where[] = 'r.approved = 0'; // For now, rejected is same as pending
    }
}

if (!empty($examDate)) {
    $where[] = 'r.test_date = ?';
    $params[] = $examDate;
    $types .= 's';
}

if (!empty($examLevel)) {
    $where[] = 'r.exam_level LIKE ?';
    $params[] = "%$examLevel%";
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

// Get registrations (paginated)
$query = "
    SELECT
        r.*,
        ed.exam_date
    FROM registrations r
    LEFT JOIN exam_dates ed ON r.test_date = ed.exam_date
    WHERE $whereClause
    ORDER BY r.submitted_at DESC
";

$countQuery = "
    SELECT COUNT(*) AS cnt
    FROM registrations r
    WHERE $whereClause
";

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, [25, 50, 100, 200], true)) {
    $perPage = 50;
}

$paginator = paginateQuery($query, $countQuery, $params, $types, $page, $perPage);
$registrations = $paginator['rows'];

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

// Get status counts based on approved column
$stmt = $conn->prepare("
    SELECT
        COUNT(*) as all_count,
        SUM(CASE WHEN approved IS NULL OR approved = 0 THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN approved = 1 THEN 1 ELSE 0 END) as approved_count
    FROM registrations
");
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$statusCounts['all'] = $result['all_count'];
$statusCounts['pending'] = $result['pending_count'];
$statusCounts['approved'] = $result['approved_count'];
$statusCounts['rejected'] = 0; // Will be implemented later

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Registrations</h1>
    <p class="page-subtitle">Manage registration applications</p>
</div>

<!-- Status Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px;">
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php<?php echo $page > 1 ? '?page=' . $page : ''; ?>" class="btn <?php echo $status === '' ? 'btn-primary' : 'btn-secondary'; ?>">
        All (<?php echo $statusCounts['all']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=pending<?php echo $page > 1 ? '&amp;page=' . $page : ''; ?>" class="btn <?php echo $status === 'pending' ? 'btn-primary' : 'btn-secondary'; ?>">
        Pending (<?php echo $statusCounts['pending']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=approved<?php echo $page > 1 ? '&amp;page=' . $page : ''; ?>" class="btn <?php echo $status === 'approved' ? 'btn-primary' : 'btn-secondary'; ?>">
        Approved (<?php echo $statusCounts['approved']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=rejected<?php echo $page > 1 ? '&amp;page=' . $page : ''; ?>" class="btn <?php echo $status === 'rejected' ? 'btn-primary' : 'btn-secondary'; ?>">
        Rejected (<?php echo $statusCounts['rejected']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/api/registrations/export.php" class="btn btn-secondary">
        📥 Export CSV
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registration-sheet.php" class="btn btn-secondary" style="margin-left: auto;">
        📋 Registration Sheet
    </a>
</div>

<?php
// Active-filter chip so it is always obvious which status is filtered.
$activeStatus = null;
if ($status === 'pending')  $activeStatus = 'Pending';
elseif ($status === 'approved') $activeStatus = 'Approved';
elseif ($status === 'rejected') $activeStatus = 'Rejected';

$activeFilters = [];
if ($activeStatus)  $activeFilters['Status']   = [$activeStatus, 'status'];
if (!empty($search))    $activeFilters['Search']   = [$search, 'search'];
if (!empty($examDate))  $activeFilters['Exam Date'] = [formatDate($examDate), 'exam_date'];
if (!empty($examLevel)) $activeFilters['Exam Level'] = [$examLevel, 'exam_level'];
if (!empty($dateFrom))  $activeFilters['From']      = [$dateFrom, 'date_from'];
if (!empty($dateTo))    $activeFilters['To']        = [$dateTo, 'date_to'];

if (!empty($activeFilters)):
    // Build "remove this filter" URL by dropping one query param and resetting page.
    $removeUrl = function($keyToRemove) use ($status, $examDate, $examLevel, $dateFrom, $dateTo, $search, $perPage) {
        $params = array_filter([
            'status'     => $status,
            'exam_date'  => $examDate,
            'exam_level' => $examLevel,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'search'     => $search,
            'per_page'   => $perPage === 50 ? null : $perPage,
        ]);
        unset($params[$keyToRemove]);
        $qs = http_build_query($params, '', '&amp;');
        return $qs !== '' ? '?' . $qs : '';
    };

    $clearAllParams = array_filter([
        'per_page' => $perPage === 50 ? null : $perPage,
    ]);
    $clearAllQs = http_build_query($clearAllParams, '', '&amp;');
    $clearAllUrl = $clearAllQs !== '' ? '?' . $clearAllQs : '';
    ?>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; padding: 12px 16px; background: #fefcbf; border: 1px solid #f6e05e; border-radius: 8px;">
        <span style="font-size: 13px; color: #744210; font-weight: 600;">Active filters:</span>
        <?php foreach ($activeFilters as $label => [$value, $key]): ?>
            <a href="<?php echo BASE_URL; ?>/pages/registrations.php<?php echo $removeUrl($key); ?>"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: white; border: 1px solid #f6e05e; border-radius: 12px; font-size: 12px; color: #744210; text-decoration: none;">
                <strong><?php echo e($label); ?>:</strong> <?php echo e($value); ?>
                <span style="color: #c53030; font-weight: 700;">✕</span>
            </a>
        <?php endforeach; ?>
        <a href="<?php echo BASE_URL; ?>/pages/registrations.php<?php echo $clearAllUrl; ?>"
           style="font-size: 12px; color: #c53030; text-decoration: underline;">Clear all</a>
    </div>
<?php endif; ?>

<!-- Filters -->
<div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
        <?php if (!empty($status)): ?>
            <input type="hidden" name="status" value="<?php echo e($status); ?>">
        <?php endif; ?>
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
                <?php foreach (['1Q/N1', '2Q/N2', '3Q/N3', '4Q/N4', '5Q/N5'] as $level): ?>
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
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Name</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Email</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Level</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Test Date</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Status</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Payment Status</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Payment Time</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $reg): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0; hover:bg-gray-50;">
                        <td style="padding: 12px 16px; font-size: 14px; font-weight: 500;">
                            <a href="<?php echo BASE_URL; ?>/pages/registration-detail.php?id=<?php echo e($reg['id']); ?>"
                               style="color: #1a202c; text-decoration: none;">
                                <?php echo e($reg['full_name']); ?>
                            </a>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;">
                            <a href="mailto:<?php echo e($reg['email']); ?>" style="color: #667eea; text-decoration: none;">
                                <?php echo e($reg['email']); ?>
                            </a>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;">
                            <div class="font-semibold"><?php echo e($reg['exam_level']); ?></div>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;"><?php echo e(formatDate($reg['test_date'])); ?></td>
                        <td style="padding: 12px 16px;">
                            <?php
                            // Determine row status from approved column.
                            // IMPORTANT: use a local name ($rowStatus) — reusing $status
                            // here would clobber the outer filter $status used by the
                            // status tabs, active-filter chip, and pagination URLs.
                            $rowStatus = 'pending';
                            if ($reg['approved'] == 1) {
                                $rowStatus = 'approved';
                            }
                            $statusColors = [
                                'pending' => '#ed8936',
                                'approved' => '#48bb78',
                                'rejected' => '#f56565'
                            ];
                            ?>
                            <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?php echo $statusColors[$rowStatus]; ?>20; color: <?php echo $statusColors[$rowStatus]; ?>;">
                                <?php echo e(ucfirst($rowStatus)); ?>
                            </span>
                        </td>
                        <td class="p-4">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo getPaymentStatusClass($reg['payment_status']); ?>">
                                <?php echo ucfirst($reg['payment_status']); ?>
                            </span>
                        </td>
                        <td class="p-4"><?php echo $reg['payment_time'] ? date('M j, Y', strtotime($reg['payment_time'])) : '-'; ?></td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <div style="display: flex; gap: 6px; justify-content: center;">
                                <a href="<?php echo BASE_URL; ?>/pages/registration-detail.php?id=<?php echo e($reg['id']); ?>"
                                   class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                    👁️ View
                                </a>
                                <a href="<?php echo BASE_URL; ?>/pages/registration-detail.php?id=<?php echo e($reg['id']); ?>#edit"
                                   class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                    ✏️ Edit
                                </a>
                                <?php if (isSuperAdmin()): ?>
                                <form method="POST" action="<?php echo BASE_URL; ?>/api/registrations/delete.php"
                                      style="display: inline;"
                                      onsubmit="return confirm('Permanently delete this registration and its uploaded files? This cannot be undone.')">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo e($reg['id']); ?>">
                                    <input type="hidden" name="return_query" value="<?php echo e($_SERVER['QUERY_STRING'] ?? ''); ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;">
                                        🗑 Delete
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    // Pagination control — preserve every active filter in page links.
    $paginationParams = array_filter([
        'status'     => $status,
        'exam_date'  => $examDate,
        'exam_level' => $examLevel,
        'date_from'  => $dateFrom,
        'date_to'    => $dateTo,
        'search'     => $search,
        'per_page'   => $perPage === 50 ? null : $perPage,
    ]);
    echo renderPagination(
        $paginator['page'],
        $paginator['totalPages'],
        $paginator['total'],
        $paginator['perPage'],
        BASE_URL . '/pages/registrations.php',
        $paginationParams
    );
    ?>
<?php else: ?>
    <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
        <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Registrations Found</h3>
        <p style="color: #718096; font-size: 14px;">Try adjusting your filters or check back later.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
