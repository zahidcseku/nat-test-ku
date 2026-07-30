<?php
/**
 * Broadcast email — shared helpers.
 *
 * Used by:
 *   - /admin/pages/broadcast-email.php   (preview recipient list)
 *   - /admin/api/broadcast-email/send.php (actual send)
 *
 * Kept in one place because the recipient-selection query is security-
 * relevant: the page (preview) and the handler (send) MUST select the
 * exact same set, otherwise the preview lies about who will receive
 * the message.
 */

/**
 * De-duped approved recipients for a given exam DATE. Returns one row per
 * unique email address; when an address appears on several registrations,
 * the earliest created_at row wins so {full_name} substitution is
 * deterministic. NULL/empty emails are excluded.
 *
 * @return list<array{id:string, full_name:string, email:string, test_date:string}>
 */
function fetchBroadcastRecipients(mysqli $conn, string $examDate): array {
    if (!$conn || $examDate === '') {
        return [];
    }
    $stmt = $conn->prepare("
        SELECT r.id, r.full_name, r.email, r.test_date
        FROM registrations r
        INNER JOIN (
            SELECT MIN(created_at) AS min_created, email
            FROM registrations
            WHERE approved = 1
              AND test_date = ?
              AND email IS NOT NULL
              AND email <> ''
            GROUP BY email
        ) d ON d.email = r.email AND d.min_created = r.created_at
        ORDER BY r.full_name ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $examDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
