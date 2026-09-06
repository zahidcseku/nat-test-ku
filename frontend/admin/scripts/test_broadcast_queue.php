<?php
/**
 * Standalone CLI test for the broadcast email queue (DB-backed resume).
 *
 * Intake-test style: prints PASS/FAIL lines, exit 0/1. No framework.
 *
 * Runs against a THROWAWAY shadow database (test_bcast_queue) so it can
 * create/drop tables freely — the nattest_reg user only has DDL rights on
 * test_* databases locally. It applies the REAL schema/broadcast_jobs.sql
 * and drives the exact SQL sequences api/broadcast-email/send.php uses.
 *
 * Usage:
 *   php frontend/admin/scripts/test_broadcast_queue.php
 *
 * Requires a local MariaDB/MySQL with user nattest_reg (env DB_* to
 * override). Prints SKIP and exits 0 when no database is reachable.
 */

$failures = 0;
$checks = 0;

function check(bool $cond, string $label): void {
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "PASS: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'nattest_reg';
$dbPass = getenv('DB_PASS') ?: 'localtest123';
$shadow = 'test_bcast_queue';

$admin = @new mysqli($dbHost, $dbUser, $dbPass);
if ($admin->connect_error) {
    echo "SKIP: no local database ({$admin->connect_error})\n";
    exit(0);
}

// --- Fresh shadow DB with the parent tables our FKs reference ---
$admin->query("DROP DATABASE IF EXISTS `{$shadow}`");
if (!$admin->query("CREATE DATABASE `{$shadow}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    echo "SKIP: cannot create {$shadow} ({$admin->error})\n";
    exit(0);
}
$admin->select_db($shadow);
$admin->query(
    'CREATE TABLE admin_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL)'
);
$admin->query(
    'CREATE TABLE exam_dates (id CHAR(36) PRIMARY KEY, exam_date DATE NOT NULL,
      registration_deadline DATE NOT NULL)'
);
$admin->query(
    "CREATE TABLE registrations (
        id VARCHAR(36) PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        test_date DATE NOT NULL,
        approved TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
     )"
);

// --- Apply the real migration ---
$migration = file_get_contents(__DIR__ . '/../schema/broadcast_jobs.sql');
// Strip the trailing sanity SELECT (multi-result is fine but keep output clean).
$migration = preg_replace('/-- Sanity check[\s\S]+$/', '', $migration);
check($admin->multi_query($migration) === true, 'broadcast_jobs.sql applies cleanly');
while ($admin->more_results()) {
    $admin->next_result();
}
check($admin->select_db($shadow), 'database reselected after migration');

require_once __DIR__ . '/../lib/broadcast-email.php';

// --- Seed: one admin, one exam date, five registrations where two rows
// share an email AND created_at (the latent tie the snapshot must dedupe).
$conn = $admin;
$conn->query("INSERT INTO admin_users (username) VALUES ('tester')");
$adminId = (int) $conn->insert_id;
$examDateId = '11111111-2222-3333-4444-555555555555';
$dateStmt = $conn->prepare('INSERT INTO exam_dates (id, exam_date, registration_deadline) VALUES (?, ?, ?)');
$ed = '2026-10-15';
$rd = '2026-09-30';
$dateStmt->bind_param('sss', $examDateId, $ed, $rd);
$dateStmt->execute();
$dateStmt->close();

$regStmt = $conn->prepare(
    'INSERT INTO registrations (id, full_name, email, test_date, approved, created_at)
     VALUES (?, ?, ?, ?, 1, ?)'
);
$people = [
    ['aaaaaaaa-0000-0000-0000-000000000001', 'Alpha One',   'alpha@example.com', '2026-01-01 10:00:00'],
    ['aaaaaaaa-0000-0000-0000-000000000002', 'Bravo Two',   'bravo@example.com', '2026-01-01 11:00:00'],
    ['aaaaaaaa-0000-0000-0000-000000000003', 'Charlie Tri', 'charlie@example.com', '2026-01-01 12:00:00'],
    ['aaaaaaaa-0000-0000-0000-000000000004', 'Delta Dup-1', 'dup@example.com',  '2026-01-01 13:00:00'],
    ['aaaaaaaa-0000-0000-0000-000000000005', 'Delta Dup-2', 'dup@example.com',  '2026-01-01 13:00:00'],
];
foreach ($people as $p) {
    $regStmt->bind_param('sssss', $p[0], $p[1], $p[2], $ed, $p[3]);
    $regStmt->execute();
}
$regStmt->close();
// An unapproved row that must NOT be selected
$regStmt = $conn->prepare(
    "INSERT INTO registrations (id, full_name, email, test_date, approved)
     VALUES ('aaaaaaaa-0000-0000-0000-000000000099', 'Echo Pending', 'echo@example.com', ?, 0)"
);
$regStmt->bind_param('s', $ed);
$regStmt->execute();
$regStmt->close();

// --- fetchBroadcastRecipients: dedup + approved-only ---
$recipients = fetchBroadcastRecipients($conn, $ed);
check(count($recipients) === 4, 'fetchBroadcastRecipients returns 4 unique approved emails (tie deduped), got ' . count($recipients));

