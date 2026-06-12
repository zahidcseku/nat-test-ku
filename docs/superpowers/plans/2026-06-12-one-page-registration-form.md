# One-Page Registration Form Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the four-step wizard registration form into a single scrollable page and remove the gender field (backend stores `'other'`).

**Architecture:** Restructure in place. `registration.html` loses the step show/hide CSS, progress tracker, per-step nav buttons, gender field, and confirmation modal; all four sections render stacked with a live payment summary above the single Submit button. `registration.js` loses the step/modal machinery; per-field validation moves into a reusable `validateField()` used both inline (blur/change) and by submit-time validation. The submission path (`submitForm` fetch + payment redirect handling) is unchanged except for dropping `gender`. Backend: `validate.php` always returns `gender = 'other'`.

**Tech Stack:** Plain HTML/JS (no frameworks — project constraint), PHP 8 intake service. PHP CLI at `/opt/homebrew/bin/php`. No Node installed — JS syntax is verified via grep checks plus a browser console check (any SyntaxError kills the whole module, which makes errors obvious immediately).

**Spec:** `docs/superpowers/specs/2026-06-12-one-page-registration-form-design.md`

**Note on intermediate commits:** Tasks 2–5 are interdependent (HTML references JS functions and vice versa). Each task commits, but the form is only fully functional again after Task 5. Do not deploy mid-plan.

---

### Task 1: Backend — gender no longer required, always stored as 'other'

**Files:**
- Test: create `frontend/intake/test/test_gender_optional.php`
- Modify: `frontend/intake/validate.php` (the gender block, around line 322)

- [ ] **Step 1: Write the failing test**

Create `frontend/intake/test/test_gender_optional.php`:

```php
<?php
/**
 * Gender must not be required and must always be stored as 'other'
 * (form no longer collects it; DB column is NOT NULL).
 * Run: php frontend/intake/test/test_gender_optional.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../validate.php';

$base = [
    'full_name' => 'Test Applicant',
    'email' => 'test@example.com',
    'mobile' => '01712345678',
    'address' => '123 Test Road, Khulna',
    'dob' => '2000/01/15',
    'nationality' => 'Bangladeshi',
    'payment_method' => 'online',
    'exam_levels' => ['1Q', '3Q'],
    'total_amount' => 8000,
    'test_date' => '2026/08/15',
];

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// 1. No gender field at all -> valid, stored as 'other'
$v = validateRegistrationData($base);
$check('submission without gender is valid', $v['valid'] === true);
if (!$v['valid']) echo '  errors: ' . json_encode($v['errors']) . "\n";
$check("gender defaults to 'other'", ($v['data']['gender'] ?? null) === 'other');

// 2. A submitted gender value is ignored
$withGender = $base;
$withGender['gender'] = 'male';
$v2 = validateRegistrationData($withGender);
$check('submission with gender still valid', $v2['valid'] === true);
$check("submitted gender ignored, stored as 'other'", ($v2['data']['gender'] ?? null) === 'other');

exit($pass ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php frontend/intake/test/test_gender_optional.php`
Expected: FAIL — `submission without gender is valid` fails because validate.php currently requires gender (a gender error appears in the printed errors).

- [ ] **Step 3: Change the gender block in validate.php**

In `frontend/intake/validate.php`, replace:

```php
    $genderResult = validateEnum($data['gender'] ?? '', 'Gender', ['male', 'female', 'other'], true);
    if (!$genderResult['valid']) {
        $errors['gender'] = $genderResult['error'];
    } else {
        $sanitized['gender'] = $genderResult['sanitized'];
    }
```

with:

```php
    // Gender is no longer collected on the form (2026-06-12). The DB column
    // is NOT NULL, so every new registration stores 'other'. Any submitted
    // gender value is ignored.
    $sanitized['gender'] = 'other';
```

