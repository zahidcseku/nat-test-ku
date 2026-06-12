# ID Document Type & Number — Design

**Date:** 2026-06-12
**Status:** Approved by user

## Goal

In the registration form's "Government-Issued ID" block, the applicant first
chooses the document type (Passport or National ID) and enters the document
number; only then can they upload the document. Type and number are stored in
the database and shown to admins on the registration detail page.

## Decisions (made with user)

1. **Validation: lenient.** The number must be 4–30 characters, letters and
   digits only. Spaces and hyphens are stripped before validation and the
   value is stored uppercase. No country-specific format rules (foreign
   passports vary). The type must be `passport` or `national_id`. Both
   fields are required for every new registration.
2. **Upload gating: visible but disabled.** The upload control is dimmed
   with the hint "Select your ID type and enter the number first" until a
   type is selected and the number is valid. Clearing the number re-disables
   the upload but keeps an already-chosen file.
3. **Admin: shown.** The registration detail page's "ID Document" block
   displays e.g. "Passport · AB1234567" ("—" for legacy rows). Read-only.
4. **Storage: two dedicated NULLable columns** (approach A):
   `id_document_type ENUM('passport','national_id') NULL` and
   `id_document_number VARCHAR(30) NULL`. Legacy rows stay NULL.

## Changes

### Database — `frontend/intake/migrations/add_id_document_fields.sql` (new)

```sql
ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS id_document_type ENUM('passport', 'national_id') NULL
AFTER id_size_bytes;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS id_document_number VARCHAR(30) NULL
AFTER id_document_type;
```

Must run on the server BEFORE the new register.php deploys.

### frontend/registration.html — Government-Issued ID block

Above the existing upload control, add:
- Two radios, `name="id_doc_type"`, values `passport` / `national_id`,
  labels "Passport" and "National ID", with `id_doc_type-error`/`-success`
  spans.
- Text input `id="id_number"` with a placeholder that follows the selected
  type ("Passport number" / "National ID number"; default "Select ID type
  first"), with `id_number-error`/`-success` spans.
- The upload control's wrapper gets `id="id_upload_gate"` and starts gated:
  wrapper has `opacity-50 pointer-events-none` classes, the file input has
  the `disabled` attribute, and a hint paragraph
  `id="id_upload_gate_hint"` reads "Select your ID type and enter the
  number first". Enabling removes the two classes, the `disabled`
  attribute, and hides the hint.

### frontend/js/registration.js

- `validateField()` gains cases `id_doc_type` (a radio must be checked) and
  `id_number` (strip spaces/hyphens, uppercase, `/^[A-Z0-9]{4,30}$/`).
- New `updateIdUploadGate()` enables/disables the upload wrapper based on
  current type+number validity; called from radio change and number input
  events (wired in `initInlineValidation()`).
- Radio change also updates the number input's placeholder.
- `validateStep4()` requires both fields valid before files, stores
  `id_doc_type` and `id_number` (normalized) in `formData.step4`.
- `submitForm()` appends `id_document_type` and `id_document_number`.

### frontend/intake/validate.php

- `id_document_type`: required, enum `passport|national_id`.
- `id_document_number`: required; strip spaces/hyphens, uppercase; must
  match `/^[A-Z0-9]{4,30}$/`; sanitized value returned for storage.

### frontend/intake/register.php

- INSERT grows 38 → 40 columns (`id_document_type`, `id_document_number`
  after `id_size_bytes`), bind types gain `ss`, variables added in order.
  Alignment verified programmatically (same check that caught the original
  39/38/36 bug).

### frontend/admin/pages/registration-detail.php

- In the "ID Document" block: display the type label ("Passport" /
  "National ID") and the number above the View-document link; "—" when NULL.
  Output escaped with the page's `e()` helper. Verify the page's SELECT
  includes the new columns (SELECT * already does; otherwise add them).

### Not changed

- `frontend/intake/payment-test.php` (explicit column list; new columns are
  NULLable so test rows are unaffected).
- Payment flow, uploads pipeline, exam-date logic.

## Error handling

- Inline errors reuse `showError`/`showSuccess` with the new error spans.
- Server-side messages: "Please select your ID document type", "ID number
  must be 4–30 letters or digits".
- Files uploaded before validation failure are cleaned up by the existing
  register.php error paths (unchanged).

## Testing

1. PHP CLI test (`frontend/intake/test/test_id_document_fields.php`):
   valid passport / national_id submissions; missing type; missing number;
   too short (3 chars); too long (31); illegal characters (`AB-12 34`
   accepted via stripping → `AB1234`; `AB@123` rejected); normalization to
   uppercase; legacy compatibility (validate still returns gender 'other').
2. INSERT alignment check: columns = placeholders = types = variables = 40.
3. Live preview: radios toggle placeholder; upload disabled until type +
   valid number; enabled after; clearing number re-disables; full-form
   validation blocks submit when type/number missing.
