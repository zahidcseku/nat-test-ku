<?php
/**
 * Certificate Mail Labels — printable view.
 *
 * Renders a two-column grid of mailing labels for the selected
 * certificate request IDs (only paid rows are included). Admin uses
 * the browser's "Save as PDF" from the Print button. Same pattern as
 * pages/seat-tags.php.
 */

require_once __DIR__ . '/../auth/middleware.php';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

// Collect IDs from the query string (comma-separated) and sanitize to UUIDs.
$rawIds = $_GET['ids'] ?? '';
$ids = [];
foreach (explode(',', $rawIds) as $candidate) {
    $candidate = trim($candidate);
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate)) {
        $ids[] = $candidate;
    }
}

$labels = [];
if (!empty($ids)) {
    $conn = getDbConnection();
    if ($conn) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('s', count($ids));
        $sql = "
            SELECT cr.recipient_name, cr.recipient_phone, cr.house_street,
                   cr.area_thana, cr.district, cr.postal_code,
                   cr.xlsx_id, cr.tracking_number, cr.created_at,
                   ed.exam_date
            FROM certificate_requests cr
            LEFT JOIN exam_dates ed ON ed.id = cr.exam_date_id
            WHERE cr.id IN ($placeholders)
              AND cr.payment_status = 'paid'
            ORDER BY cr.district ASC, cr.recipient_name ASC
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $labels = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Mail Labels</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1a202c;
            background: #edf2f7;
            margin: 0;
            padding: 20px;
        }

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
        .toolbar h1 { font-size: 16px; font-weight: 600; margin: 0; }
        .toolbar .meta { font-size: 13px; color: #718096; }
        .toolbar .spacer { flex: 1; }
        .toolbar button, .toolbar a {
            background: #667eea; color: white; border: none; border-radius: 6px;
            padding: 8px 18px; font-size: 14px; font-weight: 500; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .toolbar a.secondary { background: #edf2f7; color: #4a5568; }

        .empty {
            background: white; border-radius: 12px; padding: 60px 20px;
            text-align: center; border: 1px solid #e2e8f0;
        }
        .empty .icon { font-size: 48px; margin-bottom: 16px; }

        .labels-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5mm;
        }

        .mail-label {
            background: white;
            border: 1px solid #4a5568;
            border-radius: 4px;
            padding: 8mm;
            min-height: 55mm;
            page-break-inside: avoid;
            break-inside: avoid;
            display: flex;
            flex-direction: column;
            gap: 3mm;
        }

        .mail-label .to {
            font-size: 14pt;
            font-weight: 700;
            line-height: 1.3;
            word-break: break-word;
        }
        .mail-label .addr-line {
            font-size: 12pt;
            line-height: 1.35;
            color: #2d3748;
        }
        .mail-label .meta {
            font-size: 9pt;
            color: #718096;
            border-top: 1px dashed #cbd5e0;
            padding-top: 2mm;
            margin-top: auto;
        }

        @page { size: A4 portrait; margin: 10mm; }

        @media print {
            body { background: white; padding: 0; margin: 0; }
            .toolbar { display: none !important; }
            .labels-grid { gap: 4mm; }
            .mail-label { box-shadow: none; border: 1px solid #4a5568; }
        }
    </style>
</head>
<body>

<?php if (empty($labels)): ?>

    <div class="toolbar">
        <h1>Certificate Mail Labels</h1>
        <span class="meta">No paid requests selected</span>
        <div class="spacer"></div>
        <a href="<?php echo e(BASE_URL); ?>/pages/certificate-requests.php" class="secondary">← Back</a>
    </div>

    <div class="empty">
        <div class="icon">📭</div>
        <h2 style="font-size: 18px; margin-bottom: 8px;">No Labels to Print</h2>
        <p style="color: #718096; font-size: 14px;">
            Select paid requests on the Certificates page and click "Print Labels for Selected".
            Only requests with <strong>payment_status = paid</strong> are included.
        </p>
    </div>

<?php else: ?>

    <div class="toolbar">
        <h1>Certificate Mail Labels</h1>
        <span class="meta"><?php echo count($labels); ?> label<?php echo count($labels) === 1 ? '' : 's'; ?> · sorted by district</span>
        <div class="spacer"></div>
        <a href="<?php echo e(BASE_URL); ?>/pages/certificate-requests.php" class="secondary">← Back</a>
        <button type="button" onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>

    <div class="labels-grid">
        <?php foreach ($labels as $i => $lbl): ?>
            <?php
                $examDate = !empty($lbl['exam_date']) ? formatDate($lbl['exam_date']) : '—';
            ?>
            <div class="mail-label">
                <div class="to"><?php echo e(html_entity_decode($lbl['recipient_name'], ENT_QUOTES, 'UTF-8')); ?></div>
                <div class="addr-line"><?php echo e($lbl['house_street']); ?></div>
                <div class="addr-line"><?php echo e($lbl['area_thana']); ?></div>
                <div class="addr-line"><?php echo e($lbl['district'] . ($lbl['postal_code'] ? ' ' . $lbl['postal_code'] : '')); ?></div>
                <div class="addr-line">Bangladesh</div>
                <div class="meta">
                    Examinee ID: <?php echo e($lbl['xlsx_id']); ?> · Exam: <?php echo e($examDate); ?><br>
                    ☎ <?php echo e($lbl['recipient_phone']); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

</body>
</html>
