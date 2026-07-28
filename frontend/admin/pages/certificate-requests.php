<?php
/**
 * Certificate Requests — admin list page.
 *
 * Lists certificate_requests joined to registrations + exam_dates, with
 * filters (exam date, payment status, certificate status, search).
 * Per-row actions: "Mark Posted" (only when paid + requested), optional
 * tracking number, and a checkbox for batch mail-label printing.
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Certificates';
$currentPage = 'certificate-requests';

$conn = getDbConnection();
if (!$conn) {
    require_once __DIR__ . '/../templates/header.php';
    echo '<div class="alert alert-error">Database connection failed.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// --- Query params ---------------------------------------------------
$selectedExamDateId = trim($_GET['exam_date_id'] ?? '');
$paymentFilter      = $_GET['payment_status'] ?? '';   // '' | paid | unpaid | failed | refunded
$certFilter         = $_GET['certificate_status'] ?? ''; // '' | requested | posted
$search             = trim($_GET['search'] ?? '');
$page               = max(1, (int) ($_GET['page'] ?? 1));
$perPage            = 50;

// --- Past exams for the filter dropdown (only exams with score reports) ---
$examDates = [];
$stmt = $conn->prepare("
    SELECT DISTINCT ed.id, ed.exam_date
    FROM exam_dates ed
    INNER JOIN score_reports sr ON sr.exam_date_id = ed.id
    WHERE ed.exam_date < CURDATE()
    ORDER BY ed.exam_date DESC
");
if ($stmt) {
    $stmt->execute();
    $examDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// --- Build WHERE clauses -------------------------------------------
$where = ['1=1'];
$params = [];
$types = '';

if ($selectedExamDateId !== '') {
    $where[] = 'cr.exam_date_id = ?';
    $params[] = $selectedExamDateId;
    $types .= 's';
}
if (in_array($paymentFilter, ['paid', 'unpaid', 'failed', 'refunded'], true)) {
    $where[] = 'cr.payment_status = ?';
    $params[] = $paymentFilter;
    $types .= 's';
}
if (in_array($certFilter, ['requested', 'posted'], true)) {
    $where[] = 'cr.certificate_status = ?';
    $params[] = $certFilter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(r.full_name LIKE ? OR cr.reg_no LIKE ? OR cr.recipient_phone LIKE ? OR r.email LIKE ?)';
    $term = "%{$search}%";
    array_push($params, $term, $term, $term, $term);
    $types .= 'ssss';
}

$whereClause = implode(' AND ', $where);

// --- Counts for summary chips --------------------------------------
$counts = [
    'total'             => 0,
    'paid_requested'    => 0,
    'posted'            => 0,
    'unpaid_or_failed'  => 0,
];
$countsStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN payment_status = 'paid' AND certificate_status = 'requested' THEN 1 ELSE 0 END) AS paid_requested,
        SUM(CASE WHEN certificate_status = 'posted' THEN 1 ELSE 0 END) AS posted,
        SUM(CASE WHEN payment_status IN ('unpaid','failed') THEN 1 ELSE 0 END) AS unpaid_or_failed
    FROM certificate_requests cr
    LEFT JOIN registrations r ON r.id = cr.registration_id
    WHERE {$whereClause}
");
if ($countsStmt) {
    if ($types !== '') {
        $countsStmt->bind_param($types, ...$params);
    }
    $countsStmt->execute();
    $crow = $countsStmt->get_result()->fetch_assoc();
    if ($crow) {
        $counts['total']            = (int) $crow['total'];
        $counts['paid_requested']   = (int) $crow['paid_requested'];
        $counts['posted']           = (int) $crow['posted'];
        $counts['unpaid_or_failed'] = (int) $crow['unpaid_or_failed'];
    }
    $countsStmt->close();
}

// --- Paginated rows -------------------------------------------------
$dataQuery = "
    SELECT cr.*,
           r.full_name AS examinee_name,
           r.email,
           r.mobile,
           ed.exam_date,
           au.username AS posted_by_name
    FROM certificate_requests cr
    LEFT JOIN registrations r ON r.id = cr.registration_id
    LEFT JOIN exam_dates ed   ON ed.id = cr.exam_date_id
    LEFT JOIN admin_users au  ON au.id = cr.posted_by
    WHERE {$whereClause}
    ORDER BY cr.created_at DESC
";
$countQuery = "
    SELECT COUNT(*) AS cnt
    FROM certificate_requests cr
    LEFT JOIN registrations r ON r.id = cr.registration_id
    WHERE {$whereClause}
";

require_once __DIR__ . '/../functions.php';
$paginator = paginateQuery($dataQuery, $countQuery, $params, $types, $page, $perPage);
$rows = $paginator['rows'];

// Preserved query string for pagination links.
$qs = http_build_query(array_filter([
    'exam_date_id'      => $selectedExamDateId,
    'payment_status'    => $paymentFilter,
    'certificate_status'=> $certFilter,
    'search'            => $search,
]));

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1>Certificate Requests</h1>
    <p class="text-secondary">Examinees who appeared for a past exam can request a certificate by post (200 BDT). Verify payment, print mail labels, mark posted.</p>
</div>

<!-- Summary chips -->
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-label">Total requests</div>
        <div class="stat-value"><?php echo e((string) $counts['total']); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Ready to post</div>
        <div class="stat-value"><?php echo e((string) $counts['paid_requested']); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Posted</div>
        <div class="stat-value"><?php echo e((string) $counts['posted']); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Unpaid / failed</div>
        <div class="stat-value"><?php echo e((string) $counts['unpaid_or_failed']); ?></div>
    </div>
</div>

<!-- Filters -->
<form method="get" class="filters" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:20px;background:#fff;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">
    <div>
        <label>Exam date</label>
        <select name="exam_date_id">
            <option value="">All exams</option>
            <?php foreach ($examDates as $ed): ?>
                <option value="<?php echo e($ed['id']); ?>" <?php echo $ed['id'] === $selectedExamDateId ? 'selected' : ''; ?>>
                    <?php echo e(formatDate($ed['exam_date'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Payment</label>
        <select name="payment_status">
            <option value="">Any</option>
            <?php foreach (['paid', 'unpaid', 'failed', 'refunded'] as $p): ?>
                <option value="<?php echo e($p); ?>" <?php echo $p === $paymentFilter ? 'selected' : ''; ?>><?php echo e(ucfirst($p)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Certificate</label>
        <select name="certificate_status">
            <option value="">Any</option>
            <option value="requested" <?php echo $certFilter === 'requested' ? 'selected' : ''; ?>>Requested</option>
            <option value="posted"    <?php echo $certFilter === 'posted'    ? 'selected' : ''; ?>>Posted</option>
        </select>
    </div>
    <div style="flex:1;min-width:200px;">
        <label>Search</label>
        <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Name, reg no, phone, email">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a href="<?php echo e(BASE_URL); ?>/pages/certificate-requests.php" class="btn btn-secondary">Reset</a>
</form>

<!-- Batch action: print labels -->
<form id="batch-form" method="get" action="<?php echo e(BASE_URL); ?>/pages/certificate-mail-labels.php" target="_blank" style="margin-bottom:16px;display:flex;gap:8px;align-items:center;">
    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
    <button type="submit" id="print-labels-btn" class="btn btn-secondary" disabled>🖨️ Print Labels for Selected</button>
    <span class="text-secondary" style="font-size:13px;"><span id="selection-count">0</span> selected (paid only will be included)</span>
</form>

<!-- Table -->
<?php if (empty($rows)): ?>
    <div class="empty-state" style="background:#fff;border-radius:8px;padding:48px;text-align:center;border:1px solid #e2e8f0;">
        <p class="text-secondary">No certificate requests match your filters.</p>
    </div>
<?php else: ?>
    <table class="data-table" style="width:100%;background:#fff;border-collapse:collapse;border-radius:8px;overflow:hidden;">
        <thead>
            <tr style="background:#f7fafc;text-align:left;font-size:13px;color:#4a5568;text-transform:uppercase;letter-spacing:0.05em;">
                <th style="padding:12px;"><input type="checkbox" id="select-all"></th>
                <th style="padding:12px;">Examinee</th>
                <th style="padding:12px;">Exam</th>
                <th style="padding:12px;">Ship To</th>
                <th style="padding:12px;">Payment</th>
                <th style="padding:12px;">Status</th>
                <th style="padding:12px;">Requested</th>
                <th style="padding:12px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                    $canMarkPosted = ($row['payment_status'] === 'paid' && $row['certificate_status'] === 'requested');
                    $examLabel = !empty($row['exam_date']) ? formatDate($row['exam_date']) : '—';
                ?>
                <tr style="border-top:1px solid #edf2f7;font-size:14px;">
                    <td style="padding:12px;">
                        <?php if ($row['payment_status'] === 'paid'): ?>
                            <input type="checkbox" name="ids[]" value="<?php echo e($row['id']); ?>" form="batch-form" class="row-checkbox">
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px;">
                        <div style="font-weight:600;"><?php echo e(html_entity_decode($row['examinee_name'] ?? '', ENT_QUOTES, 'UTF-8')); ?></div>
                        <div style="font-size:12px;color:#718096;">Reg: <?php echo e($row['reg_no']); ?></div>
                        <div style="font-size:12px;color:#718096;"><?php echo e($row['email']); ?></div>
                    </td>
                    <td style="padding:12px;"><?php echo e($examLabel); ?></td>
                    <td style="padding:12px;font-size:13px;">
                        <div><?php echo e($row['recipient_name']); ?></div>
                        <div style="color:#718096;"><?php echo e($row['house_street']); ?></div>
                        <div style="color:#718096;"><?php echo e($row['area_thana'] . ', ' . $row['district'] . ' ' . $row['postal_code']); ?></div>
                        <div style="color:#718096;">☎ <?php echo e($row['recipient_phone']); ?></div>
                    </td>
                    <td style="padding:12px;">
                        <?php
                        $payClass = ['paid' => 'badge-success', 'unpaid' => 'badge-warning', 'failed' => 'badge-error', 'refunded' => 'badge-info'];
                        $cls = $payClass[$row['payment_status']] ?? 'badge-secondary';
                        ?>
                        <span class="badge <?php echo e($cls); ?>"><?php echo e(ucfirst($row['payment_status'])); ?></span>
                        <?php if ($row['payment_status'] === 'paid' && !empty($row['payment_time'])): ?>
                            <div style="font-size:11px;color:#718096;margin-top:2px;"><?php echo e(formatDate($row['payment_time'], 'M j, Y g:i a')); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px;">
                        <?php if ($row['certificate_status'] === 'posted'): ?>
                            <span class="badge badge-success">Posted</span>
                            <?php if (!empty($row['posted_at'])): ?>
                                <div style="font-size:11px;color:#718096;margin-top:2px;"><?php echo e(formatDate($row['posted_at'], 'M j, Y')); ?> by <?php echo e($row['posted_by_name'] ?? '—'); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($row['tracking_number'])): ?>
                                <div style="font-size:11px;color:#718096;">Tracking: <?php echo e($row['tracking_number']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-secondary">Requested</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px;font-size:12px;color:#718096;"><?php echo e(formatDate($row['created_at'], 'M j, Y')); ?></td>
                    <td style="padding:12px;">
                        <?php if ($canMarkPosted): ?>
                            <form method="post" action="<?php echo e(BASE_URL); ?>/api/certificate-requests/mark-posted.php" style="display:flex;flex-direction:column;gap:4px;">
                                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                <input type="hidden" name="id" value="<?php echo e($row['id']); ?>">
                                <input type="text" name="tracking_number" placeholder="Tracking # (optional)" style="font-size:12px;padding:4px 8px;border:1px solid #cbd5e0;border-radius:4px;">
                                <button type="submit" class="btn btn-primary" style="font-size:13px;padding:6px 10px;">Mark Posted</button>
                            </form>
                        <?php else: ?>
                            <span style="color:#a0aec0;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($paginator['total_pages'] > 1): ?>
        <div class="pagination" style="margin-top:20px;display:flex;gap:6px;">
            <?php for ($i = 1; $i <= $paginator['total_pages']; $i++): ?>
                <a href="?<?php echo e($qs); ?>&page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size:13px;padding:6px 12px;"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    // Checkbox selection helpers.
    (function() {
        const selectAll = document.getElementById('select-all');
        const printBtn  = document.getElementById('print-labels-btn');
        const counter   = document.getElementById('selection-count');
        const update = function() {
            const checked = document.querySelectorAll('.row-checkbox:checked');
            counter.textContent = checked.length;
            printBtn.disabled = checked.length === 0;
        };
        document.addEventListener('change', function(ev) {
            if (ev.target.classList.contains('row-checkbox')) update();
            if (ev.target === selectAll) {
                document.querySelectorAll('.row-checkbox').forEach(function(cb) { cb.checked = selectAll.checked; });
                update();
            }
        });
        update();
    })();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
