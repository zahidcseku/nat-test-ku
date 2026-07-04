<?php
/**
 * Registration Sheet Photos ZIP Download
 *
 * Streams a .zip of applicant photos for the selected year+month, but ONLY
 * for mappings whose photo has not been included in a previous Download
 * Photos zip (photos_downloaded_at IS NULL). After a successful build,
 * those mappings are marked photos_downloaded_at = NOW() so the next
 * download only picks up newly-added applicants.
 *
 * Folder structure inside the zip mirrors the xlsx level sheets:
 *
 *     1Q/47610001.jpg
 *     1Q/47610002.jpg
 *     2Q/47620001.jpg
 *     3Q/47630005.png
 *     _manifest.csv
 *     _README.txt
 *
 * A multi-level applicant (1Q/N1 + 2Q/N2) appears in BOTH folders, once
 * per reg_no - matches how they appear in the xlsx level sheets.
 *
 * Safety: only photos whose realpath is inside /intake/uploads/ are
 * included. Path traversal via a tampered row is refused.
 *
 * Requires registration_sheet_numbers.photos_downloaded_at - see
 * schema/add_photos_downloaded_at.sql.
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

// Soft-check for the photos_downloaded_at column. If it is missing, every
// download would return the full set every time, so fail loudly with the
// migration hint instead.
$colCheck = $conn->query("SHOW COLUMNS FROM registration_sheet_numbers LIKE 'photos_downloaded_at'");
if ($colCheck === false || $colCheck->num_rows === 0) {
    http_response_code(500);
    echo "Required column 'photos_downloaded_at' is missing on registration_sheet_numbers. ";
    echo "Run: mysql -u nattest_reg -p nattest_regs < admin/schema/add_photos_downloaded_at.sql";
    exit;
}

// One row per (registration_id, level) - multi-level applicants appear
// multiple times. Filter to mappings whose photo has not yet been sent.
$stmt = $conn->prepare("
    SELECT rsn.id AS mapping_id,
           rsn.registration_id,
           rsn.level,
           rsn.reg_no,
           r.full_name,
           r.photo_storage_path,
           r.photo_filename
    FROM registration_sheet_numbers rsn
    JOIN registrations r ON r.id = rsn.registration_id
    WHERE rsn.year = ?
      AND rsn.month = ?
      AND rsn.photos_downloaded_at IS NULL
    ORDER BY rsn.reg_no ASC
");
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    http_response_code(404);
    echo 'No new photos to download for ' . sprintf('%04d-%02d', $year, $month) . '. ';
    echo 'Either every mapped applicant has already been downloaded, or the xlsx has not been exported yet.';
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

// Path-traversal guard. Same marker used by deleteRegistrationCompletely().
$uploadsMarker = DIRECTORY_SEPARATOR . 'intake' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;

$added           = 0;
$skippedMissing  = 0;
$skippedUnsafe   = 0;
$skippedCollision = 0;
$seenPaths       = []; // tracks full in-zip paths to avoid collisions
$markedMappingIds = []; // mapping.id values to mark as downloaded
$manifest        = [];

// Tally per level for the README.
$perLevel = ['1Q' => 0, '2Q' => 0, '3Q' => 0, '4Q' => 0, '5Q' => 0];

foreach ($rows as $row) {
    $regNo = $row['reg_no'];
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

    // Folder = level with the /N{digit} suffix stripped: "1Q/N1" -> "1Q".
    $levelFolder = preg_replace('/\/N\d+$/', '', $row['level']);

    // Preserve original extension (fall back to .jpg).
    $ext = strtolower(pathinfo($row['photo_filename'] ?: $path, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'jpg';
    }
    $inZipPath = $levelFolder . '/' . $regNo . '.' . $ext;

    // Defensive: avoid name collisions on case-insensitive filesystems.
    $key = strtolower($inZipPath);
    if (isset($seenPaths[$key])) {
        $suffix  = substr(md5($real), 0, 6);
        $inZipPath = $levelFolder . '/' . $regNo . '_' . $suffix . '.' . $ext;
        $key = strtolower($inZipPath);
        if (isset($seenPaths[$key])) {
            $skippedCollision++;
            continue;
        }
    }
    $seenPaths[$key] = true;

    if (!$zip->addFile($real, $inZipPath)) {
        $skippedMissing++;
        continue;
    }

    if (isset($perLevel[$levelFolder])) {
        $perLevel[$levelFolder]++;
    }

    $manifest[] = [
        'reg_no'  => $regNo,
        'level'   => $levelFolder,
        'file'    => $inZipPath,
        'name'    => $row['full_name'],
    ];
    $markedMappingIds[] = (int) $row['mapping_id'];
    $added++;
}

if ($added === 0) {
    $zip->close();
    @unlink($tmpPath);
    http_response_code(404);
    echo 'No readable photos found for the selected period. ';
    echo 'Missing: ' . $skippedMissing . ', unsafe: ' . $skippedUnsafe . '.';
    exit;
}

// Embed manifest + README so recipients can map reg_no -> name without the DB.
if (!empty($manifest)) {
    $csv = "reg_no,level,filename,full_name\n";
    foreach ($manifest as $m) {
        $name = str_replace(['"', ','], [' ', ' '], $m['name']);
        $csv .= $m['reg_no'] . ',' . $m['level'] . ',' . $m['file'] . ',' . $name . "\n";
    }
    $zip->addFromString('_manifest.csv', $csv);

    $summary = sprintf(
        "Registration photos for %04d-%02d (NEW applicants only)\nGenerated: %s\n\nTotal: %d\nPer level: 1Q=%d  2Q=%d  3Q=%d  4Q=%d  5Q=%d\nSkipped: missing=%d  unsafe=%d  collisions=%d\n",
        $year, $month, date('Y-m-d H:i:s'),
        $added,
        $perLevel['1Q'], $perLevel['2Q'], $perLevel['3Q'], $perLevel['4Q'], $perLevel['5Q'],
        $skippedMissing, $skippedUnsafe, $skippedCollision
    );
    $zip->addFromString('_README.txt', $summary);
}

$zip->close();

// Mark the included mappings as downloaded so the next call only returns
// newly-added applicants. Done AFTER the zip is built; if anything above
// exits early (e.g. no readable photos), nothing is marked.
if (!empty($markedMappingIds)) {
    $placeholders = implode(',', array_fill(0, count($markedMappingIds), '?'));
    $types = str_repeat('i', count($markedMappingIds));
    $mark = $conn->prepare("
        UPDATE registration_sheet_numbers
        SET photos_downloaded_at = NOW()
        WHERE id IN ($placeholders)
    ");
    $mark->bind_param($types, ...$markedMappingIds);
    $mark->execute();
    $mark->close();
}

$filename = sprintf('Registration_Photos_%d-%02d_new.zip', $year, $month);
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
            'marked'     => count($markedMappingIds),
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
