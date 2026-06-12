# ID Document Type & Number Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Applicants select Passport or National ID and enter the document number before they can upload their ID; type and number are stored in the database and shown to admins.

**Architecture:** Two new NULLable columns on `registrations` (migration). validate.php requires and normalizes the two new fields; register.php's INSERT grows 38 → 40 columns. The form's Government-Issued ID block gains radios + number input and a gated (dimmed/disabled) upload control driven by a small `updateIdUploadGate()` in registration.js. The admin detail page displays type · number read-only.

**Tech Stack:** PHP 8 (mysqli, prepared statements), plain HTML/JS, MariaDB migration with `IF NOT EXISTS`. PHP CLI at `/opt/homebrew/bin/php`. No Node — verify JS via grep/balance checks and the live preview.

**Spec:** `docs/superpowers/specs/2026-06-12-id-document-type-number-design.md`

**Deploy order reminder:** run the migration on the server BEFORE deploying the new register.php.

---

### Task 1: Migration + backend validation (TDD)

**Files:**
- Create: `frontend/intake/migrations/add_id_document_fields.sql`
- Create: `frontend/intake/test/test_id_document_fields.php`
- Modify: `frontend/intake/validate.php` (insert after the nationality block, around line 332)

- [ ] **Step 1: Create the migration**

Create `frontend/intake/migrations/add_id_document_fields.sql`:

```sql
-- Applicants now declare their ID document type and number before uploading.
-- Columns are NULLable: rows registered before this feature stay NULL.
-- Run: mysql -u nattest_reg -p nattest_regs < add_id_document_fields.sql

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS id_document_type ENUM('passport', 'national_id') NULL
AFTER id_size_bytes;

ALTER TABLE registrations
ADD COLUMN IF NOT EXISTS id_document_number VARCHAR(30) NULL
AFTER id_document_type;
```

- [ ] **Step 2: Write the failing test**

Create `frontend/intake/test/test_id_document_fields.php`:

```php
<?php
/**
 * ID document type & number validation (lenient):
 * - type required, passport|national_id
 * - number required, 4-30 chars, letters/digits only after stripping
 *   spaces and hyphens, stored uppercase
 * Run: php frontend/intake/test/test_id_document_fields.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../validate.php';

function basePost(array $overrides = []): array {
    return array_merge([
        'full_name' => 'Test Applicant',
        'email' => 'test@example.com',
        'mobile' => '01712345678',
        'address' => '123 Test Road, Khulna',
        'dob' => '2000/01/15',
        'nationality' => 'Bangladeshi',
        'payment_method' => 'online',
        'exam_levels' => ['1Q'],
        'total_amount' => 4000,
        'test_date' => '2026/08/15',
        'id_document_type' => 'passport',
        'id_document_number' => 'AB1234567',
    ], $overrides);
}

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// Valid passport
$v = validateRegistrationData(basePost());
$check('valid passport submission accepted', $v['valid'] === true);
$check('passport type stored', ($v['data']['id_document_type'] ?? null) === 'passport');
$check('passport number stored', ($v['data']['id_document_number'] ?? null) === 'AB1234567');

// Valid national ID
$v = validateRegistrationData(basePost(['id_document_type' => 'national_id', 'id_document_number' => '1234567890123']));
$check('valid national_id submission accepted', $v['valid'] === true);
$check('national_id type stored', ($v['data']['id_document_type'] ?? null) === 'national_id');

// Normalization: spaces/hyphens stripped, uppercased
$v = validateRegistrationData(basePost(['id_document_number' => 'ab-12 34']));
$check('spaces/hyphens stripped + uppercased', ($v['data']['id_document_number'] ?? null) === 'AB1234');

// Missing type
$v = validateRegistrationData(basePost(['id_document_type' => '']));
$check('missing type rejected', $v['valid'] === false && isset($v['errors']['id_document_type']));

// Invalid type
$v = validateRegistrationData(basePost(['id_document_type' => 'driving_license']));
$check('invalid type rejected', $v['valid'] === false && isset($v['errors']['id_document_type']));

// Missing number
$v = validateRegistrationData(basePost(['id_document_number' => '']));
$check('missing number rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Too short (3 chars after stripping)
$v = validateRegistrationData(basePost(['id_document_number' => 'A-1 2']));
$check('too-short number rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Too long (31 chars)
$v = validateRegistrationData(basePost(['id_document_number' => str_repeat('A', 31)]));
$check('too-long number rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Illegal characters survive stripping
$v = validateRegistrationData(basePost(['id_document_number' => 'AB@1234']));
$check('illegal characters rejected', $v['valid'] === false && isset($v['errors']['id_document_number']));

// Gender behavior unchanged (regression guard)
$v = validateRegistrationData(basePost());
$check("gender still defaults to 'other'", ($v['data']['gender'] ?? null) === 'other');

exit($pass ? 0 : 1);
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php frontend/intake/test/test_id_document_fields.php`
Expected: FAIL — validate.php currently ignores the two fields, so `id_document_type`/`id_document_number` never appear in `$v['data']` and the "stored" checks fail (the rejection checks also fail because submissions remain valid).

