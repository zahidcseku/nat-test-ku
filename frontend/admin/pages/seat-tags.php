<?php
/**
 * Seat Tags — printable view
 *
 * Renders two-column grid of seat tags for every examinee whose admission
 * ticket for the selected exam date has send_status = 'sent'. Each tag:
 * photo on the left, Name / DOB / ID on the right. Admin uses the
 * browser's "Save as PDF" from the Print button.
 *
 * Reachable from admission-tickets.php via the "Print Seat Tags" button.
 */

require_once __DIR__ . '/../auth/middleware.php';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$examDateId = trim($_GET['exam_date_id'] ?? '');

$conn = getDbConnection();

// Resolve the exam date (YYYY-MM-DD) for the header label.
$examDateLabel = '';
if ($conn && $examDateId !== '') {
    $stmt = $conn->prepare("SELECT exam_date FROM exam_dates WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $examDateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $examDateLabel = formatDate($row['exam_date']);
        }
        $stmt->close();
    }
}

// Pull examinees with sent admission tickets.
//
// at.reg_no is stored internally as YYYYMM + original_reg_no.
// SUBSTRING(reg_no, 7) yields the 8-digit reg_no to JOIN against rsn.
// Period filter scopes rsn to the exam month.
$examinees = [];
if ($conn && $examDateId !== '') {
    $sql = "
        SELECT at.xlsx_id, r.full_name, r.dob, r.photo_storage_path
        FROM admission_tickets at
        LEFT JOIN exam_dates ed ON ed.id = at.exam_date_id
        LEFT JOIN registration_sheet_numbers rsn
            ON rsn.reg_no = SUBSTRING(at.reg_no, 7)
            AND rsn.year  = YEAR(ed.exam_date)
            AND rsn.month = MONTH(ed.exam_date)
        LEFT JOIN registrations r ON r.id = rsn.registration_id
        WHERE at.exam_date_id = ? AND at.send_status = 'sent'
        ORDER BY CAST(at.xlsx_id AS UNSIGNED), at.xlsx_id ASC
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $examDateId);
        $stmt->execute();
        $examinees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seat Tags — <?php echo e($examDateLabel ?: 'Exam'); ?></title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1a202c;
            background: #edf2f7;
            margin: 0;
            padding: 20px;
        }

        /* Screen-only toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 16px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 20px;
            position: sticky;
            top: 20px;
            z-index: 10;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .toolbar h1 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .toolbar .meta {
            font-size: 13px;
            color: #718096;
        }

        .toolbar .spacer { flex: 1; }

        .toolbar button, .toolbar a {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 18px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .toolbar a.secondary {
            background: #edf2f7;
            color: #4a5568;
        }

        /* Empty state */
        .empty {
            background: white;
            border-radius: 12px;
            padding: 60px 20px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .empty .icon { font-size: 48px; margin-bottom: 16px; }

        /* Two-column grid of seat tags */
        .tags-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5mm;
        }

        .seat-tag {
            display: flex;
            gap: 5mm;
            background: white;
            border: 1px solid #4a5568;
            border-radius: 4px;
            padding: 5mm;
            min-height: 45mm;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .seat-tag .photo {
            width: 30mm;
            height: 35mm;
            object-fit: cover;
            flex-shrink: 0;
            border: 1px solid #cbd5e0;
            border-radius: 2px;
            background: #edf2f7;
        }

        .seat-tag .photo-placeholder {
            width: 30mm;
            height: 35mm;
            flex-shrink: 0;
            border: 1px dashed #a0aec0;
            border-radius: 2px;
            background: #f7fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a0aec0;
            font-size: 10px;
            text-align: center;
        }

        .seat-tag .info {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 4mm;
            min-width: 0;
        }

        .seat-tag .info .row {
            font-size: 12pt;
            line-height: 1.3;
            word-break: break-word;
        }

        .seat-tag .info .label {
            font-size: 9pt;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 1mm;
        }

        .seat-tag .info .value {
            font-weight: 600;
            font-size: 12pt;
        }

        .seat-tag .info .id-value {
            font-family: monospace;
            font-size: 14pt;
            font-weight: 700;
            color: #1a365d;
        }

        /* Print rules — strip chrome, set page geometry */
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .toolbar { display: none !important; }

            .tags-grid { gap: 4mm; }

            .seat-tag {
                box-shadow: none;
                border: 1px solid #4a5568;
            }
        }
    </style>
</head>
<body>

<?php if ($examDateId === ''): ?>

    <div class="empty">
        <div class="icon">⚠️</div>
        <h2 style="font-size: 18px; margin-bottom: 8px;">No Exam Date Selected</h2>
        <p style="color: #718096; font-size: 14px;">
            Open this page from the Admission Tickets page using the "Print Seat Tags" button.
        </p>
        <p style="margin-top: 20px;">
            <a href="<?php echo e(BASE_URL); ?>/pages/admission-tickets.php" class="secondary" style="display: inline-block; background: #edf2f7; color: #4a5568; padding: 8px 18px; border-radius: 6px; text-decoration: none; font-size: 14px;">
                ← Back to Admission Tickets
            </a>
        </p>
    </div>

<?php elseif (empty($examinees)): ?>

    <div class="toolbar">
        <h1>Seat Tags</h1>
        <span class="meta"><?php echo e($examDateLabel); ?> · 0 examinees</span>
        <div class="spacer"></div>
        <a href="<?php echo e(BASE_URL); ?>/pages/admission-tickets.php?exam_date_id=<?php echo e($examDateId); ?>" class="secondary">
            ← Back
        </a>
    </div>

    <div class="empty">
        <div class="icon">🎫</div>
        <h2 style="font-size: 18px; margin-bottom: 8px;">No Sent Tickets Yet</h2>
        <p style="color: #718096; font-size: 14px;">
            Seat tags are only available for examinees whose admission tickets have been sent.
        </p>
    </div>

<?php else: ?>

    <div class="toolbar">
        <h1>Seat Tags</h1>
        <span class="meta">
            <?php echo e($examDateLabel); ?> · <?php echo count($examinees); ?> examinee<?php echo count($examinees) === 1 ? '' : 's'; ?>
        </span>
        <div class="spacer"></div>
        <a href="<?php echo e(BASE_URL); ?>/pages/admission-tickets.php?exam_date_id=<?php echo e($examDateId); ?>" class="secondary">
            ← Back
        </a>
        <button type="button" onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>

    <div class="tags-grid">
        <?php foreach ($examinees as $ex): ?>
            <?php
                $photoUrl = '';
                if (!empty($ex['photo_storage_path'])) {
                    $photoUrl = intakePathToUrl($ex['photo_storage_path']);
                }
                $dob = !empty($ex['dob']) ? formatDate($ex['dob'], 'Y-m-d') : '—';
                $name = !empty($ex['full_name']) ? $ex['full_name'] : '—';
                $id   = $ex['xlsx_id'] ?? '—';
            ?>
            <div class="seat-tag">
                <?php if ($photoUrl !== ''): ?>
                    <img class="photo" src="<?php echo e($photoUrl); ?>" alt="">
                <?php else: ?>
                    <div class="photo-placeholder">No<br>Photo</div>
                <?php endif; ?>

                <div class="info">
                    <div class="row">
                        <span class="label">Name</span>
                        <span class="value"><?php echo e($name); ?></span>
                    </div>
                    <div class="row">
                        <span class="label">Date of Birth</span>
                        <span class="value"><?php echo e($dob); ?></span>
                    </div>
                    <div class="row">
                        <span class="label">ID</span>
                        <span class="id-value"><?php echo e($id); ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

</body>
</html>