(If the exact existing block differs slightly, find the gender validation block near line 322 and replace the whole block. `register.php` needs no change — it binds `$data['gender']`, which is now always `'other'`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php frontend/intake/test/test_gender_optional.php`
Expected: 4 × PASS, exit 0.
Also run: `php -l frontend/intake/validate.php` → "No syntax errors detected".

- [ ] **Step 5: Commit**

```bash
git add frontend/intake/validate.php frontend/intake/test/test_gender_optional.php
git commit -m "feat: stop requiring gender; store 'other' for all new registrations"
```

---

### Task 2: registration.html — remove gender field, progress tracker, modal, step CSS

**Files:**
- Modify: `frontend/registration.html`

- [ ] **Step 1: Remove the step show/hide and progress CSS rules**

In the `<style>` block (around line 53), replace:

```css
    /* Form step visibility */
    .form-step {
      display: none;
    }
    .form-step.active {
      display: block;
    }
    /* Progress tracker */
    .progress-step.active {
      background-color: #002147 !important;
      color: white !important;
    }
    .progress-step.completed {
      background-color: #002147 !important;
      color: white !important;
    }
```

with:

```css
    /* All form sections render stacked on one page */
```

(The `form-step`/`active` classes remain on the section divs but no longer have any effect.)

- [ ] **Step 2: Remove the progress tracker block**

Delete the entire block from `<!-- Progress Tracker -->` (around line 208) through its closing `</div>` (around line 231) — the block containing the four `data-progress-step` circles. It sits between the `<h2>...Online Registration Form</h2>` heading and `<form id="registration-form" ...>`.

- [ ] **Step 3: Remove the gender field**

Delete this block (around lines 279–289):

```html
                <div class="flex flex-col gap-2">
                  <label class="text-xs font-semibold text-secondary tracking-wider uppercase" for="gender">Gender</label>
                  <select class="ghost-input py-3 text-lg bg-white" id="gender">
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                  </select>
                  <span class="field-error" id="gender-error"></span>
                  <span class="field-success" id="gender-success"></span>
                </div>
```

- [ ] **Step 4: Remove the level confirmation modal**

Delete the entire block from `<!-- Level Confirmation Modal -->` (around line 374) through the modal's closing `</div>` (around line 392) — the block with `id="level_confirmation_modal"`, `confirm_levels_count`, `confirm_levels_list`, `confirm_total`, `cancel_confirm_btn`, `confirm_selection_btn`.

- [ ] **Step 5: Verify removals**

Run:
```bash
grep -c "data-progress-step\|level_confirmation_modal\|id=\"gender\"" frontend/registration.html
```
Expected: `0`

- [ ] **Step 6: Commit**

```bash
git add frontend/registration.html
git commit -m "feat: remove gender field, progress tracker and level modal from registration page"
```

---

### Task 3: registration.html — numbered sections, remove nav buttons, summary + submit block

**Files:**
- Modify: `frontend/registration.html`

- [ ] **Step 1: Number the four section headings**

Make these four replacements:

`<h2 class="text-2xl font-bold text-primary mb-2">Personal Information</h2>` → `<h2 class="text-2xl font-bold text-primary mb-2">1. Personal Information</h2>`

`<h2 class="text-2xl font-bold text-primary mb-2">Exam Details</h2>` → `<h2 class="text-2xl font-bold text-primary mb-2">2. Exam Details</h2>`

The Payment Method section heading (inside `<div id="step-3" ...>`): `Payment Method` → `3. Payment Method` (same h2 pattern).

`<h2 class="text-2xl font-bold text-primary mb-2">Document Uploads</h2>` → `<h2 class="text-2xl font-bold text-primary mb-2">4. Document Uploads</h2>`

⚠️ Only change the four h2 headings inside the online form (`step-1`–`step-4` divs). Do NOT touch the offline-instructions section, which has its own "Offline Registration Process" headings.

- [ ] **Step 2: Remove step-1 navigation**

Delete (inside `step-1`):

```html
              <!-- Navigation -->
              <div class="flex justify-end pt-4">
                <button onclick="RegistrationForm.nextStep()" class="bg-primary text-white px-10 py-4 rounded-lg font-bold text-lg hover:shadow-xl hover:scale-[1.02] active:scale-95 transition-all flex items-center gap-3">
                  Proceed to Exam Details
                  <span class="material-symbols-outlined">arrow_forward</span>
                </button>
              </div>
```

- [ ] **Step 3: Remove step-2 navigation**

Delete the `<div class="flex justify-between pt-4">` block inside `step-2` containing the `Previous Step` button (`RegistrationForm.previousStep()`) and the `Proceed to Payment` button (`RegistrationForm.nextStep()`).

- [ ] **Step 4: Remove step-3 navigation**

Delete the `<div class="flex justify-between pt-4">` block inside `step-3` containing `Previous Step` and `Proceed to Uploads` buttons.

- [ ] **Step 5: Replace step-4 navigation with summary + submit**

Replace (inside `step-4`):

```html
              <!-- Navigation -->
              <div class="flex justify-between pt-4">
                <button onclick="RegistrationForm.previousStep()" class="text-primary font-semibold text-sm hover:underline underline-offset-8 transition-all flex items-center gap-2">
                  <span class="material-symbols-outlined text-sm">arrow_back</span>
                  Previous Step
                </button>
                <button type="button" onclick="RegistrationForm.submitForm(event)" class="submit-btn bg-primary text-white px-10 py-4 rounded-lg font-bold text-lg hover:shadow-xl hover:scale-[1.02] active:scale-95 transition-all flex items-center gap-3">
                  Submit Application
                  <span class="material-symbols-outlined">check_circle</span>
                </button>
              </div>
```

with:

```html
              <!-- Payment Summary & Submit -->
              <div class="pt-6 border-t border-surface-container-highest space-y-4">
                <div id="submit_summary" class="p-4 bg-primary-container rounded-lg hidden">
                  <div class="flex items-center justify-between">
                    <span class="text-white font-semibold"><span id="submit_summary_count">0</span> level(s) selected</span>
                    <span class="text-white font-bold text-xl">Total: <span id="submit_summary_total">0</span> BDT</span>
                  </div>
                </div>
                <p id="submit_summary_empty" class="text-sm text-secondary">Select a test date and exam level(s) above to see your total fee.</p>
                <div class="flex justify-end">
                  <button type="button" onclick="RegistrationForm.submitForm(event)" class="submit-btn bg-primary text-white px-10 py-4 rounded-lg font-bold text-lg hover:shadow-xl hover:scale-[1.02] active:scale-95 transition-all flex items-center gap-3">
                    Submit Application
                    <span class="material-symbols-outlined">check_circle</span>
                  </button>
                </div>
              </div>
```

- [ ] **Step 6: Verify**

Run:
```bash
grep -c "RegistrationForm.nextStep\|RegistrationForm.previousStep" frontend/registration.html; grep -c "submit_summary" frontend/registration.html
```
Expected: first count `0`, second count ≥ 3.

- [ ] **Step 7: Commit**

```bash
git add frontend/registration.html
git commit -m "feat: render registration form as one page with live payment summary"
```

---

### Task 4: registration.js — remove step machinery, reusable field validation, summary, scroll-to-error

**Files:**
- Modify: `frontend/js/registration.js`

- [ ] **Step 1: Remove `currentStep` state**

Delete the line `let currentStep = 1;` (around line 28).

- [ ] **Step 2: Add `validateField()` and rewrite `validateStep1()` to use it**

Replace the entire `validateStep1()` function (from its doc comment `/** Validate Step 1 ... */` through its closing `}`, currently ~lines 235–355) with:

```javascript
  /**
   * Validate a single field by id. Shows inline error/success.
   * Used by inline (blur/change) validation and by section validators.
   */
  function validateField(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return true;
    const value = (el.value || '').trim();

    switch (fieldId) {
      case 'full_name':
        if (!value) { showError('full_name', 'Please enter your full name as it appears on your ID'); return false; }
        if (value.length < 3) { showError('full_name', 'Full name must be at least 3 characters'); return false; }
        showSuccess('full_name', '✓'); return true;

      case 'email':
        if (!value) { showError('email', 'Please enter your email address'); return false; }
        if (!isValidEmail(value)) { showError('email', 'Please enter a valid email address'); return false; }
        showSuccess('email', '✓'); return true;

      case 'mobile':
        if (!value) { showError('mobile', 'Please enter your mobile number'); return false; }
        if (!isValidPhone(value)) { showError('mobile', 'Please enter a valid Bangladeshi mobile number (e.g., 01712345678)'); return false; }
        showSuccess('mobile', '✓'); return true;

      case 'address':
        if (!value) { showError('address', 'Please enter your full address'); return false; }
        if (value.length < 10) { showError('address', 'Please enter a complete address (at least 10 characters)'); return false; }
        showSuccess('address', '✓'); return true;

      case 'dob': {
        if (!value) { showError('dob', 'Please enter your date of birth'); return false; }
        // Date picker returns YYYY-MM-DD, convert to YYYY/MM/DD for validation
        const dobFormatted = value.replace(/-/g, '/');
        const dobRegex = /^\d{4}\/\d{2}\/\d{2}$/;
        if (!dobRegex.test(dobFormatted)) { showError('dob', 'Please enter date in YYYY/MM/DD format'); return false; }
        const [year, month, day] = dobFormatted.split('/').map(Number);
        const dobDate = new Date(year, month - 1, day);
        const today = new Date();
        if (dobDate.getFullYear() !== year || dobDate.getMonth() !== month - 1 || dobDate.getDate() !== day) {
          showError('dob', 'Please enter a valid date'); return false;
        }
        if (dobDate > today) { showError('dob', 'Date of birth cannot be in the future'); return false; }
        showSuccess('dob', '✓'); return true;
      }

      case 'nationality':
        if (!value) { showError('nationality', 'Please enter your nationality'); return false; }
        showSuccess('nationality', '✓'); return true;

      case 'test_date':
        if (!value) { showError('test_date', 'Please select your intended test date'); return false; }
        showSuccess('test_date', '✓'); return true;

      default:
        return true;
    }
  }

  /**
   * Validate Section 1: Personal Information
   */
  function validateStep1() {
    const fields = ['full_name', 'email', 'mobile', 'address', 'dob', 'nationality'];
    let isValid = true;

    fields.forEach(fieldId => {
      if (!validateField(fieldId)) {
        isValid = false;
      }
    });

    if (isValid) {
      formData.step1 = {
        full_name: document.getElementById('full_name').value.trim(),
        email: document.getElementById('email').value.trim(),
        mobile: document.getElementById('mobile').value.trim(),
        address: document.getElementById('address').value.trim(),
        dob: document.getElementById('dob').value,
        nationality: document.getElementById('nationality').value.trim()
      };
    }

    return isValid;
  }
