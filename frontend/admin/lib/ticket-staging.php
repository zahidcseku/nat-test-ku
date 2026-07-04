<?php
/**
 * Admission ticket staging + sending logic.
 *
 * Used by:
 *   - /admin/api/admission-tickets/upload.php (stage from xlsx + zip)
 *   - /admin/api/admission-tickets/send.php   (send selected or all)
 *
 * No HTTP concerns here — callers validate CSRF, method, and inputs.
 * This file is pure business logic + DB + filesystem.
 *
 * Schema (see admin/schema/redesign_admission_tickets.sql):
 *   admission_tickets(id, xlsx_id, reg_no, exam_date_id, file_path,
 *                     send_status, emailed_at, last_error,
 *                     created_by, created_at)
 *
 * Reg_no lookup: registration_sheet_numbers.reg_no -> registration_id
 * -> registrations(email, full_name)
 */

require_once __DIR__ . '/xlsx-reader.php';

/**
 * Stage admission tickets from a paired xlsx + zip upload.
 *
 * @param string $xlsxTmpPath  $_FILES['...']['tmp_name'] of the xlsx
 * @param string $zipTmpPath   $_FILES['...']['tmp_name'] of the zip
 * @param string $examDateId   exam_dates.id (CHAR(36) UUID)
 * @param string $examDate     'YYYY-MM-DD' — used in the storage path
 * @param int    $createdBy    admin_users.id of the uploader
 *
 * @return array{success:bool, staged:int, warnings:string[], errors:string[]}
 */
