<?php
/**
 * Registration Sheet Export (.xlsx)
 *
 * Streams an .xlsx built by copying the template (path from
 * REGISTRATION_SHEET_TEMPLATE env) and filling:
 *   - Registration sheet: K1 = year, K2 = month
 *   - 1Q..5Q sheets:      column C (Name) and column D (DOB as YYYY/MM/DD)
 *
 * Reg-no assignment rules:
 *   1. New approved applicants for the period are randomly shuffled and
 *      assigned to the lowest free row in their level sheet.
 *   2. The assignment is persisted in `registration_sheet_numbers`.
 *   3. On re-export, every existing assignment is reused unchanged; only
 *      first-time applicants for the period get fresh rows (shuffled
 *      among themselves).
 *
 * The Reg. No shown in the workbook is computed by the template's own
 * formula in column B (site_code & level_digit & sheet_row). We mirror
 * that formula in PHP so we can store the same value in the DB.
 *
 * Requires the `registration_sheet_numbers` table — see
 * schema/registration_sheet_numbers.sql.
 */

require_once __DIR__ . '/../../auth/middleware.php';

// --- Validate input --------------------------------------------------
$year  = isset($_GET['year'])  ? (int) $_GET['year']  : 0;
$month = isset($_GET['month']) ? (int) $_GET['month'] : 0;

if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo 'Invalid year or month.';
    exit;
}

// --- Template path ---------------------------------------------------
$templatePath = defined('REGISTRATION_SHEET_TEMPLATE') && REGISTRATION_SHEET_TEMPLATE
    ? REGISTRATION_SHEET_TEMPLATE
    : __DIR__ . '/../../templates/Registration_Sheet_ver.30.xlsx';

if (!is_readable($templatePath)) {
    http_response_code(500);
    error_log('Registration sheet template missing or unreadable: ' . $templatePath);
    echo 'Template file is not available. Contact administrator.';
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'Server lacks ZipArchive extension.';
    exit;
}

// --- DB + table check ------------------------------------------------
$conn = getDbConnection();
if (!$conn) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'registration_sheet_numbers'");
if ($tableCheck === false || $tableCheck->num_rows === 0) {
    http_response_code(500);
    echo "Required table 'registration_sheet_numbers' does not exist. ";
    echo "Run: mysql -u nattest_reg -p nattest_regs < admin/schema/registration_sheet_numbers.sql";
    exit;
}

// --- Fetch approved registrations for the period ---------------------
$stmt = $conn->prepare("
    SELECT id, full_name, dob, exam_level
    FROM registrations
    WHERE approved = 1
      AND YEAR(test_date)  = ?
      AND MONTH(test_date) = ?
    ORDER BY full_name ASC
");
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Index by id for quick lookup during sheet patching
$applicantsById = [];
foreach ($registrations as $r) {
    $applicantsById[$r['id']] = $r;
}

// --- Copy template, open zip, read site code -------------------------
$tmpPath = tempnam(sys_get_temp_dir(), 'regsheet_');
if ($tmpPath === false || !copy($templatePath, $tmpPath)) {
    http_response_code(500);
    echo 'Failed to prepare workbook from template.';
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpPath) !== true) {
    @unlink($tmpPath);
    http_response_code(500);
    echo 'Failed to open workbook.';
    exit;
}

// Build sheet name -> worksheet XML file map (robust to reordering)
$relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
$bookXml = $zip->getFromName('xl/workbook.xml');

$ridToFile = [];
if (preg_match_all('#<Relationship\s+Id="([^"]+)"[^>]*Target="([^"]+)"#', $relsXml, $m)) {
    foreach ($m[1] as $i => $rid) {
        $target = $m[2][$i];
        if (strpos($target, '/') !== 0) {
            $target = 'xl/' . $target;
        }
        $ridToFile[$rid] = $target;
    }
}

$sheetNameToFile = [];
$sheetPattern = '#<sheet\s+name="([^"]+)"\s+sheetId="\d+"\s+r:id="([^"]+)"#';
if (preg_match_all($sheetPattern, $bookXml, $m)) {
    foreach ($m[1] as $i => $name) {
        $rid = $m[2][$i];
        if (isset($ridToFile[$rid])) {
            $sheetNameToFile[$name] = $ridToFile[$rid];
        }
    }
}