```

(Note: gender is gone from both the field list and `formData.step1`.)

- [ ] **Step 3: Simplify the test_date check in `validateStep2()`**

In `validateStep2()`, replace:

```javascript
    // Intended Test Date (PLACEHOLDER: will load from database)
    const testDate = document.getElementById('test_date').value;
    if (!testDate) {
      showError('test_date', 'Please select your intended test date');
      isValid = false;
    } else {
      showSuccess('test_date', '✓');
    }
```

with:

```javascript
    // Intended Test Date
    const testDate = document.getElementById('test_date').value;
    if (!validateField('test_date')) {
      isValid = false;
    }
```

- [ ] **Step 4: Remove the step/modal machinery**

Delete these functions entirely (each with its doc comment):
- `goToStep()` (~line 429)
- `showStep()` (~line 559)
- `updateProgressTracker()` (~line 581)
- `nextStep()` (~line 599)
- `previousStep()` (~line 638)
- `showLevelConfirmation()` (~line 1218)
- `cancelLevelConfirmation()` (~line 1253)
- `confirmLevelSelection()` (~line 1260)

- [ ] **Step 5: Add scroll-to-first-error and use it in `submitForm()`**

Add after `clearFieldValidation()` (around line 233):

```javascript
  /**
   * Scroll to and focus the first field with a visible error
   */
  function scrollToFirstError() {
    const firstError = document.querySelector('.field-error.show');
    if (!firstError) return;
    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const wrapper = firstError.closest('div');
    const field = wrapper ? wrapper.querySelector('input, select, textarea') : null;
    if (field) field.focus({ preventScroll: true });
  }
