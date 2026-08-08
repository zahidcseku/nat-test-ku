<?php
/**
 * Admission Tickets page
 *
 * Two modes:
 *   - No exam_date_id:   show upload form + per-exam overview cards.
 *   - ?exam_date_id=...: show the tracking table (ID | RegNumber |
 *     Ticket | Status | checkbox) with Send Selected / Send All buttons.
 *
 * All tickets are STAGED on upload — no auto-send. Admin reviews then
 * triggers send explicitly.
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Admission Tickets';
$currentPage = 'admission-tickets';

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

// --- Upcoming exam dates (with approved counts) ---------------------
$examDates = [];
$stmt = $conn->prepare("
    SELECT ed.id, ed.exam_date,
           (SELECT COUNT(*) FROM registrations r WHERE r.test_date = ed.exam_date AND r.approved = 1) AS approved_count
    FROM exam_dates ed
    WHERE ed.exam_date >= CURDATE()
    ORDER BY ed.exam_date ASC
");
if ($stmt) {
    $stmt->execute();
    $examDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// --- Tracking data (only when an exam is selected) ------------------
$ticketCounts = ['staged' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0];
$tickets      = [];
$paginator    = null;

if ($selectedExamDateId !== '') {
    // Resolve exam_date (YYYY-MM-DD) for the selected exam_date_id, used
    // to build the PDF web path. Also pull the guide_pdf_path so we can
    // show whether a guide is set.
    $selectedExamDate = '';
    $examGuidePath    = null;
    foreach ($examDates as $ed) {
        if ($ed['id'] === $selectedExamDateId) {
            $selectedExamDate = $ed['exam_date'];
            break;
        }
    }
    if ($selectedExamDate === '') {
        // Fallback: query directly.
        $stmt = $conn->prepare("SELECT exam_date, guide_pdf_path FROM exam_dates WHERE id = ?");
        $stmt->bind_param('s', $selectedExamDateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $selectedExamDate = $row['exam_date'] ?? '';
        $examGuidePath    = $row['guide_pdf_path'] ?? null;
        $stmt->close();
    } else {
        // Have the exam_date from the cached list — fetch guide separately.
        $stmt = $conn->prepare("SELECT guide_pdf_path FROM exam_dates WHERE id = ?");
        $stmt->bind_param('s', $selectedExamDateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $examGuidePath = $row['guide_pdf_path'] ?? null;
        $stmt->close();
    }

    // Counts per status for the summary chips.
    $stmt = $conn->prepare("
        SELECT send_status, COUNT(*) AS cnt
        FROM admission_tickets
        WHERE exam_date_id = ?
        GROUP BY send_status
    ");
    $stmt->bind_param('s', $selectedExamDateId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ticketCounts[$row['send_status']] = (int) $row['cnt'];
    }
    $stmt->close();
    $ticketCounts['total'] = $ticketCounts['staged'] + $ticketCounts['sent'] + $ticketCounts['failed'];

    // Paginated ticket rows. Qualify all columns with `at.` because we
    // JOIN exam_dates, registration_sheet_numbers, and registrations,
    // which share column names (reg_no, id).
    //
    // at.reg_no is stored internally as YYYYMM + original_reg_no
    // (e.g. 20260847650019). Strip the prefix with SUBSTRING(reg_no, 7)
    // for both the JOIN (rsn.reg_no is the 8-digit form) and the SELECT
    // (so the admin table shows the human-readable reg_no). The search
    // box still works because LIKE matches anywhere in the stored value.
    $where = ['at.exam_date_id = ?'];
    $params = [$selectedExamDateId];
    $types = 's';

    if (in_array($statusFilter, ['staged', 'sent', 'failed'], true)) {
        $where[] = 'at.send_status = ?';
        $params[] = $statusFilter;
        $types   .= 's';
    }
    if ($search !== '') {
        $where[] = '(at.xlsx_id LIKE ? OR at.reg_no LIKE ? OR r.full_name LIKE ? OR r.email LIKE ? OR r.mobile LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types   .= 'sssss';
    }
    $whereClause = implode(' AND ', $where);

    $dataQuery = "
        SELECT at.id, at.xlsx_id,
               SUBSTRING(at.reg_no, 7) AS reg_no,
               at.file_path,
               at.send_status, at.emailed_at, at.last_error,
               at.incorrect_disposal_at,
               r.full_name, r.email, r.mobile
        FROM admission_tickets at
        LEFT JOIN exam_dates ed ON ed.id = at.exam_date_id
        LEFT JOIN registration_sheet_numbers rsn
            ON rsn.reg_no = SUBSTRING(at.reg_no, 7)
            AND rsn.year  = YEAR(ed.exam_date)
            AND rsn.month = MONTH(ed.exam_date)
        LEFT JOIN registrations r ON r.id = rsn.registration_id
        WHERE $whereClause
        GROUP BY at.id
        ORDER BY CAST(at.xlsx_id AS UNSIGNED), at.xlsx_id ASC
    ";
    $countQuery = "
        SELECT COUNT(*) AS cnt
        FROM admission_tickets at
        LEFT JOIN exam_dates ed ON ed.id = at.exam_date_id
        LEFT JOIN registration_sheet_numbers rsn
            ON rsn.reg_no = SUBSTRING(at.reg_no, 7)
            AND rsn.year  = YEAR(ed.exam_date)
            AND rsn.month = MONTH(ed.exam_date)
        LEFT JOIN registrations r ON r.id = rsn.registration_id
        WHERE $whereClause
    ";

    $paginator = paginateQuery($dataQuery, $countQuery, $params, $types, $page, $perPage);
    $tickets   = $paginator['rows'];
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Admission Tickets</h1>
    <p class="page-subtitle">Upload Examinee List + PDFs, then review and send</p>
</div>

<?php $flash = getFlashMessage(); if ($flash): ?>
    <div class="alert alert-<?php echo e($flash['type']); ?>" style="white-space: pre-wrap;"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<!-- Upload form -->
<div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
    <h2 style="font-size: 16px; font-weight: 600; color: #1a202c; margin-bottom: 12px;">Stage New Tickets</h2>
    <form method="POST" action="<?php echo BASE_URL; ?>/api/admission-tickets/upload.php"
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
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Tickets ZIP</label>
            <input type="file" name="tickets_zip" accept=".zip" required
                   style="font-size: 13px; width: 100%;">
            <p style="font-size: 11px; color: #718096; margin-top: 4px;">PDFs named &lt;ID&gt;.pdf</p>
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Stage Tickets</button>
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
        <a href="<?php echo BASE_URL; ?>/pages/admission-tickets.php"
           style="font-size: 12px; color: #c53030; text-decoration: underline;">✕ Clear</a>
    </div>

    <!-- Exam Guide (optional, attached to every admission ticket email) -->
    <div style="background: white; border-radius: 12px; padding: 16px 20px; border: 1px solid #e2e8f0; margin-bottom: 16px;">
        <h2 style="font-size: 15px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">Exam Guide (optional)</h2>
        <p style="font-size: 12px; color: #718096; margin-bottom: 12px;">
            If set, this PDF is attached to every admission-ticket email sent for this exam date.
        </p>

        <?php if (!empty($examGuidePath) && is_readable($examGuidePath)): ?>
            <?php
            // Web URL for the guide — same path-mapping logic as ticket PDFs.
            $guideUrl = _ticketWebUrl($examGuidePath);
            ?>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #c6f6d5; color: #276749; border-radius: 12px; font-size: 13px; font-weight: 600;">
                    ✓ Guide uploaded
                </span>
                <?php if ($guideUrl): ?>
                    <a href="<?php echo e($guideUrl); ?>" target="_blank" style="font-size: 13px; color: #667eea; text-decoration: none;">📄 view current</a>
                <?php else: ?>
                    <span style="font-size: 12px; color: #718096; font-family: monospace;"><?php echo e(basename($examGuidePath)); ?></span>
                <?php endif; ?>
                <form method="POST" action="<?php echo BASE_URL; ?>/api/admission-tickets/guide.php" style="display: inline; margin-left: auto;"
                      onsubmit="return confirm('Remove the exam guide? Future admission-ticket emails for this exam will not include an attachment.');">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                    <input type="hidden" name="exam_date_id" value="<?php echo e($selectedExamDateId); ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;">Remove</button>
                </form>
            </div>

            <form method="POST" action="<?php echo BASE_URL; ?>/api/admission-tickets/guide.php"
                  enctype="multipart/form-data"
                  style="display: flex; gap: 8px; align-items: center; margin-top: 12px;">
                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                <input type="hidden" name="exam_date_id" value="<?php echo e($selectedExamDateId); ?>">
                <input type="file" name="guide_pdf" accept=".pdf,application/pdf" required
                       style="font-size: 13px;">
                <button type="submit" class="btn btn-secondary" style="padding: 6px 16px; font-size: 13px;">Replace Guide</button>
            </form>
        <?php else: ?>
            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
                <span style="display: inline-flex; align-items: center; padding: 6px 12px; background: #edf2f7; color: #4a5568; border-radius: 12px; font-size: 13px; font-weight: 600;">
                    ⚠ No guide uploaded
                </span>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/api/admission-tickets/guide.php"
                  enctype="multipart/form-data"
                  style="display: flex; gap: 8px; align-items: center;">
                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                <input type="hidden" name="exam_date_id" value="<?php echo e($selectedExamDateId); ?>">
                <input type="file" name="guide_pdf" accept=".pdf,application/pdf" required
                       style="font-size: 13px;">
                <button type="submit" class="btn btn-primary" style="padding: 6px 16px; font-size: 13px;">Upload Guide</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Summary chips -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <span style="background: #edf2f7; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Total: <strong><?php echo $ticketCounts['total']; ?></strong></span>
        <span style="background: #fefcbf; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Staged: <strong><?php echo $ticketCounts['staged']; ?></strong></span>
        <span style="background: #c6f6d5; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Sent: <strong><?php echo $ticketCounts['sent']; ?></strong></span>
        <span style="background: #fed7d7; padding: 6px 12px; border-radius: 16px; font-size: 13px;">Failed: <strong><?php echo $ticketCounts['failed']; ?></strong></span>
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
        <a href="<?php echo BASE_URL; ?>/pages/admission-tickets.php?exam_date_id=<?php echo e($selectedExamDateId); ?>"
           class="btn btn-secondary" style="padding: 6px 16px; font-size: 14px;">Clear</a>
    </form>

    <?php if (!empty($tickets)): ?>

        <!-- Send-actions form. Wraps the table so each row's checkbox
             belongs to the same POST. -->
        <form method="POST" action="<?php echo BASE_URL; ?>/api/admission-tickets/send.php" id="tickets-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
            <input type="hidden" name="exam_date_id" value="<?php echo e($selectedExamDateId); ?>">
            <input type="hidden" name="action" id="tickets-action" value="">

            <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                <button type="submit"
                        class="btn btn-primary"
                        onclick="return confirmSend('send_selected', 0);"
                        <?php if ($ticketCounts['staged'] + $ticketCounts['failed'] === 0) echo 'disabled'; ?>>
                    Send Selected
                </button>
                <button type="submit"
                        class="btn btn-secondary"
                        onclick="return confirmSend('send_all', <?php echo $ticketCounts['staged'] + $ticketCounts['failed']; ?>);"
                        <?php if ($ticketCounts['staged'] + $ticketCounts['failed'] === 0) echo 'disabled'; ?>>
                    Send All (<?php echo $ticketCounts['staged'] + $ticketCounts['failed']; ?> staged/failed)
                </button>
                <a href="<?php echo BASE_URL; ?>/pages/seat-tags.php?exam_date_id=<?php echo e($selectedExamDateId); ?>"
                   target="_blank"
                   class="btn btn-secondary"
                   style="text-decoration: none; <?php echo $ticketCounts['sent'] === 0 ? 'opacity: 0.5; pointer-events: none;' : ''; ?>"
                   <?php echo $ticketCounts['sent'] === 0 ? 'title="No sent tickets yet"' : 'title="Open printable seat tags in a new tab"'; ?>>
                    🖨️ Print Seat Tags (<?php echo $ticketCounts['sent']; ?>)
                </a>
                <?php if (isSuperAdmin()): ?>
                <!--
                    Super-admin manual cleanup: marks the selected sent
                    tickets as incorrect disposals and unstages them for
                    review/re-send. Uses formaction so it posts to a
                    different endpoint than the Send actions above while
                    still sharing the per-row checkboxes in this form.
                    Note: ticket PDFs are named by xlsx_id (the "reg_id"
                    column from Examinee List.xlsx), NOT by RegNumber.
                -->
                <button type="submit"
                        class="btn btn-danger"
                        formaction="<?php echo BASE_URL; ?>/api/admission-tickets/mark-incorrect.php"
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
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">RegNumber</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Name</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Email</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Phone</th>
                            <th style="padding: 10px 12px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Ticket</th>
                            <th style="padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t):
                            $statusColors = ['staged' => '#ed8936', 'sent' => '#48bb78', 'failed' => '#f56565'];
                            $color = $statusColors[$t['send_status']] ?? '#718096';
                            $staged  = in_array($t['send_status'], ['staged', 'failed'], true);
                            // Super admins can also pick sent rows (to mark them
                            // as incorrect disposals via the Mark Selected button).
                            $canCheck = $staged || isSuperAdmin();
                            // Web URL for the PDF. PDFs are named by xlsx_id
                            // (the "reg_id" / ID column from Examinee List.xlsx),
                            // NOT by RegNumber — see lib/ticket-staging.php.
                            $pdfUrl = _ticketWebUrl($t['file_path']);
                        ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 10px 12px; text-align: center;">
                                    <?php if ($canCheck): ?>
                                        <input type="checkbox" name="ticket_ids[]" value="<?php echo (int) $t['id']; ?>" class="ticket-checkbox" onchange="updateCount()">
                                    <?php else: ?>
                                        <input type="checkbox" disabled title="Already sent">
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px; font-family: monospace; font-size: 13px;"><?php echo e($t['xlsx_id']); ?></td>
                                <td style="padding: 10px 12px; font-family: monospace; font-size: 13px;"><?php echo e($t['reg_no']); ?></td>
                                <td style="padding: 10px 12px; font-size: 13px;">
                                    <?php if (!empty($t['full_name'])): ?>
                                        <?php echo e($t['full_name']); ?>
                                    <?php else: ?>
                                        <span style="color: #cbd5e0;" title="No registration found for this reg_no">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px; font-size: 13px;">
                                    <?php if (!empty($t['email'])): ?>
                                        <a href="mailto:<?php echo e($t['email']); ?>" style="color: #667eea; text-decoration: none;"><?php echo e($t['email']); ?></a>
                                    <?php else: ?>
                                        <span style="color: #cbd5e0;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px; font-size: 13px;">
                                    <?php if (!empty($t['mobile'])): ?>
                                        <?php echo e($t['mobile']); ?>
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
                                        <?php echo ucfirst($t['send_status']); ?>
                                    </span>
                                    <?php if (!empty($t['incorrect_disposal_at'])): ?>
                                        <div style="margin-top: 4px;">
                                            <span style="display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #fed7d7; color: #c53030;"
                                                  title="<?php echo e('Marked incorrect on ' . date('M j, Y g:i A', strtotime($t['incorrect_disposal_at']))); ?>">
                                                ⚠ Marked incorrect
                                            </span>
                                            <div style="font-size: 10px; color: #c53030; margin-top: 2px;">
                                                <?php echo e(date('M j, Y', strtotime($t['incorrect_disposal_at']))); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($t['send_status'] === 'sent' && !empty($t['emailed_at'])): ?>
                                        <div style="font-size: 11px; color: #718096; margin-top: 2px;">
                                            <?php echo e(date('M j, Y g:i A', strtotime($t['emailed_at']))); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($t['send_status'] === 'failed' && !empty($t['last_error'])): ?>
                                        <div style="font-size: 11px; color: #c53030; margin-top: 2px; max-width: 380px;">
                                            <?php echo e($t['last_error']); ?>
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
            BASE_URL . '/pages/admission-tickets.php',
            $paginationParams
        );
        ?>

    <?php else: ?>
        <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
            <div style="font-size: 48px; margin-bottom: 16px;">🎫</div>
            <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Tickets Staged Yet</h3>
            <p style="color: #718096; font-size: 14px;">
                Use the form above to upload the Examinee List xlsx and the PDF zip for this exam date.
            </p>
        </div>
    <?php endif; ?>

<?php else: ?>

    <!-- Exam dates overview -->
    <?php if (!empty($examDates)): ?>
        <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
            <h2 style="font-size: 16px; font-weight: 600; color: #1a202c; margin-bottom: 12px;">Exam Dates</h2>
            <div style="display: grid; gap: 12px;">
                <?php foreach ($examDates as $ed):
                    // Ticket counts per exam
                    $stmt = $conn->prepare("
                        SELECT
                            SUM(send_status = 'staged') AS staged,
                            SUM(send_status = 'sent')   AS sent,
                            SUM(send_status = 'failed') AS failed
                        FROM admission_tickets
                        WHERE exam_date_id = ?
                    ");
                    $stmt->bind_param('s', $ed['id']);
                    $stmt->execute();
                    $cnt = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                ?>
                    <a href="<?php echo BASE_URL; ?>/pages/admission-tickets.php?exam_date_id=<?php echo e($ed['id']); ?>"
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
            <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Upcoming Exam Dates</h3>
            <p style="color: #718096; font-size: 14px;">Add an exam date first to enable ticket distribution.</p>
        </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Batch send progress overlay -->
<div id="send-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:32px; border-radius:12px; text-align:center; min-width:360px; max-width:480px; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="font-size:18px; margin:0 0 16px; color:#1a202c;">Sending admission tickets…</h3>
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
    document.querySelectorAll('input.ticket-checkbox[type="checkbox"]').forEach(function (cb) {
        cb.checked = master.checked;
    });
    updateCount();
}
function updateCount() {
    var n = document.querySelectorAll('input.ticket-checkbox[type="checkbox"]:checked').length;
    var el = document.getElementById('selected-count');
    if (el) el.textContent = n;
}
// Gate every send action behind a confirm so a stray click can't email
// applicants. 'send_all' is the mass-send case; 'send_selected' still
// asks (with the live selection count) because email sends are irreversible.
function confirmSend(action, allCount) {
    var n = action === 'send_all'
        ? allCount
        : document.querySelectorAll('input.ticket-checkbox[type="checkbox"]:checked').length;
    if (n <= 0) {
        alert('No tickets selected. Tick rows first, or use Send All.');
        return false;
    }
    var msg = 'Email admission tickets to ' + n + ' examinee' + (n === 1 ? '' : 's') + ' for this exam date?';
    if (action === 'send_all') {
        msg += '\n\nThis sends to every staged or failed row. It cannot be undone.';
    }
    if (!confirm(msg)) return false;
    // Start AJAX batch send instead of submitting the form (which times
    // out on the server for large batches).
    batchSend(action);
    return false; // prevent traditional form submit
}

// Batch send: processes tickets in groups of 15 via sequential AJAX
// requests, so each HTTP request finishes well within the server's
// timeout. Shows a progress overlay and reloads on completion.
async function batchSend(action) {
    var form = document.getElementById('tickets-form');
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
            document.querySelectorAll('input.ticket-checkbox[type="checkbox"]:checked').forEach(function (cb) {
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
                batch.forEach(function (id) { params.append('ticket_ids[]', id); });

                var data = await postBatch(sendUrl, params);
                totalSent += data.sent;
                totalFailed += data.failed;
                processed += data.processed;

                bar.style.width = Math.round((processed / total) * 100) + '%';
                text.textContent = totalSent + ' sent' + (totalFailed ? ', ' + totalFailed + ' failed' : '') + ' (' + processed + '/' + total + ')';
            }
        } else {
            // send_all: server picks the next batch each time
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
                // If the entire batch failed, stop — likely SMTP is down.
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
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    });
    var data = await resp.json();
    if (data.error) throw new Error(data.error);
    return data;
}

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
// Super-admin manual cleanup. Marks selected sent rows as incorrect
// disposals and unstages them. Doesn't email anyone — just resets the
// row state and stamps a marker for the audit trail.
function confirmMarkIncorrect() {
    var n = document.querySelectorAll('input.ticket-checkbox[type="checkbox"]:checked').length;
    if (n <= 0) {
        alert('No tickets selected. Tick rows first.');
        return false;
    }
    return confirm(
        'Mark ' + n + ' ticket(s) as INCORRECT disposal and reset to staged?\n\n' +
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
 * Convert an absolute PDF path stored in admission_tickets.file_path
 * into a web URL reachable from the admin panel.
 *
 * The upload stored under UPLOAD_PATH (e.g. /home/.../admin/uploads/).
 * The web root maps /admin/ to that directory, so /admin/uploads/...
 * is the public path.
 */
function _ticketWebUrl(string $absPath): string {
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
