<?php
/**
 * Render an email template by key, falling back to PHP defaults if a DB
 * row is missing. Used by every admin-side email call site.
 *
 * Placeholder convention: {variable_name} with single braces.
 * Values are HTML-escaped before substitution.
 *
 * Used by:
 *   - pages/registration-detail.php  (confirmation, rejection)
 *   - lib/ticket-staging.php         (admission_ticket)
 *   - lib/score-staging.php          (score_report)
 *   - api/payments/retry-email.php   (payment_retry)
 *   - pages/email-templates.php      (live preview, auto-seed)
 *   - api/email-templates/reset.php  (restore defaults)
 */

require_once __DIR__ . '/email-template-defaults.php';

/**
 * Render a template: look up DB row, fall back to defaults, substitute vars.
 *
 * @param string $key   Template key (e.g., 'confirmation').
 * @param array  $vars  Map of {placeholder-name} => value. Values are
 *                      HTML-escaped before substitution. Placeholders
 *                      not present in $vars are left as-is so the admin
 *                      sees the typo in any preview/log.
 *
 * @return array{subject: string, body: string}
 */
function renderEmailTemplate(string $key, array $vars = []): array {
    $row = _loadEmailTemplateRow($key);
    if (!$row) {
        $defaults = emailTemplateDefaults();
        $def = $defaults[$key] ?? null;
        if (!$def) {
            // Unknown key — return empty so caller can detect and bail.
            return ['subject' => '', 'body' => ''];
        }
        $subject = $def['subject'];
        $body    = $def['body'];
    } else {
        $subject = $row['subject'];
        $body    = $row['body_html'];
    }

    return [
        'subject' => _substituteTemplateVars($subject, $vars),
        'body'    => _substituteTemplateVars($body, $vars),
    ];
}

/**
 * Load a raw template row from the DB (no substitution). Returns null if
 * the table doesn't exist or the row is missing. Used by the editor.
 *
 * @return array|null
 */
function loadEmailTemplateRow(string $key): ?array {
    return _loadEmailTemplateRow($key);
}

/**
 * Seed any missing template_key rows from the defaults. Called by the
 * email-templates admin page on every load so the DB converges on the
 * full set of known templates without requiring a manual seed step.
 * Existing rows are never overwritten — only missing keys are inserted.
 *
 * @return int Number of rows inserted.
 */
function seedMissingEmailTemplates(): int {
    $conn = getDbConnection();
    if (!$conn) return 0;

    $defaults = emailTemplateDefaults();
    if (empty($defaults)) return 0;

    // Find which keys already exist in the DB.
    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = str_repeat('s', count($keys));
    $existing = [];
    $stmt = $conn->prepare("SELECT template_key FROM email_templates WHERE template_key IN ($placeholders)");
    if (!$stmt) return 0;
    $stmt->bind_param($types, ...$keys);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing[$row['template_key']] = true;
    }
    $stmt->close();

    $inserted = 0;
    $insert = $conn->prepare("
        INSERT INTO email_templates
            (template_key, name, description, subject, body_html, available_variables, is_system)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");
    if (!$insert) return 0;

    foreach ($defaults as $key => $def) {
        if (isset($existing[$key])) continue;
        $varsJson = json_encode($def['variables'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $insert->bind_param(
            'ssssss',
            $key,
            $def['name'],
            $def['description'],
            $def['subject'],
            $def['body'],
            $varsJson
        );
        if ($insert->execute()) {
            $inserted++;
        }
    }
    $insert->close();

    return $inserted;
}

// ----------------------------------------------------------------------
// Internals
// ----------------------------------------------------------------------

/**
 * Load one row. Tolerates missing table (returns null) so the system
 * keeps working before the schema is applied.
 */
function _loadEmailTemplateRow(string $key): ?array {
    $conn = getDbConnection();
    if (!$conn) return null;

    $stmt = $conn->prepare("
        SELECT template_key, name, description, subject, body_html,
               available_variables, updated_by, updated_at, created_at
        FROM email_templates
        WHERE template_key = ?
    ");
    if (!$stmt) return null; // table missing or similar
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Substitute every {var} => value into $text. Unknown placeholders are
 * left intact (visible in the rendered email so typos are obvious).
 *
 * Values that are NOT pre-escaped HTML are escaped here. Variables that
 * hold pre-built HTML blocks (e.g., {info_table}, {banner_paid}) are
 * intentionally pre-escaped by the caller — they pass through a safe-list
 * so we don't double-escape them.
 */
function _substituteTemplateVars(string $text, array $vars): string {
    if (empty($vars)) return $text;

    // Variables whose values are intentionally raw HTML (pre-built by
    // the caller). Everything else gets htmlspecialchars.
    $rawHtmlVars = [
        'info_table'              => true,
        'next_steps'              => true,
        'qr_section'              => true,
        'payment_options_online'  => true,
        'payment_options_offline' => true,
        'banner_paid'             => true,
        'banner_pending'          => true,
        'guide_line'              => true,
        'rejection_reasons'       => true,
        'tracking_block'          => true,
    ];

    foreach ($vars as $k => $v) {
        if ($v === null) $v = '';
        $value = isset($rawHtmlVars[$k]) ? (string) $v : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $text = str_replace('{' . $k . '}', $value, $text);
    }
    return $text;
}