```

In `submitForm()`, replace:

```javascript
    // Validate all steps
    if (!validateAllSteps()) {
      console.log('Validation failed');
      alert('Please correct the errors before submitting');
      return false;
    }
```

with:

```javascript
    // Validate the whole form
    if (!validateAllSteps()) {
      console.log('Validation failed');
      scrollToFirstError();
      return false;
    }
```

- [ ] **Step 6: Drop gender from submission**

In `submitForm()`, delete the line:

```javascript
    formDataToSend.append('gender', formData.step1.gender);
```

- [ ] **Step 7: Update `updateFeeDisplay()` to drive the submit-area summary**

Replace the entire `updateFeeDisplay()` function with:

```javascript
  /**
   * Update fee displays (levels box and submit-area summary)
   */
  function updateFeeDisplay() {
    const count = selectedLevels.length;
    const total = count * CONFIG.FEE_PER_LEVEL;

    const feeSummary = document.getElementById('fee_summary');
    const feeCount = document.getElementById('fee_count');
    const feeTotal = document.getElementById('fee_total');
    const feeMultiplier = document.getElementById('fee_multiplier');

    if (count > 0) {
      feeSummary.classList.remove('hidden');
      feeCount.textContent = count;
      feeTotal.textContent = total.toLocaleString('en-BD') + ' BDT';
      feeMultiplier.textContent = count;
    } else {
      feeSummary.classList.add('hidden');
    }

    // Live summary above the Submit button
    const submitSummary = document.getElementById('submit_summary');
    const submitSummaryEmpty = document.getElementById('submit_summary_empty');
    if (submitSummary && submitSummaryEmpty) {
      if (count > 0) {
        document.getElementById('submit_summary_count').textContent = count;
        document.getElementById('submit_summary_total').textContent = total.toLocaleString('en-BD');
        submitSummary.classList.remove('hidden');
        submitSummaryEmpty.classList.add('hidden');
      } else {
        submitSummary.classList.add('hidden');
        submitSummaryEmpty.classList.remove('hidden');
      }
    }

    totalAmount = total;
  }