function stageTicketsFromUpload(string $xlsxTmpPath, string $zipTmpPath, string $examDateId, string $examDate, int $createdBy): array {
    $warnings = [];
    $errors   = [];
    $staged   = 0;

    // --- Parse xlsx ---------------------------------------------------
    $rows = [];
    try {
        $rows = readXlsxRows($xlsxTmpPath);
    } catch (Throwable $e) {
        return ['success' => false, 'staged' => 0, 'warnings' => [], 'errors' => ['Could not read xlsx: ' . $e->getMessage()]];
    }

    if (empty($rows)) {
        return ['success' => false, 'staged' => 0, 'warnings' => [], 'errors' => ['xlsx had no rows']];
    }

    // Validate headers — accept case/space-insensitive variants.
    $firstRow = $rows[0];
    $idKey     = _findKey($firstRow, ['ID', 'Id', 'id']);
    $regNoKey  = _findKey($firstRow, ['RegNumber', 'Reg No', 'RegNo', 'Reg No.', 'reg_number', 'regnumber']);
    if ($idKey === null || $regNoKey === null) {
        $errors[] = 'xlsx must have columns ID and RegNumber. Found: ' . implode(', ', array_keys($firstRow));
        return ['success' => false, 'staged' => 0, 'warnings' => $warnings, 'errors' => $errors];
    }

    // --- Extract zip to temp -----------------------------------------
    $tempDir = sys_get_temp_dir() . '/tickets_' . bin2hex(random_bytes(8));
    if (!mkdir($tempDir, 0755, true)) {
        $errors[] = 'Could not create temp directory for zip extraction';
        return ['success' => false, 'staged' => 0, 'warnings' => $warnings, 'errors' => $errors];
    }

    $zip = new ZipArchive();
    $openCode = $zip->open($zipTmpPath);
    if ($openCode !== true) {
        @rmdir($tempDir);
        $errors[] = 'Could not open zip (code ' . $openCode . ')';
        return ['success' => false, 'staged' => 0, 'warnings' => $warnings, 'errors' => $errors];
    }
    if (!$zip->extractTo($tempDir)) {
        $zip->close();
        @rmdir($tempDir);
        $errors[] = 'Could not extract zip';
        return ['success' => false, 'staged' => 0, 'warnings' => $warnings, 'errors' => $errors];
    }
    $zip->close();

    // Some zips wrap files in a top-level directory. Build a flat map
    // of basename -> full path so we can find <ID>.pdf anywhere inside.
    $filesInZip = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if ($f->isFile()) {
            $filesInZip[strtolower($f->getFilename())] = $f->getPathname();
        }
    }

    // --- Target storage path -----------------------------------------
    $conn = getDbConnection();
    if (!$conn) {
        _cleanupTemp($tempDir);
        $errors[] = 'Database connection failed';
        return ['success' => false, 'staged' => 0, 'warnings' => $warnings, 'errors' => $errors];
    }

    $targetDir = UPLOAD_PATH . 'tickets/' . $examDate . '/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // --- Process xlsx rows -------------------------------------------
    // De-dup within this upload (UNIQUE constraint would also catch it,
    // but warning per-row is friendlier than a single dup-key error).
    $seenIds = [];
    $insert  = $conn->prepare("
        INSERT INTO admission_tickets
            (xlsx_id, reg_no, exam_date_id, file_path, send_status, created_by)
        VALUES (?, ?, ?, ?, 'staged', ?)
        ON DUPLICATE KEY UPDATE
            reg_no      = VALUES(reg_no),
            file_path   = VALUES(file_path),
            send_status = 'staged',
            emailed_at  = NULL,
            last_error  = NULL
    ");

    $rowCount = 0;
    foreach ($rows as $r) {
        $rowCount++;
        $xlsxId = trim((string) ($r[$idKey] ?? ''));
        $regNo  = trim((string) ($r[$regNoKey] ?? ''));
        if ($xlsxId === '' || $regNo === '') {
            $warnings[] = "xlsx row {$rowCount}: missing ID or RegNumber, skipped";
            continue;
        }
        if (isset($seenIds[$xlsxId])) {
            $warnings[] = "xlsx row {$rowCount}: duplicate ID '{$xlsxId}', skipped";
            continue;
        }
        $seenIds[$xlsxId] = true;

        // Locate the PDF in the extracted zip.
        $pdfKey = strtolower($xlsxId . '.pdf');
        if (!isset($filesInZip[$pdfKey])) {
            $warnings[] = "xlsx ID '{$xlsxId}': no '{$xlsxId}.pdf' in zip, skipped";
            continue;
        }
        $srcPath = $filesInZip[$pdfKey];

        // Validate MIME is PDF (defence vs. a misnamed executable).
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($srcPath);
        if ($mime !== 'application/pdf') {
            $warnings[] = "xlsx ID '{$xlsxId}': file is not a PDF (got {$mime}), skipped";
            continue;
        }

        // Move to permanent location (overwrite if re-uploaded).
        $destPath = $targetDir . $xlsxId . '.pdf';
        if (!copy($srcPath, $destPath)) {
            $warnings[] = "xlsx ID '{$xlsxId}': could not save PDF, skipped";
            continue;
        }

        // Store ABSOLUTE path — page renders a download link from it.
        $absPath = realpath($destPath);
        if ($absPath === false) {
            $absPath = $destPath;
        }

        $insert->bind_param('ssssi', $xlsxId, $regNo, $examDateId, $absPath, $createdBy);
        if (!$insert->execute()) {
            $warnings[] = "xlsx ID '{$xlsxId}': DB insert failed — " . $insert->error;
            continue;
        }

        $staged++;
    }
    $insert->close();

    _cleanupTemp($tempDir);

    // Best-effort audit log.
    try {
        logAudit(
            'stage_admission_tickets',
            'admission_tickets',
            null,
            null,
            ['exam_date_id' => $examDateId, 'staged' => $staged, 'warnings' => count($warnings)]
        );
    } catch (Throwable $e) {
        error_log('ticket staging audit failed: ' . $e->getMessage());
    }

    if ($staged === 0) {
        $errors[] = 'No tickets were staged';
        return ['success' => false, 'staged' => 0, 'warnings' => $warnings, 'errors' => $errors];
    }

    return ['success' => true, 'staged' => $staged, 'warnings' => $warnings, 'errors' => $errors];
}

/**
 * Send one or more staged admission tickets by ID.
 *
 * @param array<int, int|string> $ticketIds  admission_tickets.id values
 * @param int                    $sentBy     admin_users.id
 *
 * @return array{sent:int, failed:int, errors:string[]}
 */
