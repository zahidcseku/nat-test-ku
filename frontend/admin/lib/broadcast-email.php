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
 * deterministic (ties on created_at — e.g. a double submission in the same
 * second — are broken by the smallest id). NULL/empty emails are excluded.
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
            -- One winning registration id per email: earliest created_at,
            -- smallest id as the tie-break.
            SELECT MIN(k.id) AS keep_id
            FROM registrations k
            INNER JOIN (
                SELECT email, MIN(created_at) AS min_created
                FROM registrations
                WHERE approved = 1
                  AND test_date = ?
                  AND email IS NOT NULL
                  AND email <> ''
                GROUP BY email
            ) m ON m.email = k.email AND m.min_created = k.created_at
            WHERE k.approved = 1
              AND k.test_date = ?
            GROUP BY k.email
        ) d ON d.keep_id = r.id
        ORDER BY r.full_name ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ss', $examDate, $examDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Id of the most recent UNFINISHED broadcast with the same exam date,
 * subject, and body — confirming the same message again should resume
 * that job instead of creating a duplicate that re-emails people.
 *
 * Comparison is utf8mb4_unicode_ci (case-insensitive), which is the
 * desired behaviour here: a re-confirmed draft differing only in case
 * is the same announcement.
 *
 * @return int|null Broadcast id, or null when no unfinished match exists.
 */
function findResumableBroadcast(mysqli $conn, string $examDateId, string $subject, string $body): ?int {
    $stmt = $conn->prepare("
        SELECT id FROM broadcasts
        WHERE exam_date_id = ? AND subject = ? AND body = ?
          AND finished_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('sss', $examDateId, $subject, $body);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['id'] : null;
}

/**
 * The most recent FINISHED broadcast identical to the draft being
 * previewed — powers the "already fully sent on <date>" warning.
 * Warning only; the admin may still choose to send again.
 *
 * @return array{id:int, exam_date:string, finished_at:string, sent_count:int}|null
 */
function findFinishedIdenticalBroadcast(mysqli $conn, string $examDateId, string $subject, string $body): ?array {
    $stmt = $conn->prepare("
        SELECT b.id, b.exam_date, b.finished_at,
               COALESCE(SUM(r.status = 'sent'), 0) AS sent_count
        FROM broadcasts b
        LEFT JOIN broadcast_recipients r ON r.broadcast_id = b.id
        WHERE b.exam_date_id = ? AND b.subject = ? AND b.body = ?
          AND b.finished_at IS NOT NULL
        GROUP BY b.id, b.exam_date, b.finished_at
        ORDER BY b.id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('sss', $examDateId, $subject, $body);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Recent broadcast jobs with per-status recipient counts for the
 * "Recent broadcasts" table (Resume button lives there).
 *
 * @return list<array{id:int, subject:string, exam_date:string, created_at:string,
 *                    finished_at:?string, sent_count:int, failed_count:int,
 *                    pending_count:int, total_count:int}>
 */
function getRecentBroadcasts(mysqli $conn, int $limit = 10): array {
    $stmt = $conn->prepare("
        SELECT b.id, b.subject, b.exam_date, b.created_at, b.finished_at,
               COALESCE(SUM(r.status = 'sent'), 0)                     AS sent_count,
               COALESCE(SUM(r.status = 'failed'), 0)                   AS failed_count,
               COALESCE(SUM(r.status IN ('pending','sending')), 0)     AS pending_count,
               COUNT(r.id)                                             AS total_count
        FROM broadcasts b
        LEFT JOIN broadcast_recipients r ON r.broadcast_id = b.id
        GROUP BY b.id
        ORDER BY b.created_at DESC
        LIMIT ?
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * A broadcast job plus its failed recipients, for the failed-deliveries
 * panel. `sending` rows left over from an interrupted run are included
 * as failed — they never completed.
 *
 * @return array{job:?array, failed:list<array>}
 */
function getBroadcastFailures(mysqli $conn, int $broadcastId): array {
    $job = null;
    $failed = [];

    $jobStmt = $conn->prepare("
        SELECT id, subject, exam_date, created_at, finished_at
        FROM broadcasts WHERE id = ?
    ");
    if ($jobStmt) {
        $jobStmt->bind_param('i', $broadcastId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        $jobStmt->close();
    }
    if ($job === null) {
        return ['job' => null, 'failed' => []];
    }

    $failStmt = $conn->prepare("
        SELECT full_name, email, attempts, last_error
        FROM broadcast_recipients
        WHERE broadcast_id = ? AND status IN ('failed','sending')
        ORDER BY attempts DESC, email ASC
    ");
    if ($failStmt) {
        $failStmt->bind_param('i', $broadcastId);
        $failStmt->execute();
        $failed = $failStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $failStmt->close();
    }

    return ['job' => $job, 'failed' => $failed];
}