```

- [ ] **Step 8: Add `initInlineValidation()`**

Add after `scrollToFirstError()`:

```javascript
  /**
   * Wire inline validation: fields validate when the user leaves them
   */
  function initInlineValidation() {
    ['full_name', 'email', 'mobile', 'address', 'nationality'].forEach(fieldId => {
      const el = document.getElementById(fieldId);
      if (el) el.addEventListener('blur', () => validateField(fieldId));
    });

    const dob = document.getElementById('dob');
    if (dob) dob.addEventListener('change', () => validateField('dob'));

    const testDate = document.getElementById('test_date');
    if (testDate) testDate.addEventListener('change', () => validateField('test_date'));

    document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
      radio.addEventListener('change', () => validateStep3());
    });
  }
```

(Level checkboxes already validate inline via `handleLevelSelection()`; file inputs already validate via the change handler in registration.html.)

- [ ] **Step 9: Clean up `resetForm()`**

In `resetForm()`, delete these lines:

```javascript
    // Reset state
    currentStep = 1;
```
(keep the `formData = {...}` reset that follows) and delete:
```javascript
    // Show first step
    showStep(1);
```

- [ ] **Step 10: Update the public API export**

In the `return { ... }` block at the end of the module, remove these entries:
`showStep,`, `updateProgressTracker,`, `nextStep,`, `previousStep,`, `showLevelConfirmation,`, `cancelLevelConfirmation,`, `confirmLevelSelection`

and add:
`validateField,`, `initInlineValidation,`

(keep everything else; make sure the last entry has no trailing comma).

- [ ] **Step 11: Verify no stale references**

Run:
```bash
grep -cE "nextStep|previousStep|showStep|currentStep|LevelConfirmation|updateProgressTracker|gender" frontend/js/registration.js
```
Expected: `0`. Then run the project's JS parse check from the shell (the JXA Function-constructor one-liner used throughout this project's sessions, or `node --check` if Node has been installed) and confirm it reports the file parses. If no parse tool is available, the Task 6 browser check covers it — a syntax error disables the entire form, which is unmissable.

- [ ] **Step 12: Commit**

```bash
git add frontend/js/registration.js
git commit -m "feat: one-page form logic — inline validation, live summary, no step machinery"
```

---

### Task 5: registration.html inline script — drop modal wiring, init inline validation

**Files:**
- Modify: `frontend/registration.html` (the `<script>` block near line 803)

- [ ] **Step 1: Remove the modal button listeners**

In the `DOMContentLoaded` handler, delete the two blocks that wire `cancel_confirm_btn` → `RegistrationForm.cancelLevelConfirmation()` and `confirm_selection_btn` → `RegistrationForm.confirmLevelSelection()` (around lines 820–831). The first looks like:

```javascript
      const cancelBtn = document.getElementById('cancel_confirm_btn');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
          RegistrationForm.cancelLevelConfirmation();
        });
      }