// Read site code from Registration!P1 (drives the Reg. No formula).
$siteCode = '000';
if (isset($sheetNameToFile['Registration'])) {
    $regXml = $zip->getFromName($sheetNameToFile['Registration']);
    if (preg_match('#<c r="P1"[^>]*><v>([^<]+)</v></c>#', $regXml, $pm)) {
        $siteCode = trim($pm[1]);
    }
}

/**
 * Write an inline string into a cell (e.g. C4, D4). Preserves the
 * style index s="..." and avoids touching sharedStrings.xml.
 *
 * Using [^/>] in the open regex prevents it from matching the / of a
 * self-closing cell, which would otherwise let the trailing .*?</c>
 * consume sibling cells (D4, E4, ...).
 */
function setCellInlineString($xml, $cellRef, $value) {
    $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $ref     = preg_quote($cellRef, '#');

    $open      = '#<c r="' . $ref . '"(\s+s="\d+")?[^/>]*>.*?</c>#s';
    $selfClose = '#<c r="' . $ref . '"(\s+s="\d+")?[^>]*/>#';

    $replacement = '<c r="' . $cellRef . '"$1 t="inlineStr"><is>'
                 . '<t xml:space="preserve">' . $escaped . '</t>'
                 . '</is></c>';

    $new = preg_replace($open, $replacement, $xml, 1, $count);
    if ($count) {
        return $new;
    }
    return preg_replace($selfClose, $replacement, $xml, 1);
}

/**
 * Write a plain number into a cell.
 */
function setCellNumber($xml, $cellRef, $value) {
    $ref         = preg_quote($cellRef, '#');
    $open        = '#<c r="' . $ref . '"(\s+s="\d+")?[^/>]*>(?:<f[^>]*>.*?</f>)?<v>[^<]*</v></c>#s';
    $selfClose   = '#<c r="' . $ref . '"(\s+s="\d+")?[^>]*/>#';
    $replacement = '<c r="' . $cellRef . '"$1><v>' . $value . '</v></c>';

    $new = preg_replace($open, $replacement, $xml, 1, $count);
    if ($count) {
        return $new;
    }
    return preg_replace($selfClose, $replacement, $xml, 1);
}

/**
 * Mirror the template's column-B formula:
 *   Registration!$P$1 & MID($A$1,5,1) & A{row}
 *   = site_code   & level_digit  & zero-padded sheet_row
 */
function computeRegNo($siteCode, $levelName, $sheetRow) {
    $levelDigit = substr($levelName, 1, 1); // "N1" -> "1"
    $padded     = str_pad((string) $sheetRow, 4, '0', STR_PAD_LEFT);
    return $siteCode . $levelDigit . $padded;
}

// =====================================================================
// ASSIGNMENT TRANSACTION
// =====================================================================
$LEVELS    = ['N1', 'N2', 'N3', 'N4', 'N5'];
$ROW_CAP   = 500; // template hard limit per level sheet

// Existing mappings for this period (read inside transaction).
$existing  = [];   // keyed by "{regId}|{level}" => ['sheet_row'=>, 'reg_no'=>]
$usedRows  = [];   // per-level: [level => [sheet_row => true]]

// New assignments we will write back.
$newMappings = []; // rows to INSERT

// Final per-level assignment for sheet patching:
//   $assigned[level] => [sheet_row => regId]
$assigned = ['N1' => [], 'N2' => [], 'N3' => [], 'N4' => [], 'N5' => []];

