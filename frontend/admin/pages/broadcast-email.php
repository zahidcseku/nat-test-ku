<?php
/**
 * Broadcast Email Page
 *
 * Send an ad-hoc announcement to every approved examinee for a chosen exam
 * date. Two-step flow: compose → preview recipient list + sample email →
 * confirm and send (handled by api/broadcast-email/send.php).
 *
 * Placeholders {full_name} and {exam_date} are substituted per recipient
 * in both subject and body.
 */

require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../lib/email-templates.php';
require_once __DIR__ . '/../lib/broadcast-email.php';

$pageTitle = 'Broadcast Email';
$currentPage = 'broadcast-email';

$conn = getDbConnection();

// --- Draft source: recover stashed draft on a bounced send, else empty ---
$draft = ['exam_date_id' => '', 'subject' => '', 'body' => ''];
if (isset($_SESSION['broadcast_draft'])) {
    $draft = array_merge($draft, $_SESSION['broadcast_draft']);
    unset($_SESSION['broadcast_draft']);
}

// --- Handle POST (preview or edit-draft round-trip) ---
$view           = 'compose';
$recipients     = [];
$previewExamDate = null;
$previewError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Anything POSTed re-becomes the working draft so nothing is lost.
    $draft = array_merge($draft, [
        'exam_date_id' => trim($_POST['exam_date_id'] ?? ''),
        'subject'      => trim($_POST['subject'] ?? ''),
        'body'         => $_POST['body'] ?? '',
    ]);

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $previewError = 'Invalid CSRF token. Please try again.';
    } elseif ($action === 'preview') {
        if ($draft['exam_date_id'] === '' || $draft['subject'] === '' || $draft['body'] === '') {
            $previewError = 'Exam date, subject, and body are all required.';
        } else {
            $dateStmt = $conn->prepare('SELECT exam_date FROM exam_dates WHERE id = ?');
            $dateStmt->bind_param('s', $draft['exam_date_id']);
            $dateStmt->execute();
            $previewExamDate = ($dateStmt->get_result()->fetch_assoc() ?? [])['exam_date'] ?? null;
            $dateStmt->close();

            if ($previewExamDate === null) {
                $previewError = 'Selected exam date no longer exists.';
            } else {
                $recipients = fetchBroadcastRecipients($conn, $previewExamDate);
                if (empty($recipients)) {
                    $previewError = 'No approved examinees found for ' . formatDate($previewExamDate) . '.';
                } else {
                    $view = 'preview';
                }
            }
        }
    }
    // action === 'compose' (Edit Draft button): just renders compose with the carried draft.
}

// --- Exam date dropdown options ---
$examDates = [];
$edStmt = $conn->prepare('SELECT id, exam_date FROM exam_dates ORDER BY exam_date ASC');
if ($edStmt) {
    $edStmt->execute();
    $examDates = $edStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $edStmt->close();
}

// --- Send results (failures) after a send-handler redirect ---
$failures = null;
if (isset($_GET['last_send']) && isset($_SESSION['broadcast_failures'])) {
    $failures = $_SESSION['broadcast_failures'];
    unset($_SESSION['broadcast_failures']);
}

$csrfToken = generateCsrfToken();

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Broadcast Email</h1>
    <p class="page-subtitle">Send an announcement to all approved examinees for an exam date</p>
</div>