```
(and the equivalent `confirmBtn` block). Delete both.

- [ ] **Step 2: Initialize inline validation**

Immediately after the `RegistrationForm.loadExamDates();` call, add:

```javascript
      // Inline validation: fields validate as the user leaves them
      RegistrationForm.initInlineValidation();
```

- [ ] **Step 3: Verify no stale references**

Run:
```bash
grep -c "cancelLevelConfirmation\|confirmLevelSelection\|nextStep\|previousStep" frontend/registration.html
```
Expected: `0`

- [ ] **Step 4: Commit**

```bash
git add frontend/registration.html
git commit -m "feat: wire inline validation on page load, drop modal listeners"
```

---

### Task 6: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Backend test still green**

```bash
php frontend/intake/test/test_gender_optional.php
php -l frontend/intake/validate.php && php -l frontend/intake/register.php
```
Expected: all PASS / no syntax errors.

- [ ] **Step 2: Static page checks**

```bash
(php -S localhost:8095 -t frontend >/tmp/onepage_test.log 2>&1 &) && sleep 1
curl -s http://localhost:8095/registration.html | grep -c "data-progress-step\|level_confirmation_modal\|id=\"gender\""   # expect 0
curl -s http://localhost:8095/registration.html | grep -c "submit_summary"                                                # expect >= 3
curl -s http://localhost:8095/registration.html | grep -c "1. Personal Information"                                       # expect 1
pkill -f "php -S localhost:8095"
```

- [ ] **Step 3: Manual browser check (human or browser tooling)**

Open `http://localhost:<port>/registration.html` via a local server and verify, with the browser console open (no errors on load):
1. All four sections visible at once, numbered 1–4; no Next/Back buttons.
2. Leaving an empty required field shows its inline error; filling it correctly shows ✓.
3. Ticking levels updates both the levels fee box and the summary above Submit (count and total = levels × 4000).
4. Clicking Submit with errors scrolls to the first invalid field.
5. Clicking Submit with valid data + photo/ID files sends the POST to `/intake/register.php` (visible in the network tab; locally the DB insert fails without MySQL — expected; full submit must be re-verified after deployment).

- [ ] **Step 4: Push (with user approval)**

```bash
git push origin main
```
