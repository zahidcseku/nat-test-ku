# Registration Delete (Admin Panel) — Design

**Date:** 2026-06-12
**Status:** Approved by user (proceeding to implementation)

## Goal

Admins can permanently delete a registration: the database row is removed and
the uploaded files (photo, ID document, payment receipt) are deleted from the
server.

## Decisions (made with user)

1. **Permission:** any logged-in admin (no super_admin gate).
2. **Confirmation:** native browser `confirm()` dialog.
3. **Placement:** the registration-detail page AND each row of the
   registrations list.

## Components

### `deleteRegistrationCompletely($id)` in frontend/admin/functions.php

Returns `['success' => bool, 'message' => string]`.

1. Fetch the row by id; not found → failure message.
2. Capture for the audit record: full_name, email, mobile, exam_level,
   test_date, payment_status, approved, and the three storage paths.
3. `DELETE FROM registrations WHERE id = ?` (prepared). Failure → stop;
   files untouched (no half-deleted state).
4. After a successful DB delete, unlink each of photo_storage_path,
   id_storage_path, payment_receipt_storage_path (skip empty/NULL):
   - Path guard: `realpath()` of the file must exist inside a directory whose
     real path contains `/intake/uploads/` — otherwise skip with a warning
     (a tampered row must never delete arbitrary files).
   - Missing file → note and continue. Unlink failure → note the orphan
     path. Neither fails the operation.
5. `logAudit('delete_registration', 'registrations', $id, $capturedValues,
   null)`.
6. Message reports DB deletion and per-file outcomes.

### frontend/admin/pages/registration-detail.php

- New `delete` case in the existing POST action switch (after CSRF check):
  calls the helper, sets the flash message, redirects to
  `pages/registrations.php`.
- Red "Delete" button beside the existing action buttons, inside a form
  posting `action=delete` + CSRF token, with
  `onsubmit="return confirm('Permanently delete this registration and its uploaded files? This cannot be undone.')"`.

### frontend/admin/api/registrations/delete.php (new)

- Requires auth (same middleware include as sibling api files), POST only,
  CSRF-validated. Calls the helper, sets flash, redirects back to
  `pages/registrations.php` (preserving the current filter query if passed).

### frontend/admin/pages/registrations.php

- Per-row red "Delete" form posting `id` + CSRF token to
  `api/registrations/delete.php`, same `confirm()` text.

## Not changed

- email_log rows are kept (sent-mail history); their registration_id no
  longer resolves after deletion.
- No intake-service changes. No schema changes.

## Error handling

- Invalid CSRF → existing flash-error path.
- Row not found → flash error, redirect.
- DB failure → flash error, files untouched.
- File issues → operation succeeds with a flash message noting any orphaned
  or missing files; details in the audit log/admin error log.

## Verification (local runtime)

Stand up the admin panel against the local MariaDB (load admin schema.sql,
seed an admin user, admin .env), log in through the real login form (curl
session or preview browser), then:
1. Delete a seeded registration with real files under intake/uploads/ →
   row gone, files gone, audit_log row written, flash shown.
2. Row doctored to point a storage path outside uploads/ → delete succeeds
   but that file is skipped (still on disk), warning recorded.
3. Delete with a missing file → succeeds, notes the missing file.
4. POST without CSRF → rejected.
5. GET on the api endpoint → 405/redirect, nothing deleted.
