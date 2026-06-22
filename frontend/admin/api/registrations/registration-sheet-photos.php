<?php
/**
 * Registration Sheet Photos ZIP Download
 *
 * Streams a .zip of every applicant's photo for the selected year+month,
 * with each file renamed to {reg_no}.{ext}.
 *
 * Source of truth: registration_sheet_numbers (the same mapping used by the
 * xlsx export). One photo per registration; for multi-level applicants, the
 * lowest reg_no wins as the filename (so 1Q < 2Q < 3Q ...).
 *
 * Safety: only photos whose realpath is inside /intake/uploads/ are
 * included. Path traversal via a tampered row is refused, never followed.
 *
 * Requires the mapping table — export the .xlsx at least once first.
 */

require_once __DIR__ . '/../../auth/middleware.php';

$year  = isset($_GET['year'])  ? (int) $_GET['year']  : 0;
$month = isset($_GET['month']) ? (int) $_GET['month'] : 0;

if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo 'Invalid year or month.';
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'Server lacks ZipArchive extension.';
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

// One row per registration. primary_reg_no = lowest reg_no across levels.
$stmt = $conn->prepare("
    SELECT rsn.registration_id,
           MIN(rsn.reg_no) AS primary_reg_no,
           r.full_name,
           r.photo_storage_path,
           r.photo_filename
    FROM registration_sheet_numbers rsn
    JOIN registrations r ON r.id = rsn.registration_id
    WHERE rsn.year = ? AND rsn.month = ?
    GROUP BY rsn.registration_id, r.full_name, r.photo_storage_path, r.photo_filename
    ORDER BY primary_reg_no ASC
");
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    http_response_code(404);
    echo 'No registration numbers have been assigned for this period yet. ';
    echo 'Export the .xlsx first to assign reg numbers.';
    exit;
}

$tmpPath = tempnam(sys_get_temp_dir(), 'regphotos_');
if ($tmpPath === false) {
    http_response_code(500);
    echo 'Could not create temp file.';
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpPath);
    http_response_code(500);
    echo 'Could not create ZIP.';
    exit;
}

// Path-traversal guard: same marker used by deleteRegistrationCompletely.
$uploadsMarker = DIRECTORY_SEPARATOR . 'intake' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;

$added           = 0;
$skippedMissing  = 0;
$skippedUnsafe   = 0;
$skippedCollision = 0;
$seenNames       = [];
$manifest        = [];

foreach ($rows as $row) {
    $regNo = $row['primary_reg_no'];
    $path  = $row['photo_storage_path'];

    if ($path === '' || $path === null) {
        $skippedMissing++;
        continue;
    }

    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        $skippedMissing++;
        error_log("regphotos: missing file for $regNo at $path");
        continue;
    }

    if (strpos($real, $uploadsMarker) === false) {
        $skippedUnsafe++;
        error_log("regphotos: refused path outside uploads for $regNo: $real");
        continue;
    }

    // Preserve original extension (fall back to .jpg).
    $ext = strtolower(pathinfo($row['photo_filename'] ?: $path, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'jpg';
    }
    $zipName = $regNo . '.' . $ext;

    // Defensive: avoid name collisions (reg_no is UNIQUE per period, but
    // case-insensitive filesystems could merge e.g. 47610001.JPG and .jpg).
    if (isset($seenNames[strtolower($zipName)])) {
        $suffix = substr(md5($real), 0, 6);
        $zipName = $regNo . '_' . $suffix . '.' . $ext;
        if (isset($seenNames[strtolower($zipName)])) {
            $skippedCollision++;
            continue;
        }
    }
    $seenNames[strtolower($zipName)] = true;

    if (!$zip->addFile($real, $zipName)) {
        $skippedMissing++;
        continue;
    }

    $manifest[] = [
        'reg_no' => $regNo,
        'file'   => $zipName,
        'name'   => $row['full_name'],
    ];
    $added++;
}

// Embed a small manifest so recipients can map reg_no -> name without opening the DB.
if (!empty($manifest)) {
    $csv = "reg_no,filename,full_name\n";
    foreach ($manifest as $m) {
        $name = str_replace(['"', ','], [' ', ' '], $m['name']);
        $csv .= $m['reg_no'] . ',' . $m['file'] . ',' . $name . "\n";
    }
    $zip->addFromString('_manifest.csv', $csv);

    $summary = sprintf(
        "Registration photos for %d-%02d\nGenerated: %s\n\nTotal: %d\nMissing: %d\nUnsafe: %d\nCollisions: %d\n",
        $year, $month, date('Y-m-d H:i:s'), $added, $skippedMissing, $skippedUnsafe, $skippedCollision
    );
    $zip->addFromString('_README.txt', $summary);
}

$zip->close();

if ($added === 0) {
    @unlink($tmpPath);
    http_response_code(404);
    echo 'No readable photos found for the selected period. ';
    echo 'Missing: ' . $skippedMissing . ', unsafe: ' . $skippedUnsafe . '.';
    exit;
}

$filename = sprintf('Registration_Photos_%d-%02d.zip', $year, $month);
$filesize = filesize($tmpPath);

try {
    logAudit(
        'export_registration_photos',
        'registrations',
        null,
        null,
        [
            'year'       => $year,
            'month'      => $month,
            'added'      => $added,
            'missing'    => $skippedMissing,
            'unsafe'     => $skippedUnsafe,
            'collisions' => $skippedCollision,
        ]
    );
} catch (Throwable $e) {
    error_log('Audit log failed: ' . $e->getMessage());
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$fp = fopen($tmpPath, 'rb');
while (!feof($fp)) {
    echo fread($fp, 64 * 1024);
}
fclose($fp);

@unlink($tmpPath);
exit;
