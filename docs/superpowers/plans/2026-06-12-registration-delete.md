# Registration Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins can permanently delete a registration — DB row plus uploaded files — from the detail page and the registrations list, with confirm() and audit logging.

**Architecture:** One helper `deleteRegistrationCompletely($id)` in frontend/admin/functions.php (fetch → DB delete → guarded unlink of the three uploads → logAudit), called from a new `delete` action case on registration-detail.php and a new api/registrations/delete.php used by per-row forms on registrations.php. Follows the panel's CSRF + flash + redirect pattern.

**Tech Stack:** PHP 8 admin panel (mysqli, sessions). Local MariaDB available for runtime verification.

**Spec:** `docs/superpowers/specs/2026-06-12-registration-delete-design.md`

---

### Task 1: `deleteRegistrationCompletely()` helper

**Files:** Modify `frontend/admin/functions.php` (append after `sendEmail()`)

- [ ] Add the helper:

```php
/**
 * Permanently delete a registration: DB row first, then its uploaded files.
 * Files are only unlinked when they resolve inside an intake uploads
 * directory — a tampered row must never delete arbitrary server files.
 *
 * @return array ['success' => bool, 'message' => string]
 */
function deleteRegistrationCompletely($id) {
    $conn = getDbConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database connection failed'];
    }

    $stmt = $conn->prepare("
        SELECT full_name, email, mobile, exam_level, test_date, payment_status,
               approved, photo_storage_path, id_storage_path, payment_receipt_storage_path
        FROM registrations WHERE id = ?
    ");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        return ['success' => false, 'message' => 'Registration not found'];
    }
    $row = $result->fetch_assoc();
    $stmt->close();

    // DB row first: if this fails, no files have been touched
    $del = $conn->prepare("DELETE FROM registrations WHERE id = ?");
    $del->bind_param('s', $id);
    if (!$del->execute()) {
        $err = $del->error;
        $del->close();
        return ['success' => false, 'message' => 'Database delete failed: ' . $err];
    }
    $del->close();

    // Unlink uploads after the DB delete succeeded
    $fileNotes = [];
    foreach (['photo_storage_path', 'id_storage_path', 'payment_receipt_storage_path'] as $field) {
        $path = $row[$field] ?? '';
        if ($path === '' || $path === null) {
            continue;
        }
        $real = realpath($path);
        if ($real === false) {
            $fileNotes[] = basename($path) . ' (already missing)';
            continue;
        }
        $uploadsMarker = DIRECTORY_SEPARATOR . 'intake' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        if (strpos($real, $uploadsMarker) === false) {
            error_log("Registration delete: refused to unlink path outside uploads: {$real}");
            $fileNotes[] = basename($path) . ' (skipped: outside uploads)';
            continue;
        }
        if (!@unlink($real)) {
            error_log("Registration delete: failed to unlink {$real}");
            $fileNotes[] = basename($path) . ' (could not delete)';
        }
    }

    logAudit('delete_registration', 'registrations', $id, $row, null);

    $message = 'Registration deleted permanently';
    if (!empty($fileNotes)) {
        $message .= ' — file notes: ' . implode(', ', $fileNotes);
    }
    return ['success' => true, 'message' => $message];
}
```

- [ ] `php -l frontend/admin/functions.php`
- [ ] Commit: `feat: helper to delete a registration row and its uploaded files`

---

### Task 2: detail-page action + button

**Files:** Modify `frontend/admin/pages/registration-detail.php`

- [ ] After the `reject` case (which ends `exit; }` around line 145), add
      (the helper already audit-logs — no extra logAudit here):

```php
    } elseif ($action === 'delete') {
        $deleteResult = deleteRegistrationCompletely($id);
        setFlashMessage($deleteResult['message'], $deleteResult['success'] ? 'success' : 'error');
        header('Location: ' . BASE_URL . '/pages/registrations.php');
        exit;
    }
```

- [ ] After the main action form's closing `</form>` (line ~479), add a separate form (cannot nest):

```php
            <form method="POST" onsubmit="return confirmDelete()" style="margin-top: 8px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <button type="submit" name="action" value="delete" class="btn btn-danger"
                        style="width: 100%; padding: 12px; font-size: 15px; font-weight: 600; background: #c53030;">
                    🗑 Delete Registration Permanently
                </button>
            </form>
```

- [ ] Add to the page's `<script>` block:

```javascript
function confirmDelete() {
    return confirm('Permanently delete this registration and its uploaded files? This cannot be undone.');
}
```

- [ ] `php -l`, commit: `feat: delete action on registration detail page`

---

### Task 3: list-row delete via api endpoint

**Files:** Create `frontend/admin/api/registrations/delete.php`; modify `frontend/admin/pages/registrations.php`

- [ ] Create the endpoint:

```php
<?php
/**
 * Delete Registration (DB row + uploaded files)
 */

require_once __DIR__ . '/../../auth/middleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/pages/registrations.php');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('Invalid CSRF token', 'error');
    header('Location: ' . BASE_URL . '/pages/registrations.php');
    exit;
}

$id = $_POST['id'] ?? '';
$result = deleteRegistrationCompletely($id);
setFlashMessage($result['message'], $result['success'] ? 'success' : 'error');

// Preserve the list's filter query (sanitized — header() rejects CRLF anyway)
$qs = preg_replace('/[^a-zA-Z0-9=&_\-%.]/', '', $_POST['return_query'] ?? '');
header('Location: ' . BASE_URL . '/pages/registrations.php' . ($qs !== '' ? ('?' . $qs) : ''));
exit;
```

- [ ] In `frontend/admin/pages/registrations.php`, inside the row actions div (after the Edit link, line ~286), add:

```php
                                <form method="POST" action="<?php echo BASE_URL; ?>/api/registrations/delete.php"
                                      style="display: inline;"
                                      onsubmit="return confirm('Permanently delete this registration and its uploaded files? This cannot be undone.')">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo e($reg['id']); ?>">
                                    <input type="hidden" name="return_query" value="<?php echo e($_SERVER['QUERY_STRING'] ?? ''); ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;">
                                        🗑 Delete
                                    </button>
                                </form>
```

- [ ] `php -l` both files, commit: `feat: per-row registration delete on the admin list`

---

### Task 4: Runtime verification (local admin)

- [ ] Local setup: load `frontend/admin/schema.sql` tables into the local `nattest_regs` DB (admin_users, audit_log if missing), seed an admin user with `password_hash()`, create `frontend/admin/.env` (DB creds as intake's test env, SESSION_NAME etc.). DELETE both `.env` files afterwards.
- [ ] Log in through the real login form (curl cookie jar), confirm dashboard loads.
- [ ] Seed a registration with real files under `frontend/intake/uploads/` (a prior browser-submitted row works), then POST the delete to `api/registrations/delete.php` with the session + CSRF token scraped from the list page. Verify: row gone, files gone, `audit_log` row `delete_registration` written, flash on redirect.
- [ ] Guard probe: doctor a row's `photo_storage_path` to `/etc/hosts`-like path outside uploads → delete succeeds, file skipped with warning, file untouched.
- [ ] Missing-file probe: row whose file was already removed → succeeds with "already missing" note.
- [ ] CSRF probe: POST without token → flash error, nothing deleted. GET → redirect, nothing deleted.
- [ ] Grant the local DB user DELETE privilege if missing (`GRANT DELETE ON nattest_regs.* TO 'nattest_reg'@'localhost';`) — and note for deployment that the production `nattest_reg` user needs DELETE on registrations (it may only have SELECT/INSERT/UPDATE).