try {
    $conn->begin_transaction();

    // Lock existing rows for this period to avoid concurrent-export races.
    $stmt = $conn->prepare("
        SELECT registration_id, level, sheet_row, reg_no
        FROM registration_sheet_numbers
        WHERE year = ? AND month = ?
        FOR UPDATE
    ");
    $stmt->bind_param('ii', $year, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = $row['registration_id'] . '|' . $row['level'];
        $existing[$key] = [
            'sheet_row' => (int) $row['sheet_row'],
            'reg_no'    => $row['reg_no'],
        ];
        $usedRows[$row['level']][(int) $row['sheet_row']] = true;
    }
    $stmt->close();

    // Split out new vs already-assigned (applicant, level) pairs.
    $newByLevel = ['N1' => [], 'N2' => [], 'N3' => [], 'N4' => [], 'N5' => []];
    foreach ($registrations as $r) {
        foreach (explode(',', $r['exam_level']) as $lvl) {
            $lvl = trim($lvl);
            if (!in_array($lvl, $LEVELS, true)) {
                continue;
            }
            $key = $r['id'] . '|' . $lvl;
            if (isset($existing[$key])) {
                // Reuse the prior assignment (do NOT change reg_no).
                $assigned[$lvl][$existing[$key]['sheet_row']] = $r['id'];
            } else {
                $newByLevel[$lvl][] = $r['id'];
            }
        }
    }

    // For each level, shuffle the new applicants and hand out the
    // lowest free rows.
    foreach ($LEVELS as $lvl) {
        $candidates = $newByLevel[$lvl];
        if (empty($candidates)) {
            continue;
        }

        // Free rows = [1..ROW_CAP] minus already used.
        $free = [];
        for ($row = 1; $row <= $ROW_CAP; $row++) {
            if (!isset($usedRows[$lvl][$row])) {
                $free[] = $row;
            }
        }

        // Random shuffle so submission order / admin bias cannot be
        // inferred from the final sheet order.
        shuffle($candidates);

        foreach ($candidates as $regId) {
            if (empty($free)) {
                $msg = sprintf(
                    'Registration sheet %s-%02d level %s full: %d applicants, row cap %d reached',
                    $year, $month, $lvl, count($newByLevel[$lvl]), $ROW_CAP
                );
                error_log($msg);
                break;
            }
            $sheetRow = array_shift($free);
            $regNo    = computeRegNo($siteCode, $lvl, $sheetRow);

            $assigned[$lvl][$sheetRow]      = $regId;
            $usedRows[$lvl][$sheetRow]      = true;
            $newMappings[] = [
                'registration_id' => $regId,
                'level'           => $lvl,
                'sheet_row'       => $sheetRow,
                'reg_no'          => $regNo,
            ];
        }
    }

    // Persist new assignments.
    if (!empty($newMappings)) {
        $ins = $conn->prepare("
            INSERT INTO registration_sheet_numbers
                (registration_id, level, year, month, sheet_row, reg_no)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($newMappings as $m) {
            $ins->bind_param(
                'ssiiis',
                $m['registration_id'],
                $m['level'],
                $year,
                $month,
                $m['sheet_row'],
                $m['reg_no']
            );
            $ins->execute();
        }
        $ins->close();
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    $zip->close();
    @unlink($tmpPath);
    http_response_code(500);
    error_log('Registration sheet assignment failed: ' . $e->getMessage());
    echo 'Failed to assign registration numbers: ' . e($e->getMessage());
    exit;
}

// =====================================================================
// BUILD THE WORKBOOK
// =====================================================================

// 1) Patch Registration sheet: year at K1, month at K2
if (isset($sheetNameToFile['Registration'])) {
    $file = $sheetNameToFile['Registration'];
    $xml  = $zip->getFromName($file);
    if ($xml !== false) {
        $xml = setCellNumber($xml, 'K1', $year);
        $xml = setCellNumber($xml, 'K2', $month);
        $zip->addFromString($file, $xml);
    }
}

// 2) Patch each level sheet using the persisted assignments
foreach ($LEVELS as $lvl) {
    if (empty($assigned[$lvl])) {
        continue;
    }
    if (!isset($sheetNameToFile[$lvl])) {
        continue;
    }
    $file = $sheetNameToFile[$lvl];
    $xml  = $zip->getFromName($file);
    if ($xml === false) {
        continue;
    }

    foreach ($assigned[$lvl] as $sheetRow => $regId) {
        if (!isset($applicantsById[$regId])) {
            continue;
        }
        $r    = $applicantsById[$regId];
        $row  = $sheetRow + 3; // data starts at sheet row 4
        $name = $r['full_name'];
        $dob  = !empty($r['dob']) ? date('Y/m/d', strtotime($r['dob'])) : '';

        $xml = setCellInlineString($xml, 'C' . $row, $name);
        $xml = setCellInlineString($xml, 'D' . $row, $dob);
    }

    $zip->addFromString($file, $xml);
}

$zip->close();

// =====================================================================
// STREAM
// =====================================================================

$filename = sprintf('Registration_Sheet_%d-%02d.xlsx', $year, $month);
$filesize = filesize($tmpPath);

try {
    logAudit(
        'export_registration_sheet',
        'registrations',
        null,
        null,
        [
            'year'        => $year,
            'month'       => $month,
            'total'       => count($registrations),
            'new'         => count($newMappings),
            'reused'      => count($existing),
            'site_code'   => $siteCode,
        ]
    );
} catch (Throwable $e) {
    error_log('Audit log failed: ' . $e->getMessage());
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
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
