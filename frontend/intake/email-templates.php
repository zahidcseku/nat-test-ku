<?php
/**
 * NAT-TEST Intake Service - Email Template Loader
 *
 * Thin DB-only loader for email templates. Does NOT import anything from
 * /admin (the intake service's hard constraint). If the email_templates
 * table is missing or the requested template_key row is absent, this
 * returns null and the caller (mailer.php) falls back to the legacy
 * buildRegistrationEmail() path — so behavior is unchanged before the
 * admin seeds the templates and unchanged if a row is deleted.
 *
 * This file MUST be included after /intake/config.php (for getDbConnection).
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

/**
 * Render an email template from the DB.
 *
 * @param string $key   Template key (e.g., 'submission_receipt_online').
 * @param array  $vars  Map of {placeholder-name} => value. Values are
 *                      HTML-escaped before substitution except for the
 *                      raw-HTML block variables (info_table, banner_paid,
 *                      payment_options_*, etc.) which the caller builds.
 *
 * @return array|null  ['subject' => ..., 'body' => ...] or null if the
 *                     template row is missing (caller should fall back
 *                     to the legacy hardcoded builder).
 */
function renderIntakeEmailTemplate(string $key, array $vars = []): ?array {
    $conn = getDbConnection();
    if (!$conn) return null;

    $stmt = $conn->prepare("
        SELECT subject, body_html
        FROM email_templates
        WHERE template_key = ?
        LIMIT 1
    ");
    if (!$stmt) return null; // table missing

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return null;

    return [
        'subject' => _intakeSubstituteVars($row['subject'], $vars),
        'body'    => _intakeSubstituteVars($row['body_html'], $vars),
    ];
}

/**
 * Substitute {var} => value into $text. Unknown placeholders are left
 * intact so typos are visible. Raw-HTML block variables (passed by the
 * caller as pre-built HTML) are NOT escaped; everything else is.
 */
function _intakeSubstituteVars(string $text, array $vars): string {
    if (empty($vars)) return $text;

    $rawHtmlVars = [
        'info_table'              => true,
        'next_steps'              => true,
        'qr_section'              => true,
        'payment_options_online'  => true,
        'payment_options_offline' => true,
        'banner_paid'             => true,
        'banner_pending'          => true,
    ];

    foreach ($vars as $k => $v) {
        if ($v === null) $v = '';
        $value = isset($rawHtmlVars[$k]) ? (string) $v : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $text = str_replace('{' . $k . '}', $value, $text);
    }
    return $text;
}