// --- Create a job + snapshot exactly as send.php does ---
$conn->query("INSERT INTO broadcasts (exam_date_id, exam_date, subject, body, created_by)
              VALUES ('{$examDateId}', '{$ed}', 'Test subject', 'Hello {full_name}', {$adminId})");
$broadcastId = (int) $conn->insert_id;

$snapped = 0;
foreach (array_chunk($recipients, 100) as $chunk) {
    $values = [];
    $params = [];
    $types  = '';
    foreach ($chunk as $r) {
        $values[] = '(?, ?, ?, ?)';
        $params[] = $broadcastId;
        $params[] = $r['id'];
        $params[] = $r['email'];
        $params[] = $r['full_name'];
        $types   .= 'isss';
    }
    $snap = $conn->prepare(
        'INSERT IGNORE INTO broadcast_recipients (broadcast_id, registration_id, email, full_name)
         VALUES ' . implode(', ', $values)
    );
    $snap->bind_param($types, ...$params);
    $snap->execute();
    $snapped += $snap->affected_rows;
    $snap->close();
}
check($snapped === 4, 'snapshot inserts 4 recipients, got ' . $snapped);

// --- findResumableBroadcast finds the unfinished job ---
$found = findResumableBroadcast($conn, $examDateId, 'Test subject', 'Hello {full_name}');
check($found === $broadcastId, 'findResumableBroadcast returns the unfinished job');
check(findResumableBroadcast($conn, $examDateId, 'Other subject', 'x') === null,
    'findResumableBroadcast ignores different subject');

// --- Atomic claim: first take wins, second gets affected_rows 0 ---
$row = $conn->query("SELECT id FROM broadcast_recipients WHERE broadcast_id = {$broadcastId} ORDER BY id ASC LIMIT 1")->fetch_assoc();
$takeStmt = $conn->prepare(
    "UPDATE broadcast_recipients SET status = 'sending', attempts = attempts + 1
     WHERE id = ? AND status IN ('pending','failed')"
);
$takeStmt->bind_param('i', $row['id']);
$takeStmt->execute();
check($takeStmt->affected_rows === 1, 'first claim wins (affected_rows 1)');
$takeStmt->execute();
check($takeStmt->affected_rows === 0, 'second claim of same row loses (affected_rows 0)');
$takeStmt->close();

// --- Mark outcomes + recount (the exact statements from send.php) ---
$markSent = $conn->prepare("UPDATE broadcast_recipients SET status = 'sent', sent_at = NOW() WHERE id = ?");
$markSent->bind_param('i', $row['id']);
$markSent->execute();
$markSent->close();

$counts = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'sending' => 0];
$countStmt = $conn->prepare('SELECT status, COUNT(*) AS cnt FROM broadcast_recipients WHERE broadcast_id = ? GROUP BY status');
$countStmt->bind_param('i', $broadcastId);
$countStmt->execute();
foreach ($countStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $counts[$r['status']] = (int) $r['cnt'];
}
$countStmt->close();
check($counts['sent'] === 1 && $counts['pending'] === 3, 'recount sees 1 sent / 3 pending');

// --- getRecentBroadcasts ---
$recent = getRecentBroadcasts($conn);
check(count($recent) === 1
    && (int) $recent[0]['sent_count'] === 1
    && (int) $recent[0]['pending_count'] === 3
    && (int) $recent[0]['total_count'] === 4,
    'getRecentBroadcasts counts 1 sent / 3 pending / 4 total');

// --- Fail one, then getBroadcastFailures ---
$failRow = $conn->query("SELECT id FROM broadcast_recipients WHERE broadcast_id = {$broadcastId} AND status = 'pending' ORDER BY id ASC LIMIT 1")->fetch_assoc();
$conn->query("UPDATE broadcast_recipients SET status = 'failed', attempts = 1, last_error = 'SMTP unexpected response: 421 busy' WHERE id = {$failRow['id']}");
$fdata = getBroadcastFailures($conn, $broadcastId);
check($fdata['job'] !== null && count($fdata['failed']) === 1 && str_contains($fdata['failed'][0]['last_error'], '421'),
    'getBroadcastFailures returns the failed row with its error');

// --- Finish the job: findResumableBroadcast -> null; identical-finished found ---
$conn->query("UPDATE broadcasts SET finished_at = NOW() WHERE id = {$broadcastId}");
check(findResumableBroadcast($conn, $examDateId, 'Test subject', 'Hello {full_name}') === null,
    'finished job is no longer resumable');
$finished = findFinishedIdenticalBroadcast($conn, $examDateId, 'Test subject', 'Hello {full_name}');
check($finished !== null && (int) $finished['sent_count'] === 1,
    'findFinishedIdenticalBroadcast returns the finished job with sent count');

// --- Resume sweep: stranded sending rows become failed (recoverable) ---
$conn->query("UPDATE broadcast_recipients SET status = 'sending' WHERE broadcast_id = {$broadcastId} AND status = 'pending' LIMIT 1");
$sweep = $conn->prepare(
    "UPDATE broadcast_recipients
     SET status = 'failed', last_error = 'Interrupted mid-send (recovered by resume)'
     WHERE broadcast_id = ? AND status = 'sending'"
);
$sweep->bind_param('i', $broadcastId);
$sweep->execute();
$sweep->close();
$stillSending = (int) $conn->query("SELECT COUNT(*) c FROM broadcast_recipients WHERE broadcast_id = {$broadcastId} AND status = 'sending'")->fetch_assoc()['c'];
check($stillSending === 0, 'resume sweep converts stranded sending rows to failed');

echo "\n{$checks} checks, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