function sendTickets(array $ticketIds, int $sentBy): array {
    $conn = getDbConnection();
    if (!$conn) {
        return ['sent' => 0, 'failed' => 0, 'errors' => ['Database connection failed']];
    }

    if (!function_exists('sendEmailWithAttachment')) {
        require_once __DIR__ . '/../functions.php';
    }

    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($ticketIds as $tid) {
        $tid = (int) $tid;
        if ($tid <= 0) continue;

        // Pull the ticket + JOIN through registration_sheet_numbers to
        // registrations for the recipient email + name.
        $stmt = $conn->prepare("
            SELECT at.id, at.xlsx_id, at.reg_no, at.file_path,
                   r.email, r.full_name, r.id AS registration_id
            FROM admission_tickets at
            LEFT JOIN registration_sheet_numbers rsn ON rsn.reg_no = at.reg_no
            LEFT JOIN registrations r ON r.id = rsn.registration_id
            WHERE at.id = ?
            ORDER BY rsn.id ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $tid);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ticket) {
            $failed++;
            $errors[] = "ticket {$tid}: not found";
            continue;
        }

        // No registration match -> can't send.
        if (empty($ticket['email']) || empty($ticket['registration_id'])) {
            $failed++;
            $err = "ticket {$tid} (xlsx ID {$ticket['xlsx_id']}): no registration found for reg_no {$ticket['reg_no']}";
            $errors[] = $err;
            _markTicketFailed($conn, $tid, $err);
            continue;
        }

        // PDF readable?
        if (!is_readable($ticket['file_path'])) {
            $failed++;
            $err = "ticket {$tid}: PDF not readable at {$ticket['file_path']}";
            $errors[] = $err;
            _markTicketFailed($conn, $tid, $err);
            continue;
        }

        $body    = _renderTicketEmailBody($ticket);
        $subject = 'Your NAT-TEST Admission Ticket';

        $ok = sendEmailWithAttachment(
            $ticket['email'],
            $subject,
            $body,
            $ticket['registration_id'],
            'admission_ticket',
            [[
                'path' => $ticket['file_path'],
                'name' => 'admission-ticket-' . $ticket['xlsx_id'] . '.pdf',
                'mime' => 'application/pdf',
            ]]
        );

        if ($ok) {
            $upd = $conn->prepare("UPDATE admission_tickets SET send_status='sent', emailed_at=NOW(), last_error=NULL WHERE id=?");
            $upd->bind_param('i', $tid);
            $upd->execute();
            $upd->close();
            $sent++;
        } else {
            // sendEmailWithAttachment already wrote the error to email_log;
            // pull the latest one for the row's last_error.
            $lastErr = '';
            $logQ = $conn->prepare("
                SELECT error_message FROM email_log
                WHERE recipient_email = ? AND email_type = 'admission_ticket'
                ORDER BY id DESC LIMIT 1
            ");
            $logQ->bind_param('s', $ticket['email']);
            $logQ->execute();
            $logRow = $logQ->get_result()->fetch_assoc();
            $logQ->close();
            if (!empty($logRow['error_message'])) {
                $lastErr = $logRow['error_message'];
            }
            _markTicketFailed($conn, $tid, $lastErr ?: 'SMTP send failed (no error detail)');
            $failed++;
            $errors[] = "ticket {$tid} (xlsx ID {$ticket['xlsx_id']}): {$lastErr}";
        }
    }

    try {
        logAudit(
            'send_admission_tickets',
            'admission_tickets',
            null,
            null,
            ['sent' => $sent, 'failed' => $failed, 'sent_by' => $sentBy]
        );
    } catch (Throwable $e) {
        error_log('ticket send audit failed: ' . $e->getMessage());
    }

    return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
}

// ----------------------------------------------------------------------
// Internals
// ----------------------------------------------------------------------

function _findKey(array $row, array $candidates): ?string {
    foreach ($row as $k => $v) {
        $normalized = strtolower(preg_replace('/\s+/', '', (string) $k));
        foreach ($candidates as $c) {
            if (strtolower(preg_replace('/\s+/', '', $c)) === $normalized) {
                return $k;
            }
        }
    }
    return null;
}

function _markTicketFailed(mysqli $conn, int $ticketId, string $error): void {
    $upd = $conn->prepare("UPDATE admission_tickets SET send_status='failed', last_error=? WHERE id=?");
    $upd->bind_param('si', $error, $ticketId);
    $upd->execute();
    $upd->close();
}

function _cleanupTemp(string $dir): void {
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iter as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function _renderTicketEmailBody(array $ticket): string {
    $name = htmlspecialchars((string) ($ticket['full_name'] ?? 'Applicant'), ENT_QUOTES, 'UTF-8');
    $id   = htmlspecialchars((string) $ticket['xlsx_id'],        ENT_QUOTES, 'UTF-8');
    $reg  = htmlspecialchars((string) $ticket['reg_no'],         ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
        . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
        . '<p style="font-size:14px;">Dear ' . $name . ',</p>'
        . '<p style="font-size:14px;">Your admission ticket is attached to this email. '
        . 'Please print it and bring it with you on the exam day.</p>'
        . '<div style="background:#f4f6f8;border-left:4px solid #667eea;padding:12px 16px;margin:16px 0;font-size:14px;">'
        . '<strong>Examinee ID:</strong> ' . $id . '<br>'
        . '<strong>Reg. Number:</strong> ' . $reg
        . '</div>'
        . '<p style="font-size:13px;color:#555;">Please arrive at the test center at least 30 minutes before the exam start time. '
        . 'If you have any questions, reply to this email or contact '
        . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>.</p>'
        . '<p style="font-size:13px;color:#555;">This is an automated message — please do not reply directly.</p>'
        . '</body></html>';
}