- [ ] **Step 4: Add validation to validate.php**

In `frontend/intake/validate.php`, directly AFTER the nationality block:

```php
    $nationalityResult = validateRequired($data['nationality'] ?? '', 'Nationality', 2, 100);
    if (!$nationalityResult['valid']) {
        $errors['nationality'] = $nationalityResult['error'];
    } else {
        $sanitized['nationality'] = $nationalityResult['sanitized'];
    }
```

insert:

```php
    // ID document type & number (Documents section).
    // Lenient number rule by design: foreign passports vary, so only
    // require 4-30 letters/digits after stripping spaces and hyphens.
    $idTypeResult = validateEnum($data['id_document_type'] ?? '', 'ID document type', ['passport', 'national_id']);
    if (!$idTypeResult['valid']) {
        $errors['id_document_type'] = 'Please select your ID document type';
    } else {
        $sanitized['id_document_type'] = $idTypeResult['sanitized'];
    }

    $idNumber = strtoupper(str_replace([' ', '-'], '', trim($data['id_document_number'] ?? '')));
    if ($idNumber === '') {
        $errors['id_document_number'] = 'ID document number is required';
    } elseif (!preg_match('/^[A-Z0-9]{4,30}$/', $idNumber)) {
        $errors['id_document_number'] = 'ID number must be 4-30 letters or digits';
    } else {
        $sanitized['id_document_number'] = $idNumber;
    }
```

