<?php
/**
 * Scores page
 *
 * Two modes:
 *   - No exam_date_id:   show upload form + past-exam overview cards.
 *   - ?exam_date_id=...: show the tracking table (ID | RegNumber |
 *     Score PDF | Status | checkbox) with Send Selected / Send All.
 *
 * All scores are STAGED on upload — no auto-send. Admin reviews then
 * triggers send explicitly. No exam-guide attachment for scores.
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Scores';
$currentPage = 'scores';

$conn = getDbConnection();
if (!$conn) {
    require_once __DIR__ . '/../templates/header.php';
    echo '<div class="alert alert-error">Database connection failed.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// --- Query params ---------------------------------------------------
$selectedExamDateId = trim($_GET['exam_date_id'] ?? '');
$statusFilter       = $_GET['status'] ?? '';          // '' | staged | sent | failed
$search             = trim($_GET['search'] ?? '');
$page               = max(1, (int) ($_GET['page'] ?? 1));
$perPage            = 50;

// --- Past exam dates (with approved counts) -------------------------
// Scores are distributed after the exam, so list past dates only,
// newest first.
$examDates = [];
$stmt = $conn->prepare("
    SELECT ed.id, ed.exam_date,
           (SELECT COUNT(*) FROM registrations r WHERE r.test_date = ed.exam_date AND r.approved = 1) AS approved_count
    FROM exam_dates ed
    WHERE ed.exam_date < CURDATE()
    ORDER BY ed.exam_date DESC
");
if ($stmt) {
    $stmt->execute();
    $examDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// --- Tracking data (only when an exam is selected) ------------------
$scoreCounts = ['staged' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0];
$scores      = [];
$paginator   = null;

if ($selectedExamDateId !== '') {
    // Resolve exam_date (YYYY-MM-DD) for the selected exam_date_id.
    $selectedExamDate = '';
    foreach ($examDates as $ed) {
        if ($ed['id'] === $selectedExamDateId) {
            $selectedExamDate = $ed['exam_date'];
            break;
        }
    }
    if ($selectedExamDate === '') {
        // Fallback: query directly (allows viewing scores for exams that
        // somehow aren't in the past-dates list, e.g. same-day edge case).
        $stmt = $conn->prepare("SELECT exam_date FROM exam_dates WHERE id = ?");
        $stmt->bind_param('s', $selectedExamDateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $selectedExamDate = $row['exam_date'] ?? '';
        $stmt->close();
    }

    // Counts per status for the summary chips.
    $stmt = $conn->prepare("
        SELECT send_status, COUNT(*) AS cnt
        FROM score_reports
        WHERE exam_date_id = ?
        GROUP BY send_status
    ");
    $stmt->bind_param('s', $selectedExamDateId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $scoreCounts[$row['send_status']] = (int) $row['cnt'];
    }
    $stmt->close();
    $scoreCounts['total'] = $scoreCounts['staged'] + $scoreCounts['sent'] + $scoreCounts['failed'];

    // Paginated score rows. Qualify all columns with `sr.` because we
    // JOIN exam_dates, registration_sheet_numbers, and registrations,
    // which share column names (reg_no, id).
    //
    // sr.reg_no is stored internally as YYYYMM + original_reg_no.
    // SUBSTRING(reg_no, 7) yields the 8-digit reg_no for both JOIN and
    // SELECT (so the admin table shows the human-readable form).
    $where = ['sr.exam_date_id = ?'];
    $params = [$selectedExamDateId];
    $types = 's';

    if (in_array($statusFilter, ['staged', 'sent', 'failed'], true)) {
        $where[] = 'sr.send_status = ?';
        $params[] = $statusFilter;
        $types   .= 's';
    }
    if ($search !== '') {
        $where[] = '(sr.xlsx_id LIKE ? OR sr.reg_no LIKE ? OR r.full_name LIKE ? OR r.email LIKE ? OR r.mobile LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types   .= 'sssss';
    }
    $whereClause = implode(' AND ', $where);

    $dataQuery = "
        SELECT sr.id, sr.xlsx_id,
               SUBSTRING(sr.reg_no, 7) AS reg_no,
               sr.file_path,
               sr.send_status, sr.emailed_at, sr.last_error,
               sr.incorrect_disposal_at,
               r.full_name, r.email, r.mobile
        FROM score_reports sr
        LEFT JOIN exam_dates ed ON ed.id = sr.exam_date_id
        LEFT JOIN registration_sheet_numbers rsn
            ON rsn.reg_no = SUBSTRING(sr.reg_no, 7)
            AND rsn.year  = YEAR(ed.exam_date)
            AND rsn.month = MONTH(ed.exam_date)
        LEFT JOIN registrations r ON r.id = rsn.registration_id
        WHERE $whereClause
        GROUP BY sr.id
        ORDER BY CAST(sr.xlsx_id AS UNSIGNED), sr.xlsx_id ASC
    ";
    $countQuery = "
        SELECT COUNT(*) AS cnt
        FROM score_reports sr
        LEFT JOIN exam_dates ed ON ed.id = sr.exam_date_id
        LEFT JOIN registration_sheet_numbers rsn
            ON rsn.reg_no = SUBSTRING(sr.reg_no, 7)
            AND rsn.year  = YEAR(ed.exam_date)
            AND rsn.month = MONTH(ed.exam_date)
        LEFT JOIN registrations r ON r.id = rsn.registration_id
        WHERE $whereClause
    ";

    $paginator = paginateQuery($dataQuery, $countQuery, $params, $types, $page, $perPage);
    $scores    = $paginator['rows'];
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Scores</h1>
    <p class="page-subtitle">Upload score PDFs, then review and send</p>
</div>

<?php $flash = getFlashMessage(); if ($flash): ?>
    <div class="alert alert-<?php echo e($flash['type']); ?>" style="white-space: pre-wrap;"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<!-- Upload form -->
<div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
    <h2 style="font-size: 16px; font-weight: 600; color: #1a202c; margin-bottom: 12px;">Stage New Score Reports</h2>
    <form method="POST" action="<?php echo BASE_URL; ?>/api/scores/upload.php"
          enctype="multipart/form-data"
          style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; align-items: end;">

        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam Date *</label>
            <select name="exam_date_id" id="exam-date-select" required
                    style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">Select exam date...</option>
                <?php foreach ($examDates as $ed): ?>
                    <option value="<?php echo e($ed['id']); ?>"
                            data-date="<?php echo e($ed['exam_date']); ?>"
                            <?php echo $selectedExamDateId === $ed['id'] ? 'selected' : ''; ?>>
                        <?php echo e(formatDate($ed['exam_date'])); ?>
                        (<?php echo (int) $ed['approved_count']; ?> approved)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="exam_date" id="exam-date-value" value="">
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Examinee List (xlsx)</label>
            <input type="file" name="xlsx_file" accept=".xlsx" required
                   style="font-size: 13px; width: 100%;">
            <p style="font-size: 11px; color: #718096; margin-top: 4px;">Columns: ID, RegNumber</p>
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Scores ZIP</label>
            <input type="file" name="scores_zip" accept=".zip" required
                   style="font-size: 13px; width: 100%;">
            <p style="font-size: 11px; color: #718096; margin-top: 4px;">PDFs named &lt;ID&gt;.pdf</p>
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Stage Scores</button>
        </div>
    </form>
</div>

<?php if ($selectedExamDateId !== ''): ?>

    <?php
    // Active filter chip
    $activeLabel = null;
    foreach ($examDates as $ed) {
        if ($ed['id'] === $selectedExamDateId) {
            $activeLabel = formatDate($ed['exam_date']);
            break;
        }
    }
    ?>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; padding: 12px 16px; background: #fefcbf; border: 1px solid #f6e05e; border-radius: 8px;">
        <span style="font-size: 13px; color: #744210; font-weight: 600;">Viewing:</span>
        <span style="background: white; border: 1px solid #f6e05e; border-radius: 12px; padding: 4px 10px; font-size: 12px; color: #744210;">
            <strong>Exam Date:</strong> <?php echo e($activeLabel ?? $selectedExamDateId); ?>
        </span>
        <a href="<?php echo BASE_URL; ?>/pages/scores.php"
           style="font-size: 12px; color: #c53030; text-decoration: underline;">✕ Clear</a>
    </div>

    <!-- Summary chips -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <span style="background: #edf2f7; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Total: <strong><?php echo $scoreCounts['total']; ?></strong></span>
        <span style="background: #fefcbf; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Staged: <strong><?php echo $scoreCounts['staged']; ?></strong></span>
        <span style="background: #c6f6d5; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Sent: <strong><?php echo $scoreCounts['sent']; ?></strong></span>
        <span style="background: #fed7d7; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Failed: <strong><?php echo $scoreCounts['failed']; ?></strong></span>
    </div>

    <!-- Filter form -->
    <form method="GET" style="background: white; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; align-items: end;">
        <input type="hidden" name="exam_date_id" value="<?php echo e($selectedExamDateId); ?>">
        <div>
            <label style="display: block; font-size: 12px; color: #4a5568; margin-bottom: 4px;">Status</label>
            <select name="status" style="padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">All</option>
                <option value="staged" <?php echo $statusFilter === 'staged' ? 'selected' : ''; ?>>Staged</option>
                <option value="sent"   <?php echo $statusFilter === 'sent'   ? 'selected' : ''; ?>>Sent</option>
                <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
            </select>
        </div>
        <div>
            <label style="display: block; font-size: 12px; color: #4a5568; margin-bottom: 4px;">Search</label>
            <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="ID or reg no..."
                   style="padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 6px 16px; font-size: 14px;">Apply</button>
        <a href="<?php echo BASE_URL; ?>/pages/scores.php?exam_date_id=<?php echo e($selectedExamDateId); ?>"
           class="btn btn-secondary" style="padding: 6px 16px; font-size: 14px;">Clear</a>
    </form>

    <?php if (!empty($scores)): ?>

        <!-- Send-actions form. Wraps the table so each row's checkbox
             belongs to the same POST. -->
        <form method="POST" action="<?php echo BASE_URL; ?>/api/scores/send.php" id="scores-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
            <input type="hidden" name="exam_date_id" value="<?php echo e($selectedExamDateId); ?>">
            <input type="hidden" name="action" id="scores-action" value="">

            <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                <button type="submit"
                        class="btn btn-primary"
                        onclick="return confirmSend('send_selected', 0);"
                        <?php if ($scoreCounts['staged'] + $scoreCounts['failed'] === 0) echo 'disabled'; ?>>
                    Send Selected
                </button>
                <button type="submit"
                        class="btn btn-secondary"
                        onclick="return confirmSend('send_all', <?php echo $scoreCounts['staged'] + $scoreCounts['failed']; ?>);"
                        <?php if ($scoreCounts['staged'] + $scoreCounts['failed'] === 0) echo 'disabled'; ?>>
                    Send All (<?php echo $scoreCounts['staged'] + $scoreCounts['failed']; ?> staged/failed)
                </button>
                <?php if (isSuperAdmin()): ?>
                <!--
                    Super-admin manual cleanup for score reports: marks
                    the selected sent rows as incorrect disposals and
                    unstages them for review/re-send. Uses formaction so
                    it posts to a different endpoint than the Send actions.
                -->
                <button type="submit"
                        class="btn btn-danger"
                        formaction="<?php echo BASE_URL; ?>/api/scores/mark-incorrect.php"
                        onclick="return confirmMarkIncorrect();"
                        style="margin-left: auto;">
                    ⚠ Mark Selected Incorrect &amp; Unstage
                </button>
                <?php else: ?>
                <span style="font-size: 12px; color: #718096; align-self: center; margin-left: auto;">
                    Selected: <span id="selected-count">0</span>
                </span>
                <?php endif; ?>
                <?php if (isSuperAdmin()): ?>
                <span style="font-size: 12px; color: #718096; align-self: center;">
                    Selected: <span id="selected-count">0</span>
                </span>
                <?php endif; ?>
            </div>

            <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                            <th style="padding: 10px 12px; width: 32px; text-align: center;">
                                <input type="checkbox" id="select-all" onclick="toggleAll(this)">
                            </th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">ID</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Name</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Email</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Phone</th>
                            <th style="padding: 10px 12px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Score</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scores as $s):
                            $statusColors = ['staged' => '#ed8936', 'sent' => '#48bb78', 'failed' => '#f56565'];
                            $color = $statusColors[$s['send_status']] ?? '#718096';
                            $staged  = in_array($s['send_status'], ['staged', 'failed'], true);
                            // Super admins can also pick sent rows (to mark
                            // them as incorrect disposals).
                            $canCheck = $staged || isSuperAdmin();
                            // Web URL for the PDF. Score report PDFs are
                            // named by xlsx_id (the "reg_id" / ID column
                            // from Examinee List.xlsx), not by RegNumber.
                            $pdfUrl = _scoreWebUrl($s['file_path']);
                        ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 10px 12px; text-align: center;">
                                    <?php if ($canCheck): ?>
                                        <input type="checkbox" name="score_ids[]" value="<?php echo (int) $s['id']; ?>" class="score-checkbox" onchange="updateCount()">
                                    <?php else: ?>
                                        <input type="checkbox" disabled title="Already sent">
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px; font-family: monospace; font-size: 13px;"><?php echo e($s['xlsx_id']); ?></td>
                                <td style="padding: 10px 12px; font-size: 13px;">
                                    <?php if (!empty($s['full_name'])): ?>
                                        <?php echo e($s['full_name']); ?>
                                    <?php else: ?>
                                        <span style="color: #cbd5e0;" title="No registration found for this reg_no">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px; font-size: 13px;">
                                    <?php if (!empty($s['email'])): ?>
                                        <a href="mailto:<?php echo e($s['email']); ?>" style="color: #667eea; text-decoration: none;"><?php echo e($s['email']); ?></a>
                                    <?php else: ?>
                                        <span style="color: #cbd5e0;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px; font-size: 13px;">
                                    <?php if (!empty($s['mobile'])): ?>
                                        <?php echo e($s['mobile']); ?>
                                    <?php else: ?>
                                        <span style="color: #cbd5e0;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px; text-align: center;">
                                    <?php if ($pdfUrl): ?>
                                        <a href="<?php echo e($pdfUrl); ?>" target="_blank" style="color: #667eea; text-decoration: none; font-size: 13px;">📄 view</a>
                                    <?php else: ?>
                                        <span style="color: #cbd5e0;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px;">
                                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                        <?php echo ucfirst($s['send_status']); ?>
                                    </span>
                                    <?php if (!empty($s['incorrect_disposal_at'])): ?>
                                        <div style="margin-top: 4px;">
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #fed7d7; color: #c53030;"
                                                  title="<?php echo e('Marked incorrect on ' . date('M j, Y g:i A', strtotime($s['incorrect_disposal_at']))); ?>">
                                                ⚠ Marked incorrect
                                            </span>
                                            <div style="font-size: 10px; color: #c53030; margin-top: 2px;">
                                                <?php echo e(date('M j, Y', strtotime($s['incorrect_disposal_at']))); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($s['send_status'] === 'sent' && !empty($s['emailed_at'])): ?>
                                        <div style="font-size: 11px; color: #718096; margin-top: 2px;">
                                            <?php echo e(date('M j, Y g:i A', strtotime($s['emailed_at']))); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($s['send_status'] === 'failed' && !empty($s['last_error'])): ?>
                                        <div style="font-size: 11px; color: #c53030; margin-top: 2px; max-width: 380px;">
                                            <?php echo e($s['last_error']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php
        $paginationParams = array_filter([
            'exam_date_id' => $selectedExamDateId,
            'status'       => $statusFilter,
            'search'       => $search,
        ]);
        echo renderPagination(
            $paginator['page'],
            $paginator['totalPages'],
            $paginator['total'],
            $paginator['perPage'],
            BASE_URL . '/pages/scores.php',
            $paginationParams
        );
        ?>

    <?php else: ?>
        <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
            <div style="font-size: 48px; margin-bottom: 16px;">📊</div>
            <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Scores Staged Yet</h3>
            <p style="color: #718096; font-size: 14px;">
                Use the form above to upload the Examinee List xlsx and the score PDF zip for this exam date.
            </p>
        </div>
    <?php endif; ?>

<?php else: ?>

    <!-- Exam dates overview -->
    <?php if (!empty($examDates)): ?>
        <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
            <h2 style="font-size: 16px; font-weight: 600; color: #1a202c; margin-bottom: 12px;">Past Exam Dates</h2>
            <div style="display: grid; gap: 12px;">
                <?php foreach ($examDates as $ed):
                    // Score counts per exam
                    $stmt = $conn->prepare("
                        SELECT
                            SUM(send_status = 'staged') AS staged,
                            SUM(send_status = 'sent')   AS sent,
                            SUM(send_status = 'failed') AS failed
                        FROM score_reports
                        WHERE exam_date_id = ?
                    ");
                    $stmt->bind_param('s', $ed['id']);
                    $stmt->execute();
                    $cnt = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                ?>
                    <a href="<?php echo BASE_URL; ?>/pages/scores.php?exam_date_id=<?php echo e($ed['id']); ?>"
                       style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: #f7fafc; border-radius: 8px; text-decoration: none; color: inherit;">
                        <div>
                            <div style="font-size: 15px; font-weight: 600; color: #1a202c;">
                                <?php echo e(formatDate($ed['exam_date'])); ?>
                            </div>
                            <div style="font-size: 12px; color: #718096; margin-top: 2px;">
                                <?php echo (int) $ed['approved_count']; ?> approved
                                · <span style="color: #ed8936;"><?php echo (int) ($cnt['staged'] ?? 0); ?> staged</span>
                                · <span style="color: #48bb78;"><?php echo (int) ($cnt['sent']   ?? 0); ?> sent</span>
                                · <span style="color: #f56565;"><?php echo (int) ($cnt['failed'] ?? 0); ?> failed</span>
                            </div>
                        </div>
                        <span style="font-size: 13px; color: #667eea; font-weight: 500;">View →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
            <div style="font-size: 48px; margin-bottom: 16px;">📅</div>
            <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Past Exam Dates</h3>
            <p style="color: #718096; font-size: 14px;">Once an exam date passes, scores can be distributed from here.</p>
        </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Batch send progress overlay -->
<div id="send-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:32px; border-radius:12px; text-align:center; min-width:360px; max-width:480px; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="font-size:18px; margin:0 0 16px; color:#1a202c;">Sending score reports…</h3>
        <p id="send-progress" style="font-size:20px; font-weight:600; margin:0 0 16px; color:#1a202c;">0 sent</p>
        <div style="background:#edf2f7; border-radius:8px; height:8px; overflow:hidden;">
            <div id="send-bar" style="background:#667eea; height:100%; width:0%; transition:width 0.3s ease;"></div>
        </div>
        <p style="font-size:12px; color:#718096; margin:12px 0 0;">Do not close this page while sending.</p>
        <p id="send-errors" style="color:#c53030; margin-top:8px; font-size:13px; display:none; text-align:left;"></p>
    </div>
</div>

<script>
function toggleAll(master) {
    document.querySelectorAll('input.score-checkbox[type="checkbox"]').forEach(function (cb) {
        cb.checked = master.checked;
    });
    updateCount();
}
function updateCount() {
    var n = document.querySelectorAll('input.score-checkbox[type="checkbox"]:checked').length;
    var el = document.getElementById('selected-count');
    if (el) el.textContent = n;
}
// Gate every send action behind a confirm so a stray click can't email
// applicants. 'send_all' is the mass-send case; 'send_selected' still
// asks (with the live selection count) because email sends are irreversible.
function confirmSend(action, allCount) {
    var n = action === 'send_all'
        ? allCount
        : document.querySelectorAll('input.score-checkbox[type="checkbox"]:checked').length;
    if (n <= 0) {
        alert('No scores selected. Tick rows first, or use Send All.');
        return false;
    }
    var msg = 'Email score reports to ' + n + ' examinee' + (n === 1 ? '' : 's') + ' for this exam date?';
    if (action === 'send_all') {
        msg += '\n\nThis sends to every staged or failed row. It cannot be undone.';
    }
    if (!confirm(msg)) return false;
    batchSend(action);
    return false;
}

// Batch send: processes score reports in groups of 15 via sequential AJAX
// requests so each HTTP request finishes within the server timeout.
async function batchSend(action) {
    var form = document.getElementById('scores-form');
    var csrfToken = form.querySelector('input[name="csrf_token"]').value;
    var examDateId = form.querySelector('input[name="exam_date_id"]').value;
    var sendUrl = form.action;

    var overlay = document.getElementById('send-overlay');
    var bar = document.getElementById('send-bar');
    var text = document.getElementById('send-progress');
    var errs = document.getElementById('send-errors');
    errs.style.display = 'none';
    errs.textContent = '';
    bar.style.width = '0%';
    text.textContent = 'Starting…';
    overlay.style.display = 'flex';

    var totalSent = 0, totalFailed = 0, processed = 0;

    try {
        if (action === 'send_selected') {
            var allIds = [];
            document.querySelectorAll('input.score-checkbox[type="checkbox"]:checked').forEach(function (cb) {
                allIds.push(parseInt(cb.value));
            });
            var total = allIds.length;

            for (var i = 0; i < allIds.length; i += 15) {
                var batch = allIds.slice(i, i + 15);
                var params = new URLSearchParams();
                params.set('csrf_token', csrfToken);
                params.set('exam_date_id', examDateId);
                params.set('action', 'send_selected');
                params.set('batch_mode', '1');
                batch.forEach(function (id) { params.append('score_ids[]', id); });

                var data = await postBatch(sendUrl, params);
                totalSent += data.sent;
                totalFailed += data.failed;
                processed += data.processed;

                bar.style.width = Math.round((processed / total) * 100) + '%';
                text.textContent = totalSent + ' sent' + (totalFailed ? ', ' + totalFailed + ' failed' : '') + ' (' + processed + '/' + total + ')';
            }
        } else {
            for (var iter = 0; iter < 100; iter++) {
                var params = new URLSearchParams();
                params.set('csrf_token', csrfToken);
                params.set('exam_date_id', examDateId);
                params.set('action', 'send_all');
                params.set('batch_mode', '1');

                var data = await postBatch(sendUrl, params);
                totalSent += data.sent;
                totalFailed += data.failed;
                processed += data.processed;

                text.textContent = totalSent + ' sent' + (totalFailed ? ', ' + totalFailed + ' failed' : '') + ' — ' + data.remaining + ' remaining';

                if (data.remaining === 0) break;
                if (data.sent === 0 && data.failed > 0) {
                    throw new Error('All sends in this batch failed. ' + (data.errors[0] || 'Check SMTP settings.'));
                }
            }
        }

        bar.style.width = '100%';
        text.textContent = 'Done: ' + totalSent + ' sent' + (totalFailed ? ', ' + totalFailed + ' failed' : '');
        await sleep(1500);
    } catch (e) {
        errs.textContent = e.message;
        errs.style.display = 'block';
        text.textContent = 'Stopped after ' + totalSent + ' sent' + (totalFailed ? ', ' + totalFailed + ' failed' : '');
        await sleep(4000);
    }

    overlay.style.display = 'none';
    window.location.reload();
}

async function postBatch(url, params) {
    var resp = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    });
    var text = await resp.text();
    try {
        var data = JSON.parse(text);
    } catch (e) {
        if (text.indexOf('<!DOCTYPE') !== -1 || text.indexOf('<html') !== -1)
            throw new Error('Session expired or request blocked. Reload the page and try again.');
        throw new Error('Unexpected server response: ' + text.substring(0, 200));
    }
    if (data.error) throw new Error(data.error);
    return data;
}

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
// Super-admin manual cleanup. Marks selected sent rows as incorrect
// disposals and unstages them. Doesn't email anyone — just resets the
// row state and stamps a marker for the audit trail.
function confirmMarkIncorrect() {
    var n = document.querySelectorAll('input.score-checkbox[type="checkbox"]:checked').length;
    if (n <= 0) {
        alert('No scores selected. Tick rows first.');
        return false;
    }
    return confirm(
        'Mark ' + n + ' score report(s) as INCORRECT disposal and reset to staged?\n\n' +
        'This does NOT email anyone. It only:\n' +
        '  - sets the row back to staged (so it can be re-sent later)\n' +
        '  - stamps a marker so the audit trail records this was flagged incorrect\n' +
        '  - clears the emailed_at timestamp on the row'
    );
}
document.addEventListener('DOMContentLoaded', updateCount);

// Populate the hidden exam_date (YYYY-MM-DD) from the selected option's data attribute.
(function () {
    var sel   = document.getElementById('exam-date-select');
    var field = document.getElementById('exam-date-value');
    if (!sel || !field) return;
    function sync() {
        var opt = sel.options[sel.selectedIndex];
        if (opt) field.value = opt.getAttribute('data-date') || '';
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>

<?php
/**
 * Convert an absolute PDF path stored in score_reports.file_path
 * into a web URL reachable from the admin panel.
 *
 * The upload stored under UPLOAD_PATH (e.g. /home/.../admin/uploads/).
 * The web root maps /admin/ to that directory, so /admin/uploads/...
 * is the public path.
 */
function _scoreWebUrl(string $absPath): string {
    if ($absPath === '') return '';
    $uploadsDir = UPLOAD_PATH;
    // Normalize for comparison.
    $realAbs    = str_replace('\\', '/', $absPath);
    $realUpload = str_replace('\\', '/', realpath($uploadsDir) ?: $uploadsDir);
    if (strpos($realAbs, $realUpload) === 0) {
        $rel = substr($realAbs, strlen($realUpload));
        return BASE_URL . '/uploads/' . ltrim($rel, '/');
    }
    // Fallback: opaque path — no link.
    return '';
}
require_once __DIR__ . '/../templates/footer.php';
