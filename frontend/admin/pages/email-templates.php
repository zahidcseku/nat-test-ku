<?php
/**
 * Email Templates page
 *
 * Two modes:
 *   - No ?key=...: list every template as a card.
 *   - ?key=...:    edit form with subject input, body textarea, variable
 *                  reference panel (click-to-insert), and live preview
 *                  iframe (client-side substitution using example values).
 *
 * Missing template_key rows are auto-seeded from defaults on each load,
 * so the DB converges on the full set of known templates.
 */

require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../lib/email-templates.php';

$pageTitle = 'Email Templates';
$currentPage = 'email-templates';

$conn = getDbConnection();
if (!$conn) {
    require_once __DIR__ . '/../templates/header.php';
    echo '<div class="alert alert-error">Database connection failed.</div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// Auto-seed any missing template rows from defaults.
$seeded = seedMissingEmailTemplates();

// All known template keys + metadata, ordered for display.
$defaults = emailTemplateDefaults();

// Load all DB rows (whatever exists) so the list view can show
// last-updated timestamps.
$dbRows = [];
$stmt = $conn->prepare("
    SELECT template_key, name, description, subject, updated_at
    FROM email_templates
    ORDER BY template_key ASC
");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $dbRows[$r['template_key']] = $r;
    }
    $stmt->close();
}

// --- Edit mode ---------------------------------------------------
$selectedKey = trim($_GET['key'] ?? '');
$editingTemplate = null;

if ($selectedKey !== '') {
    if (!isset($defaults[$selectedKey])) {
        setFlashMessage("Unknown template key: {$selectedKey}", 'error');
        header('Location: ' . BASE_URL . '/pages/email-templates.php');
        exit;
    }
    $row = loadEmailTemplateRow($selectedKey);
    if (!$row) {
        // seedMissingEmailTemplates() above should have inserted it,
        // but fall back gracefully if not.
        $def = $defaults[$selectedKey];
        $row = [
            'template_key' => $selectedKey,
            'name'         => $def['name'],
            'description'  => $def['description'],
            'subject'      => $def['subject'],
            'body_html'    => $def['body'],
            'available_variables' => json_encode($def['variables']),
            'updated_at'   => null,
        ];
    }
    $editingTemplate = $row;
}

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Email Templates</h1>
    <p class="page-subtitle">Edit the subject and body of every email the system sends</p>
</div>

<?php if ($seeded > 0): ?>
    <div class="alert alert-info">
        Seeded <?php echo (int) $seeded; ?> new template(s) from defaults.
    </div>
<?php endif; ?>

<?php $flash = getFlashMessage(); if ($flash): ?>
    <div class="alert alert-<?php echo e($flash['type']); ?>" style="white-space: pre-wrap;"><?php echo e($flash['message']); ?></div>
<?php endif; ?>

