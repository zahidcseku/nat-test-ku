<?php
/**
 * Authenticated PDF download for admission tickets, score reports and
 * exam guides.
 *
 * admin/uploads/ is denied over HTTP entirely (see uploads/.htaccess) —
 * those PDFs carry applicant PII and were previously enumerable at
 * /admin/uploads/{tickets|scores}/<exam_date>/<xlsx_id>.pdf because
 * xlsx_id is a structured sequential examinee ID. This endpoint is the
 * only way to view them and requires an admin session.
 */
require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    exit('Method not allowed');
}

$file = $_GET['file'] ?? '';

// Strict shape whitelist (the only layouts written by ticket-staging.php,
// score-staging.php and api/admission-tickets/guide.php):
//   tickets/<exam_date>/<xlsx_id>.pdf
//   scores/<exam_date>/<xlsx_id>.pdf
//   guides/<exam_date_id>.pdf
// The allowed charset excludes '.', '/' and '\', so traversal cannot be
// expressed; the realpath confinement below is defense in depth.
$shapeOk = preg_match('#^(?:tickets|scores)/[A-Za-z0-9-]+/[A-Za-z0-9_-]+\.pdf$#', $file)
        || preg_match('#^guides/[A-Za-z0-9_-]+\.pdf$#', $file);
if (!$shapeOk) {
    http_response_code(400);
    exit('Invalid file');
}

$base = realpath(UPLOAD_PATH);
$full = realpath(UPLOAD_PATH . '/' . $file);
if ($base === false || $full === false
    || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0
    || !is_file($full) || !is_readable($full)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($full));
header('Content-Disposition: inline; filename="' . basename($full) . '"');
header('X-Content-Type-Options: nosniff');
readfile($full);