<?php if ($failures !== null): ?>
    <div style="background: #fff5f5; border: 1px solid #feb2b2; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
        <h3 style="font-size: 16px; font-weight: 600; color: #c53030; margin: 0 0 12px;">Failed deliveries</h3>
        <?php if (empty($failures)): ?>
            <p style="color: #4a5568; font-size: 14px; margin: 0;">No failures — every recipient received the broadcast.</p>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="border-bottom: 1px solid #feb2b2; text-align: left;">
                        <th style="padding: 8px 12px;">Name</th>
                        <th style="padding: 8px 12px;">Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($failures as $f): ?>
                        <tr style="border-bottom: 1px solid #fed7d7;">
                            <td style="padding: 8px 12px;"><?php echo e($f['name']); ?></td>
                            <td style="padding: 8px 12px;"><?php echo e($f['email']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="color: #718096; font-size: 13px; margin: 12px 0 0;">See the Emails page for the full SMTP error on each row.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($previewError !== null): ?>
    <div style="background: #fff5f5; border: 1px solid #feb2b2; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; color: #c53030; font-size: 14px;">
        <?php echo e($previewError); ?>
    </div>
<?php endif; ?>

<?php if ($view === 'preview'): ?>
    <?php
        $recipientCount = count($recipients);
        $firstRecipient = $recipients[0];
        $formattedDate  = formatDate($previewExamDate);
        $sampleVars     = ['full_name' => $firstRecipient['full_name'], 'exam_date' => $formattedDate];
        $sampleSubject  = _substituteTemplateVars($draft['subject'], $sampleVars);
        $sampleBody     = _substituteTemplateVars($draft['body'], $sampleVars);
        $sampleDoc      = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family: Arial, Helvetica, sans-serif; color: #1a202c; margin: 0; padding: 16px;">'
                        . $sampleBody
                        . '</body></html>';
        $visibleCount   = min(50, $recipientCount);
        $hiddenCount    = $recipientCount - $visibleCount;
    ?>

    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
        <div style="font-size: 15px; color: #1a202c; margin-bottom: 4px;">
            <strong>Sending to <?php echo $recipientCount; ?> recipient<?php echo $recipientCount === 1 ? '' : 's'; ?></strong>
            for <?php echo e($formattedDate); ?>
        </div>
        <div style="font-size: 13px; color: #718096;">Review the list and sample email below before confirming.</div>
    </div>

    <!-- Recipient list -->
    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 24px;">
        <div style="max-height: 320px; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="position: sticky; top: 0;">
                    <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Name</th>
                        <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($recipients, 0, $visibleCount) as $r): ?>
                        <tr style="border-bottom: 1px solid #edf2f7;">
                            <td style="padding: 10px 16px; font-size: 14px;"><?php echo e($r['full_name']); ?></td>
                            <td style="padding: 10px 16px; font-size: 14px; color: #667eea;"><?php echo e($r['email']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($hiddenCount > 0): ?>
            <div style="padding: 12px 16px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0;">
                Showing <?php echo $visibleCount; ?> of <?php echo $recipientCount; ?> — all <?php echo $recipientCount; ?> will receive the email.
            </div>
        <?php endif; ?>
    </div>

    <!-- Sample rendered email -->
    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
        <div style="font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Subject</div>
        <div style="font-size: 15px; font-weight: 500; color: #1a202c; margin-bottom: 16px;"><?php echo e($sampleSubject); ?></div>
        <div style="font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Preview (rendered for <?php echo e($firstRecipient['full_name']); ?>)</div>
        <iframe srcdoc="<?php echo e($sampleDoc); ?>" style="width: 100%; height: 360px; border: 1px solid #e2e8f0; border-radius: 8px; background: white;"></iframe>
    </div>

    <!-- Confirm / Edit actions -->
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <form method="POST" action="<?php echo BASE_URL; ?>/api/broadcast-email/send.php" style="flex: 0;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="exam_date_id" value="<?php echo e($draft['exam_date_id']); ?>">
            <input type="hidden" name="subject" value="<?php echo e($draft['subject']); ?>">
            <input type="hidden" name="body" value="<?php echo e($draft['body']); ?>">
            <input type="hidden" name="previewed_count" value="<?php echo $recipientCount; ?>">
            <button type="submit" class="btn btn-primary">Confirm &amp; Send to <?php echo $recipientCount; ?></button>
        </form>
        <form method="POST" action="<?php echo BASE_URL; ?>/pages/broadcast-email.php" style="flex: 0;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="compose">
            <input type="hidden" name="exam_date_id" value="<?php echo e($draft['exam_date_id']); ?>">
            <input type="hidden" name="subject" value="<?php echo e($draft['subject']); ?>">
            <input type="hidden" name="body" value="<?php echo e($draft['body']); ?>">
            <button type="submit" class="btn btn-secondary">Edit Draft</button>
        </form>
    </div>

<?php else: // compose ?>

    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
        <form method="POST" action="<?php echo BASE_URL; ?>/pages/broadcast-email.php">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="preview">

            <div style="margin-bottom: 20px;">
                <label for="exam_date_id" style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam date</label>
                <select id="exam_date_id" name="exam_date_id" required style="width: 100%; max-width: 400px; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    <option value="">Select an exam date…</option>
                    <?php foreach ($examDates as $ed): ?>
                        <option value="<?php echo e($ed['id']); ?>" <?php echo $draft['exam_date_id'] === $ed['id'] ? 'selected' : ''; ?>>
                            <?php echo e(formatDate($ed['exam_date'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="subject" style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Subject</label>
                <input type="text" id="subject" name="subject" maxlength="255" value="<?php echo e($draft['subject']); ?>" required style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;" placeholder="Email subject line">
            </div>

            <div style="margin-bottom: 12px;">
                <label for="body" style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Message (HTML allowed)</label>
                <textarea id="body" name="body" rows="14" required style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; font-family: monospace;" placeholder="Dear {full_name},&#10;&#10;…"><?php echo e($draft['body']); ?></textarea>
            </div>

            <div style="background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #4a5568; margin-bottom: 20px;">
                <strong>Placeholders</strong> (work in subject and body):<br>
                <code style="background: #edf2f7; padding: 2px 6px; border-radius: 4px;">{full_name}</code> — recipient's name &nbsp;
                <code style="background: #edf2f7; padding: 2px 6px; border-radius: 4px;">{exam_date}</code> — exam date (e.g. <?php echo e(date('F j, Y')); ?>)
            </div>

            <button type="submit" class="btn btn-primary">Preview Recipients</button>
        </form>
    </div>

    <p style="color: #718096; font-size: 13px; margin-top: 16px;">
        Recipients are every approved registration for the selected date. Duplicate email addresses receive only one copy.
    </p>

<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