<?php if ($editingTemplate): ?>

    <?php
    $def = $defaults[$selectedKey];
    $variables = json_decode($editingTemplate['available_variables'] ?? '[]', true) ?: $def['variables'];
    $updatedAt = !empty($editingTemplate['updated_at'])
        ? date('M j, Y g:i A', strtotime($editingTemplate['updated_at']))
        : null;
    ?>

    <div style="margin-bottom: 16px;">
        <a href="<?php echo BASE_URL; ?>/pages/email-templates.php"
           style="font-size: 13px; color: #667eea; text-decoration: none;">← All templates</a>
    </div>

    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 16px;">
        <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 4px;">
            <?php echo e($editingTemplate['name']); ?>
        </h2>
        <p style="font-size: 13px; color: #718096; margin: 0 0 4px;">
            Key: <code style="background: #edf2f7; padding: 2px 6px; border-radius: 4px; font-size: 12px;"><?php echo e($selectedKey); ?></code>
        </p>
        <?php if (!empty($editingTemplate['description'])): ?>
            <p style="font-size: 13px; color: #4a5568; margin: 8px 0 0;"><?php echo e($editingTemplate['description']); ?></p>
        <?php endif; ?>
        <?php if ($updatedAt): ?>
            <p style="font-size: 12px; color: #718096; margin: 8px 0 0;">Last updated: <?php echo e($updatedAt); ?></p>
        <?php endif; ?>
    </div>

    <form method="POST" action="<?php echo BASE_URL; ?>/api/email-templates/save.php" id="template-form">
        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
        <input type="hidden" name="template_key" value="<?php echo e($selectedKey); ?>">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start;">

            <!-- Editor column -->
            <div style="background: white; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0;">
                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">Subject</label>
                    <input type="text" name="subject" id="template-subject"
                           value="<?php echo e($editingTemplate['subject']); ?>"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;"
                           required>
                </div>

                <div style="margin-bottom: 12px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 4px;">
                        Body HTML
                    </label>
                    <textarea name="body" id="template-body"
                              rows="22"
                              spellcheck="false"
                              style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-family: 'SF Mono', Menlo, Consolas, monospace; font-size: 12px; line-height: 1.5; resize: vertical;"><?php echo e($editingTemplate['body_html']); ?></textarea>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="<?php echo BASE_URL; ?>/pages/email-templates.php?key=<?php echo e($selectedKey); ?>"
                       class="btn btn-secondary" style="text-decoration: none;">Cancel</a>
                </div>
            </div>

            <!-- Variable reference column -->
            <div style="background: white; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0;">
                <h3 style="font-size: 14px; font-weight: 600; color: #1a202c; margin: 0 0 4px;">Variables</h3>
                <p style="font-size: 12px; color: #718096; margin: 0 0 12px;">
                    Click any variable to insert it at the cursor.
                </p>
                <div style="display: grid; gap: 8px;">
                    <?php foreach ($variables as $v): ?>
                        <div style="padding: 8px 10px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer;"
                             onclick="insertVar('<?php echo e($v['key']); ?>')"
                             title="Click to insert at cursor">
                            <div style="font-family: monospace; font-size: 12px; color: #667eea; font-weight: 600;">
                                {<?php echo e($v['key']); ?>}
                            </div>
                            <div style="font-size: 12px; color: #4a5568; margin-top: 2px;">
                                <?php echo e($v['label']); ?>
                            </div>
                            <?php if (!empty($v['example'])): ?>
                                <div style="font-size: 11px; color: #718096; margin-top: 2px;">
                                    Example: <?php echo e($v['example']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </form>

    <!-- Reset-to-default form (separate so Save submit doesn't conflict) -->
    <form method="POST" action="<?php echo BASE_URL; ?>/api/email-templates/reset.php"
          style="margin-top: 16px;"
          onsubmit="return confirm('Reset this template to its default subject and body? Your edits will be lost.');">
        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
        <input type="hidden" name="template_key" value="<?php echo e($selectedKey); ?>">
        <button type="submit" class="btn btn-danger">Reset to Default</button>
    </form>

    <!-- Live preview -->
    <div style="background: white; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; margin-top: 24px;">
        <h3 style="font-size: 14px; font-weight: 600; color: #1a202c; margin: 0 0 4px;">Live Preview</h3>
        <p style="font-size: 12px; color: #718096; margin: 0 0 12px;">
            Rendered with example values. Actual sends use real recipient data.
        </p>
        <div style="font-size: 13px; margin-bottom: 8px;">
            <strong>Subject:</strong> <span id="preview-subject" style="color: #4a5568;"></span>
        </div>
        <iframe id="preview-frame"
                style="width: 100%; height: 500px; border: 1px solid #e2e8f0; border-radius: 6px; background: white;"
                sandbox="allow-same-origin"></iframe>
    </div>

    <script>
    // Example values from the server — used to populate the preview.
    var varExamples = <?php echo json_encode(
        array_combine(
            array_column($variables, 'key'),
            array_column($variables, 'example')
        ) ?: [],
        JSON_FORCE_OBJECT
    ); ?>;

    function renderPreview() {
        var body = document.getElementById('template-body').value;
        var subj = document.getElementById('template-subject').value;

        // Substitute each {var} with its example value.
        Object.keys(varExamples).forEach(function (k) {
            // Replace both escaped (in case of HTML entities in textarea) and raw.
            var pattern = new RegExp('\\{' + k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\}', 'g');
            body = body.replace(pattern, varExamples[k]);
            subj = subj.replace(pattern, varExamples[k]);
        });

        document.getElementById('preview-subject').textContent = subj;

        var frame = document.getElementById('preview-frame');
        frame.srcdoc = body;
    }

    function insertVar(key) {
        var body = document.getElementById('template-body');
        var token = '{' + key + '}';
        var start = body.selectionStart;
        var end = body.selectionEnd;
        body.value = body.value.substring(0, start) + token + body.value.substring(end);
        body.selectionStart = body.selectionEnd = start + token.length;
        body.focus();
        renderPreview();
    }

    document.getElementById('template-body').addEventListener('input', renderPreview);
    document.getElementById('template-subject').addEventListener('input', renderPreview);
    renderPreview();
    </script>

<?php else: ?>

    <!-- List view -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
        <?php foreach ($defaults as $key => $def):
            $row = $dbRows[$key] ?? null;
            $updatedAt = $row && !empty($row['updated_at'])
                ? date('M j, Y g:i A', strtotime($row['updated_at']))
                : null;
        ?>
            <a href="<?php echo BASE_URL; ?>/pages/email-templates.php?key=<?php echo e($key); ?>"
               style="display: block; padding: 16px 18px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; text-decoration: none; color: inherit;">
                <div style="display: flex; justify-content: space-between; align-items: start; gap: 8px;">
                    <h3 style="font-size: 15px; font-weight: 600; color: #1a202c; margin: 0;">
                        <?php echo e($def['name']); ?>
                    </h3>
                    <span style="font-size: 12px; color: #667eea; font-weight: 500; flex-shrink: 0;">Edit →</span>
                </div>
                <code style="display: inline-block; background: #edf2f7; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #4a5568; margin-top: 6px;"><?php echo e($key); ?></code>
                <p style="font-size: 13px; color: #4a5568; margin: 8px 0 0; line-height: 1.5;">
                    <?php echo e($def['description']); ?>
                </p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                    <span style="font-size: 11px; color: #718096;">
                        <?php echo count($def['variables']); ?> variable(s)
                    </span>
                    <?php if ($updatedAt): ?>
                        <span style="font-size: 11px; color: #718096;">·</span>
                        <span style="font-size: 11px; color: #718096;">Updated <?php echo e($updatedAt); ?></span>
                    <?php else: ?>
                        <span style="font-size: 11px; color: #718096;">·</span>
                        <span style="font-size: 11px; color: #48bb78;">Using defaults</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
