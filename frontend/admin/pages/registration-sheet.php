<?php
/**
 * Registration Sheet Page
 *
 * Tab reachable from registrations.php. Lets admin pick a year + month
 * (years pulled from DB, months populated after year is chosen), then:
 *   - Show:   lists approved registrations for that period (Name, DOB, Levels)
 *   - Export: produces an .xlsx matching templates/Registration_Sheet_ver.30.xlsx
 *
 * Filter basis = test_date (the exam sitting the sheet is being prepared for).
 * Only approved registrations are included - this sheet goes to Japan HQ.
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Registration Sheet';
$currentPage = 'registrations';

$conn = getDbConnection();
if (!$conn) {
    require_once __DIR__ . '/../templates/header.php';
    echo '<div class="alert alert-error">Database connection failed.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

$selectedYear  = isset($_GET['year'])  ? (int) $_GET['year']  : 0;
$selectedMonth = isset($_GET['month']) ? (int) $_GET['month'] : 0;
$action        = $_GET['action'] ?? '';

$years = [];
$stmt  = $conn->prepare("
    SELECT DISTINCT YEAR(test_date) AS y
    FROM registrations
    WHERE approved = 1 AND test_date IS NOT NULL
    ORDER BY y DESC
");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['y'])) {
            $years[] = (int) $row['y'];
        }
    }
    $stmt->close();
}

$availableMonths = [];
if ($selectedYear > 0) {
    $stmt = $conn->prepare("
        SELECT MONTH(test_date) AS m, COUNT(*) AS cnt
        FROM registrations
        WHERE approved = 1 AND YEAR(test_date) = ?
        GROUP BY MONTH(test_date)
        ORDER BY m ASC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $selectedYear);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $availableMonths[(int) $row['m']] = (int) $row['cnt'];
        }
        $stmt->close();
    }
}

$registrations = [];
if ($action === 'show' && $selectedYear > 0 && $selectedMonth > 0) {
    $stmt = $conn->prepare("
        SELECT id, full_name, dob, exam_level, test_date, email
        FROM registrations
        WHERE approved = 1
          AND YEAR(test_date)  = ?
          AND MONTH(test_date) = ?
        ORDER BY full_name ASC
    ");
    $stmt->bind_param('ii', $selectedYear, $selectedMonth);
    $stmt->execute();
    $registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$levelCounts = ['1Q' => 0, '2Q' => 0, '3Q' => 0, '4Q' => 0, '5Q' => 0];
foreach ($registrations as $r) {
    foreach (explode(',', $r['exam_level']) as $lvl) {
        $lvl = trim($lvl);
        if (isset($levelCounts[$lvl])) {
            $levelCounts[$lvl]++;
        }
    }
}

// Pull any already-assigned Reg. Numbers for this period so admins can
// see which applicants have been exported. Missing table = none assigned yet.
$regNoById = [];
if (!empty($registrations)) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'registration_sheet_numbers'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $ids = array_column($registrations, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('s', count($ids));
        $stmt = $conn->prepare("
            SELECT registration_id, level, reg_no
            FROM registration_sheet_numbers
            WHERE year = ? AND month = ?
              AND registration_id IN ($placeholders)
            ORDER BY registration_id, level
        ");
        $params = array_merge([$selectedYear, $selectedMonth], $ids);
        $types  = 'ii' . $types;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $regNoById[$row['registration_id']][] = [
                'level' => $row['level'],
                'reg_no' => $row['reg_no'],
            ];
        }
        $stmt->close();
    }
}

// Status counts (mirrors registrations.php so the tab bar matches).
$statusCounts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS all_count,
        SUM(CASE WHEN approved IS NULL OR approved = 0 THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN approved = 1 THEN 1 ELSE 0 END) AS approved_count
    FROM registrations
");
if ($stmt) {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $statusCounts['all']      = (int) ($row['all_count']      ?? 0);
    $statusCounts['pending']  = (int) ($row['pending_count']  ?? 0);
    $statusCounts['approved'] = (int) ($row['approved_count'] ?? 0);
    $stmt->close();
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Registration Sheet</h1>
    <p class="page-subtitle">Build the monthly registration sheet (.xlsx) for Japan HQ</p>
</div>

<div style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; flex-wrap: wrap;">
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php" class="btn btn-secondary">
        All (<?php echo number_format($statusCounts['all']); ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=pending" class="btn btn-secondary">
        Pending (<?php echo number_format($statusCounts['pending']); ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=approved" class="btn btn-secondary">
        Approved (<?php echo number_format($statusCounts['approved']); ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=rejected" class="btn btn-secondary">
        Rejected (<?php echo $statusCounts['rejected']; ?>)
    </a>
    <a href="<?php echo BASE_URL; ?>/api/registrations/export.php" class="btn btn-secondary">📥 Export CSV</a>
    <a href="<?php echo BASE_URL; ?>/pages/registration-sheet.php" class="btn btn-primary">📋 Registration Sheet</a>
</div>

<div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
    <form method="GET" id="sheet-filter-form" style="display: grid; grid-template-columns: 200px 200px auto auto auto; gap: 16px; align-items: end;">
        <input type="hidden" name="action" value="show">

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Year</label>
            <select name="year" id="year-select" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">Select Year</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selectedYear === $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Month</label>
            <select name="month" id="month-select" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;" <?php echo empty($selectedYear) ? 'disabled' : ''; ?>>
                <option value="">Select Month</option>
                <?php foreach ($availableMonths as $mNum => $mCount): ?>
                    <option value="<?php echo $mNum; ?>" <?php echo $selectedMonth === $mNum ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $mNum, 1)); ?> (<?php echo $mCount; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">🔍 Show</button>
        </div>

        <div>
            <?php if ($selectedYear > 0 && $selectedMonth > 0): ?>
                <a href="<?php echo BASE_URL; ?>/api/registrations/registration-sheet-export.php?year=<?php echo $selectedYear; ?>&amp;month=<?php echo $selectedMonth; ?>" class="btn btn-secondary" style="width: 100%;">
                    📥 Export .xlsx
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-secondary" disabled style="width: 100%;">📥 Export .xlsx</button>
            <?php endif; ?>
        </div>

        <div>
            <?php if ($selectedYear > 0 && $selectedMonth > 0): ?>
                <a href="<?php echo BASE_URL; ?>/api/registrations/registration-sheet-photos.php?year=<?php echo $selectedYear; ?>&amp;month=<?php echo $selectedMonth; ?>" class="btn btn-secondary" style="width: 100%;">
                    📷 Download Photos
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-secondary" disabled style="width: 100%;">📷 Download Photos</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($action === 'show' && $selectedYear > 0 && $selectedMonth > 0): ?>

    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <span style="background: #edf2f7; padding: 6px 12px; border-radius: 16px; font-size: 13px;">
            <?php echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)); ?>
        </span>
        <span style="background: #bee3f8; padding: 6px 12px; border-radius: 16px; font-size: 13px;">
            Total: <?php echo count($registrations); ?>
        </span>
        <?php foreach ($levelCounts as $lvl => $cnt): ?>
            <span style="background: #c6f6d5; padding: 6px 12px; border-radius: 16px; font-size: 13px;">
                <?php echo e($lvl); ?>: <?php echo $cnt; ?>
            </span>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($registrations)): ?>
        <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">#</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Name</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Date of Birth</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Levels</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Reg. No(s)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $i => $r): ?>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 12px 16px; font-size: 14px;"><?php echo $i + 1; ?></td>
                            <td style="padding: 12px 16px; font-size: 14px; font-weight: 500;">
                                <?php echo e($r['full_name']); ?>
                            </td>
                            <td style="padding: 12px 16px; font-size: 14px;">
                                <?php echo e(!empty($r['dob']) ? date('Y/m/d', strtotime($r['dob'])) : '-'); ?>
                            </td>
                            <td style="padding: 12px 16px; font-size: 14px;">
                                <?php foreach (explode(',', $r['exam_level']) as $lvl): ?>
                                    <?php $lvl = trim($lvl); ?>
                                    <?php if ($lvl !== ''): ?>
                                        <span style="display: inline-block; background: #667eea20; color: #5a67d8; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; margin-right: 4px;">
                                            <?php echo e($lvl); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </td>
                            <td style="padding: 12px 16px; font-size: 13px; font-family: monospace;">
                                <?php if (!empty($regNoById[$r['id']])): ?>
                                    <?php foreach ($regNoById[$r['id']] as $rn): ?>
                                        <div style="margin-bottom: 2px;">
                                            <span style="color: #718096; font-size: 11px;"><?php echo e($rn['level']); ?>:</span>
                                            <strong><?php echo e($rn['reg_no']); ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: #cbd5e0;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
            <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
            <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Approved Registrations</h3>
            <p style="color: #718096; font-size: 14px;">
                No approved registrations found for <?php echo e(date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear))); ?>.
            </p>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
        <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">Select Year and Month</h3>
        <p style="color: #718096; font-size: 14px;">
            Pick a year to see available months, then click <strong>Show</strong> to preview or <strong>Export</strong> to generate the .xlsx file.
        </p>
    </div>
<?php endif; ?>

<script>
(function () {
    var form    = document.getElementById('sheet-filter-form');
    var yearSel = document.getElementById('year-select');
    var monSel  = document.getElementById('month-select');

    yearSel.addEventListener('change', function () {
        if (!this.value) {
            return;
        }
        monSel.value = '';
        var actionField = form.querySelector('input[name="action"]');
        actionField.value = 'load_months';
        form.submit();
    });

    var showBtn = form.querySelector('button[type="submit"]');
    if (showBtn) {
        showBtn.addEventListener('click', function () {
            var actionField = form.querySelector('input[name="action"]');
            actionField.value = 'show';
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php';