(`validateEnum` is the same helper the payment_method check uses three lines below — match its 3-argument call style.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php frontend/intake/test/test_id_document_fields.php`
Expected: 14 × PASS, exit 0.
Also: `php -l frontend/intake/validate.php` → no syntax errors.

The existing gender test will now fail because its fixture lacks the two new
required fields. **Update the fixture in
`frontend/intake/test/test_gender_optional.php`**: add
`'id_document_type' => 'passport', 'id_document_number' => 'AB1234567',`
to its `$base` array. Then run
`php frontend/intake/test/test_gender_optional.php` → 4 × PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/intake/migrations/add_id_document_fields.sql frontend/intake/validate.php frontend/intake/test/test_id_document_fields.php frontend/intake/test/test_gender_optional.php
git commit -m "feat: validate and store ID document type and number (backend)"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 2: register.php INSERT 38 → 40 columns

**Files:**
- Modify: `frontend/intake/register.php` (the INSERT statement and bind_param)

- [ ] **Step 1: Add the two columns to the INSERT**

In the column list, change:

```php
            id_filename, id_storage_path, id_size_bytes,
```

to:

```php
            id_filename, id_storage_path, id_size_bytes,
            id_document_type, id_document_number,
```

and add exactly two `?` to the VALUES list (38 → 40 placeholders total):

```php
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
```

- [ ] **Step 2: Bind the new variables**

Before the `bind_param` call, next to the other prepared variables, add:

```php
    $idDocType = $data['id_document_type'];
    $idDocNumber = $data['id_document_number'];
```

Change the type string from `'ssssssssssisssissississsisissssssdddss'` to:

```
'ssssssssssisssississssisssisissssssdddss'
```

(40 chars: the new pair is `ss` inserted right after `id_size_bytes`'s `i` at position 18.)

In the argument list, insert after `$id_size`:

```php
        $id_size,
        $idDocType,
        $idDocNumber,
        $r_name,
```

- [ ] **Step 3: Verify alignment programmatically**

Run:

```bash
python3 - <<'EOF'
import re
src = open('frontend/intake/register.php').read()
cols_raw = re.search(r'INSERT INTO registrations \((.*?)\) VALUES', src, re.S).group(1)
cols = [c.strip() for c in cols_raw.replace('\n',' ').split(',') if c.strip()]
ph = re.search(r'VALUES \((.*?)\)', src, re.S).group(1).count('?')
b = re.search(r"bind_param\(\s*'([a-z]+)',(.*?)\);", src, re.S)
args = [a.strip() for a in b.group(2).split(',') if a.strip()]
print(f"columns={len(cols)} placeholders={ph} types={len(b.group(1))} vars={len(args)}")
assert len(cols) == ph == len(b.group(1)) == len(args) == 40, "MISMATCH"
for i, (c, t, a) in enumerate(zip(cols, b.group(1), args), 1):
    print(f"{i:2} {t}  {c:32} {a}")
EOF
```

Expected: `columns=40 placeholders=40 types=40 vars=40`, and in the table `id_document_type`/`id_document_number` map to type `s` and `$idDocType`/`$idDocNumber`, with `photo_size_bytes`/`id_size_bytes`/`payment_receipt_size_bytes`/`honeypot_tripped`/`approved` on `i` and the three money columns on `d`.
Also: `php -l frontend/intake/register.php` → clean.

- [ ] **Step 4: Commit**

```bash
git add frontend/intake/register.php
git commit -m "feat: store ID document type and number on registration insert"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 3: registration.html — ID block with radios, number input, gated upload

**Files:**
- Modify: `frontend/registration.html` (the "ID Upload" block, currently lines ~422-440)

- [ ] **Step 1: Replace the ID Upload block**

Replace this entire block:

```html
                <!-- ID Upload -->
                <div class="p-6 bg-surface-container-low rounded-lg">
                  <label class="text-xs font-semibold text-secondary tracking-wider uppercase mb-4 block">Government-Issued ID</label>
                  <div class="space-y-4">
                    <p class="text-sm text-secondary">
                      Upload a clear copy of your Passport or National ID card.
                    </p>
                    <div class="flex items-center gap-4">
                      <label class="file-input-wrapper bg-white border-2 border-dashed border-surface-container-highest hover:border-primary rounded-lg px-6 py-4 cursor-pointer transition-all">
                        <span class="material-symbols-outlined text-2xl text-secondary mr-2">upload</span>
                        <span class="text-sm font-medium text-secondary">Choose ID (max 4MB, JPG/PNG/PDF)</span>
                        <input accept="image/jpeg,image/png,application/pdf" id="id_upload" type="file" />
                      </label>
                      <img alt="ID preview" class="file-preview" id="id_upload-preview" />
                    </div>
                    <span class="field-error" id="id_upload-error"></span>
                    <span class="field-success" id="id_upload-success"></span>
                  </div>
                </div>
```

with:

```html
                <!-- ID Upload -->
                <div class="p-6 bg-surface-container-low rounded-lg">
                  <label class="text-xs font-semibold text-secondary tracking-wider uppercase mb-4 block">Government-Issued ID</label>
                  <div class="space-y-4">
                    <p class="text-sm text-secondary">
                      Select your document type, enter its number, then upload a clear copy.
                    </p>

                    <div class="flex flex-wrap gap-6">
                      <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="id_doc_type" value="passport" class="w-5 h-5 accent-primary">
                        <span class="font-semibold text-primary">Passport</span>
                      </label>
                      <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="id_doc_type" value="national_id" class="w-5 h-5 accent-primary">
                        <span class="font-semibold text-primary">National ID</span>
                      </label>
                    </div>
                    <span class="field-error" id="id_doc_type-error"></span>
                    <span class="field-success" id="id_doc_type-success"></span>

                    <div class="flex flex-col gap-2 max-w-md">
                      <label class="text-xs font-semibold text-secondary tracking-wider uppercase" for="id_number">Document Number</label>
                      <input class="ghost-input py-3 text-lg placeholder:text-surface-container-highest" id="id_number" placeholder="Select ID type first" type="text" />
                      <span class="field-error" id="id_number-error"></span>
                      <span class="field-success" id="id_number-success"></span>
                    </div>

                    <div id="id_upload_gate" class="space-y-2 opacity-50 pointer-events-none">
                      <p id="id_upload_gate_hint" class="text-xs text-warning font-semibold">Select your ID type and enter the number first</p>
                      <div class="flex items-center gap-4">
                        <label class="file-input-wrapper bg-white border-2 border-dashed border-surface-container-highest hover:border-primary rounded-lg px-6 py-4 cursor-pointer transition-all">
                          <span class="material-symbols-outlined text-2xl text-secondary mr-2">upload</span>
                          <span class="text-sm font-medium text-secondary">Choose ID (max 4MB, JPG/PNG/PDF)</span>
                          <input accept="image/jpeg,image/png,application/pdf" id="id_upload" type="file" disabled />
                        </label>
                        <img alt="ID preview" class="file-preview" id="id_upload-preview" />
                      </div>
                    </div>
                    <span class="field-error" id="id_upload-error"></span>
                    <span class="field-success" id="id_upload-success"></span>
                  </div>
                </div>
```

- [ ] **Step 2: Verify**

```bash
grep -c 'name="id_doc_type"' frontend/registration.html   # expect 2
grep -c 'id="id_number"' frontend/registration.html        # expect 1
grep -c 'id_upload_gate' frontend/registration.html        # expect 2 (gate + hint)
```

- [ ] **Step 3: Commit**

```bash
git add frontend/registration.html
git commit -m "feat: ID type radios, number input and gated upload in the ID section"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 4: registration.js — validation, gating, submission

**Files:**
- Modify: `frontend/js/registration.js`

- [ ] **Step 1: Add the radio-group guard and id_number case to `validateField()`**

`validateField()` starts with `const el = document.getElementById(fieldId);` and `if (!el) return true;` — a radio group has no element with id `id_doc_type`, so it needs a guard BEFORE that lookup. Change the top of `validateField()` from:

```javascript
  function validateField(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return true;
    const value = (el.value || '').trim();
```

to:

```javascript
  function validateField(fieldId) {
    // Radio group — no single element to read a value from
    if (fieldId === 'id_doc_type') {
      const checked = document.querySelector('input[name="id_doc_type"]:checked');
      if (!checked) { showError('id_doc_type', 'Please select your ID document type'); return false; }
      showSuccess('id_doc_type', '✓');
      return true;
    }

    const el = document.getElementById(fieldId);
    if (!el) return true;
    const value = (el.value || '').trim();
```

Then add a new case after `case 'nationality':`:

```javascript
      case 'id_number': {
        const normalized = getNormalizedIdNumber();
        if (!normalized) { showError('id_number', 'Please enter your document number'); return false; }
        if (!/^[A-Z0-9]{4,30}$/.test(normalized)) { showError('id_number', 'ID number must be 4-30 letters or digits'); return false; }
        showSuccess('id_number', '✓'); return true;
      }
```

- [ ] **Step 2: Add the helpers (after `toggleReceiptSection()`)**

```javascript
  /**
   * Normalized ID number: uppercase, spaces and hyphens stripped —
   * the same rule the server applies
   */
  function getNormalizedIdNumber() {
    const input = document.getElementById('id_number');
    return input ? input.value.replace(/[\s-]/g, '').toUpperCase() : '';
  }

  /**
   * The ID upload stays disabled until a document type is selected and a
   * valid number is entered
   */
  function updateIdUploadGate() {
    const gate = document.getElementById('id_upload_gate');
    const input = document.getElementById('id_upload');
    const hint = document.getElementById('id_upload_gate_hint');
    if (!gate || !input) return;

    const typeChosen = !!document.querySelector('input[name="id_doc_type"]:checked');
    const numberValid = /^[A-Z0-9]{4,30}$/.test(getNormalizedIdNumber());

    if (typeChosen && numberValid) {
      gate.classList.remove('opacity-50', 'pointer-events-none');
      input.disabled = false;
      if (hint) hint.classList.add('hidden');
    } else {
      gate.classList.add('opacity-50', 'pointer-events-none');
      input.disabled = true;
      if (hint) hint.classList.remove('hidden');
    }
  }
```

- [ ] **Step 3: Wire events in `initInlineValidation()`**

Add before the `payment_method` radios block:

```javascript
    document.querySelectorAll('input[name="id_doc_type"]').forEach(radio => {
      radio.addEventListener('change', () => {
        validateField('id_doc_type');
        const numberInput = document.getElementById('id_number');
        if (numberInput) {
          numberInput.placeholder = radio.value === 'passport' ? 'Passport number' : 'National ID number';
        }
        updateIdUploadGate();
      });
    });

    const idNumber = document.getElementById('id_number');
    if (idNumber) {
      idNumber.addEventListener('input', updateIdUploadGate);
      idNumber.addEventListener('blur', () => validateField('id_number'));
    }
```

- [ ] **Step 4: Require the fields in `validateStep4()` and store them**

At the top of `validateStep4()`, after `let isValid = true;`, add:

```javascript
    // Document type and number come before the upload
    if (!validateField('id_doc_type')) {
      isValid = false;
    }
    if (!validateField('id_number')) {
      isValid = false;
    }
```

In the `formData.step4 = { ... }` assignment, add two properties:

```javascript
      formData.step4 = {
        id_doc_type: document.querySelector('input[name="id_doc_type"]:checked')?.value || '',
        id_number: getNormalizedIdNumber(),
        photo_file: photoFile,
        id_file: idFile,
        payment_receipt_file: paymentFile || null
      };
```

- [ ] **Step 5: Append to the submission**

In `submitForm()`, directly before `formDataToSend.append('id_document', formData.step4.id_file);`, add:

```javascript
    formDataToSend.append('id_document_type', formData.step4.id_doc_type);
    formDataToSend.append('id_document_number', formData.step4.id_number);
```

- [ ] **Step 6: Verify**

```bash
grep -c "id_doc_type\|id_number\|updateIdUploadGate\|getNormalizedIdNumber" frontend/js/registration.js   # expect >= 15
python3 -c "s=open('frontend/js/registration.js').read(); print('balance', s.count('{')-s.count('}'), s.count('(')-s.count(')'))"
```
Expected: balance 0 0. (Full behavior verified in Task 5's preview run; a syntax error would disable the entire form, which is unmissable there.)

- [ ] **Step 7: Commit**

```bash
git add frontend/js/registration.js
git commit -m "feat: gate ID upload behind document type and number, submit both fields"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`

---

### Task 5: Admin display + end-to-end verification

**Files:**
- Modify: `frontend/admin/pages/registration-detail.php` (the "ID Document" block, around line 285)

- [ ] **Step 1: Show type · number in the admin ID Document block**

The page's query is `SELECT r.*, ed.exam_date FROM registrations r ...` so the new columns arrive automatically. Change:

```php
                <!-- ID Document -->
                    <div style="font-size: 14px; font-weight: 500; color: #1a202c; margin-bottom: 8px;">ID Document</div>
```

so that directly after the `ID Document` title div there is:

```php
                    <?php
                    $idTypeLabels = ['passport' => 'Passport', 'national_id' => 'National ID'];
                    $idTypeLabel = $idTypeLabels[$registration['id_document_type'] ?? ''] ?? null;
                    ?>
                    <div style="font-size: 13px; color: #4a5568; margin-bottom: 8px;">
                        <?php if ($idTypeLabel): ?>
                            <?php echo e($idTypeLabel); ?> &middot; <?php echo e($registration['id_document_number'] ?? ''); ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </div>
```

(Keep the existing View-document link below it untouched. `e()` is the page's existing escape helper.)

- [ ] **Step 2: Lint**

```bash
php -l frontend/admin/pages/registration-detail.php
```

- [ ] **Step 3: Backend tests still green**

```bash
php frontend/intake/test/test_id_document_fields.php && php frontend/intake/test/test_gender_optional.php
```
Expected: all PASS.

- [ ] **Step 4: Live preview behavior check (controller has a preview server)**

With the local preview on the frontend directory, on /registration.html verify:
1. ID section shows Passport/National ID radios, Document Number input (placeholder "Select ID type first"), and a dimmed upload area with the hint.
2. Upload input is `disabled` until a type is selected AND a valid number (≥4 alphanumerics) is entered; placeholder switches to "Passport number"/"National ID number" with the radio.
3. Clearing the number re-disables the upload.
4. Submitting with type/number missing marks `id_doc_type-error`/`id_number-error` and validateStep4 fails.
5. No new console errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/admin/pages/registration-detail.php
git commit -m "feat: show ID document type and number on admin registration detail"
```
End the commit message body with: `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`
