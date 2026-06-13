# Applicant Application Lookup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Applicants view their submitted registration(s) from the registration page by entering full name + mobile + DOB; upcoming-exam results include the change-request email note.

**Architecture:** Pure matching helpers in `frontend/intake/lookup-lib.php` (TDD-tested CLI-side); a POST-only JSON endpoint `application-lookup.php` that mirrors registration's name sanitization, matches dob+name in SQL and mobile in PHP (normalized both sides), and returns data-only results with `is_upcoming` and conditional `retry_link`; a left-panel card + modal in registration.html driven by a new `js/application-lookup.js` (DOM-built, no innerHTML with user data).

**Tech Stack:** PHP 8 intake service patterns (INTAKE_SERVICE guard, config helpers, initSecurity), plain JS. Local MariaDB for runtime verification (temporary `frontend/intake/.env`, delete after).

**Spec:** `docs/superpowers/specs/2026-06-13-application-lookup-design.md`

**Key storage fact:** `full_name` is stored as `htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8')` (validate.php `validateRequired`); the lookup must apply the identical transform to its input before comparing.

---

### Task 1: Matching helpers (TDD)

**Files:**
- Test: create `frontend/intake/test/test_application_lookup.php`
- Create: `frontend/intake/lookup-lib.php`

- [ ] **Step 1: Write the failing test**

Create `frontend/intake/test/test_application_lookup.php`:

```php
<?php
/**
 * Lookup matching helpers: mobile normalization, name canonicalization,
 * upcoming-exam check.
 * Run: php frontend/intake/test/test_application_lookup.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lookup-lib.php';

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// Mobile normalization: +880 / 880 / 0 prefixes all converge
$check('plain 01x kept', normalizeBdMobile('01712345678') === '01712345678');
$check('+880 collapses', normalizeBdMobile('+8801712345678') === '01712345678');
$check('880 collapses', normalizeBdMobile('8801712345678') === '01712345678');
$check('spaces/dashes stripped', normalizeBdMobile('017 1234-5678') === '01712345678');
$check('match across formats', mobilesMatch('+880 1712 345 678', '01712345678'));
$check('different numbers do not match', !mobilesMatch('01712345678', '01712345679'));
$check('empty never matches', !mobilesMatch('', ''));

// Name canonicalization mirrors registration storage (htmlspecialchars)
$check('plain name canonical', canonicalLookupName("  Test Applicant ") === 'Test Applicant');
$check("apostrophe name matches stored encoding", canonicalLookupName("O'Brien") === htmlspecialchars("O'Brien", ENT_QUOTES, 'UTF-8'));

// Upcoming check (explicit today for determinism)
$check('future date upcoming', isUpcomingTestDate('2030-01-01', '2026-06-13'));
$check('today is upcoming', isUpcomingTestDate('2026-06-13', '2026-06-13'));
$check('past date not upcoming', !isUpcomingTestDate('2026-06-12', '2026-06-13'));

exit($pass ? 0 : 1);
```

- [ ] **Step 2: Run to verify it fails** — `php frontend/intake/test/test_application_lookup.php` → fatal, lookup-lib.php missing.

- [ ] **Step 3: Create `frontend/intake/lookup-lib.php`**

```php
<?php
/**
 * NAT-TEST Intake Service - Application Lookup Helpers
 *
 * Pure matching logic for the applicant self-service lookup
 * (application-lookup.php). Kept separate so it can be unit-tested
 * without executing the endpoint.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

/**
 * Normalize a Bangladeshi mobile number for comparison: digits only,
 * with the international prefix (880...) collapsed to the local 0... form.
 */
function normalizeBdMobile(string $mobile): string {
    $digits = preg_replace('/\D+/', '', $mobile);
    if (strlen($digits) === 13 && strpos($digits, '880') === 0) {
        $digits = '0' . substr($digits, 3);
    }
    return $digits;
}

/**
 * True when two mobile numbers refer to the same line in any accepted format.
 */
function mobilesMatch(string $a, string $b): bool {
    $na = normalizeBdMobile($a);
    $nb = normalizeBdMobile($b);
    return $na !== '' && $na === $nb;
}

/**
 * Canonicalize a lookup name exactly the way registration stores names
 * (validate.php validateRequired: trim then htmlspecialchars), so names
 * with quotes/apostrophes compare correctly against stored values.
 */
function canonicalLookupName(string $name): string {
    return htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8');
}

/**
 * An exam is "upcoming" when its test date is today or later.
 */
function isUpcomingTestDate(string $testDate, ?string $today = null): bool {
    $today = $today ?: date('Y-m-d');
    return $testDate >= $today;
}
```

- [ ] **Step 4: Run to verify it passes** — 12 × PASS, exit 0; `php -l frontend/intake/lookup-lib.php` clean.
- [ ] **Step 5: Commit** — `feat: lookup matching helpers for applicant self-service` (+ Co-Authored-By trailer).

---

### Task 2: The endpoint

**Files:**
- Create: `frontend/intake/application-lookup.php`

- [ ] **Step 1: Create the endpoint**

```php
<?php
/**
 * Applicant Self-Service Application Lookup
 *
 * POST full_name + mobile + dob; all three must match one registration.
 * Returns the submitted data (never images/file paths). A deliberate,
 * documented exception to the no-HTTP-reads rule, protected by:
 * POST-only, generic errors, session rate limiting, attempt logging.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/lookup-lib.php';

// Initialize security (rate limiting, headers)
initSecurity();

// Only allow POST requests (keeps personal data out of URLs/access logs)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    $nameInput = is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '';
    $mobileInput = is_string($_POST['mobile'] ?? null) ? $_POST['mobile'] : '';
    $dobInput = is_string($_POST['dob'] ?? null) ? $_POST['dob'] : '';

    $name = canonicalLookupName($nameInput);
    $dobOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dobInput, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);

    if ($name === '' || normalizeBdMobile($mobileInput) === '' || !$dobOk) {
        logActivity('Application lookup rejected: incomplete input from ' . hashIp(getClientIp()), 'info');
        errorResponse('No application found with those details', 404);
    }

    $conn = getDbConnection();
    if (!$conn) {
        errorResponse('Service temporarily unavailable', 500);
    }

    $stmt = $conn->prepare("
        SELECT id, full_name, email, mobile, address, dob, nationality,
               id_document_type, id_document_number, exam_level, test_date,
               total_amount_paid, payment_method, payment_status, approved,
               submitted_at, payment_retry_token
        FROM registrations
        WHERE dob = ? AND LOWER(full_name) = LOWER(?)
        ORDER BY submitted_at DESC
    ");
    $stmt->bind_param('ss', $dobInput, $name);
    $stmt->execute();
    $result = $stmt->get_result();

    $idTypeLabels = ['passport' => 'Passport', 'national_id' => 'National ID'];
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        if (!mobilesMatch($row['mobile'], $mobileInput)) {
            continue;
        }
        $entry = [
            'id' => $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'mobile' => $row['mobile'],
            'address' => $row['address'],
            'dob' => $row['dob'],
            'nationality' => $row['nationality'],
            'id_document' => trim(($idTypeLabels[$row['id_document_type']] ?? '—') . ' · ' . ($row['id_document_number'] ?? '')),
            'exam_level' => $row['exam_level'],
            'test_date' => $row['test_date'],
            'total_amount' => (float)$row['total_amount_paid'],
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'approved' => (bool)$row['approved'],
            'submitted_at' => $row['submitted_at'],
            'is_upcoming' => isUpcomingTestDate($row['test_date']),
        ];
        if (in_array($row['payment_status'], ['unpaid', 'failed'], true) && !empty($row['payment_retry_token'])) {
            $entry['retry_link'] = SITE_URL . '/payment-retry.html?token=' . $row['payment_retry_token'];
        }
        $applications[] = $entry;
    }
    $stmt->close();

    if (empty($applications)) {
        logActivity('Application lookup: no match for ' . hashIp(getClientIp()), 'info');
        errorResponse('No application found with those details', 404);
    }

    logActivity('Application lookup: ' . count($applications) . ' match(es) served to ' . hashIp(getClientIp()), 'info');
    successResponse(['applications' => $applications], 'Application(s) found');

} catch (Throwable $e) {
    logActivity('Application lookup exception: ' . $e->getMessage(), 'error');
    errorResponse('Service temporarily unavailable', 500);
}
```

- [ ] **Step 2: Lint** — `php -l frontend/intake/application-lookup.php`.
- [ ] **Step 3: Commit** — `feat: applicant application lookup endpoint` (+ trailer).

---

### Task 3: Registration-page card, modal, and JS

**Files:**
- Modify: `frontend/registration.html` (left aside after the Application Fee card, ~line 147; script include near the registration.js tag)
- Create: `frontend/js/application-lookup.js`

- [ ] **Step 1: Add the card** after the Application Fee Notice card's closing `</div>` (before `<!-- Group Registration -->`):

```html
      <!-- Application Lookup -->
      <div class="p-6 bg-surface-container-low rounded-lg space-y-3">
        <div class="flex items-center gap-2 text-primary">
          <span class="material-symbols-outlined text-xl">person_search</span>
          <span class="text-sm font-semibold">Already registered?</span>
        </div>
        <p class="text-sm text-secondary leading-relaxed">View your submitted application details.</p>
        <input id="lookup_name" type="text" placeholder="Full name"
               class="w-full px-3 py-2 text-sm bg-white border border-surface-container-highest rounded-lg focus:border-primary focus:outline-none">
        <input id="lookup_mobile" type="tel" placeholder="Mobile (01XXXXXXXXX)"
               class="w-full px-3 py-2 text-sm bg-white border border-surface-container-highest rounded-lg focus:border-primary focus:outline-none">
        <input id="lookup_dob" type="date" aria-label="Date of birth"
               class="w-full px-3 py-2 text-sm bg-white border border-surface-container-highest rounded-lg focus:border-primary focus:outline-none text-secondary">
        <button id="lookup_btn" type="button"
                class="w-full bg-primary text-white px-4 py-2.5 rounded-lg text-sm font-semibold hover:opacity-90 transition-all">
          View my application
        </button>
        <span class="field-error" id="lookup-error"></span>
      </div>
```

- [ ] **Step 2: Add the modal + script include.** Directly before the `<script src="js/registration.js?v=...">` tag:

```html
  <!-- Application Lookup Modal -->
  <div id="lookup_modal" class="hidden fixed inset-0 bg-black/50 z-50 overflow-y-auto p-4">
    <div class="bg-white rounded-lg max-w-2xl mx-auto my-8 p-6 sm:p-8 relative">
      <button id="lookup_modal_close" type="button" aria-label="Close"
              class="absolute top-3 right-4 text-secondary hover:text-primary text-2xl font-bold">×</button>
      <h3 class="text-xl font-bold text-primary mb-4">Your Application</h3>
      <div id="lookup_results"></div>
    </div>
  </div>
  <script src="js/application-lookup.js?v=20260613"></script>
```

- [ ] **Step 3: Create `frontend/js/application-lookup.js`** (DOM-built rendering; complete file):

```javascript
/**
 * Applicant self-service application lookup.
 * Card inputs -> POST /intake/application-lookup.php -> modal with results.
 * All rendering uses DOM methods; applicant data never goes through innerHTML.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('lookup_btn');
    var modal = document.getElementById('lookup_modal');
    var results = document.getElementById('lookup_results');
    var errorEl = document.getElementById('lookup-error');
    if (!btn || !modal || !results) return;

    document.getElementById('lookup_modal_close').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    btn.addEventListener('click', submitLookup);

    function closeModal() { modal.classList.add('hidden'); }

    function showError(msg) {
      errorEl.textContent = msg;
      errorEl.classList.add('show');
    }

    function submitLookup() {
      errorEl.classList.remove('show');
      var name = document.getElementById('lookup_name').value.trim();
      var mobile = document.getElementById('lookup_mobile').value.trim();
      var dob = document.getElementById('lookup_dob').value;

      if (!name || !mobile || !dob) {
        showError('Please enter your full name, mobile number and date of birth.');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Searching...';

      var params = new URLSearchParams();
      params.set('full_name', name);
      params.set('mobile', mobile);
      params.set('dob', dob);

      fetch('intake/application-lookup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
          btn.disabled = false;
          btn.textContent = 'View my application';
          if (res.ok && res.data.success && res.data.data && res.data.data.applications) {
            renderResults(res.data.data.applications);
            modal.classList.remove('hidden');
          } else {
            showError(res.data.error || 'No application found with those details');
          }
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = 'View my application';
          showError('Could not reach the server — please try again.');
        });
    }

    function el(tag, className, text) {
      var node = document.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    }

    function renderResults(applications) {
      results.textContent = '';

      applications.forEach(function (app, idx) {
        var section = el('div', 'mb-6 pb-6' + (idx < applications.length - 1 ? ' border-b border-surface-container-highest' : ''));

        // Status line
        var statusWrap = el('div', 'flex flex-wrap gap-2 mb-4');
        var pay = el('span', 'px-3 py-1 rounded-full text-xs font-bold ' +
          (app.payment_status === 'paid' ? 'bg-green-100 text-green-800'
            : app.payment_status === 'failed' ? 'bg-red-100 text-red-800'
            : 'bg-amber-100 text-amber-800'),
          'Payment: ' + app.payment_status.toUpperCase());
        var appr = el('span', 'px-3 py-1 rounded-full text-xs font-bold ' +
          (app.approved ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700'),
          app.approved ? 'APPROVED' : 'PENDING REVIEW');
        statusWrap.appendChild(pay);
        statusWrap.appendChild(appr);
        section.appendChild(statusWrap);

        // Data table
        var rows = [
          ['Registration ID', app.id],
          ['Full Name', app.full_name],
          ['Email', app.email],
          ['Mobile', app.mobile],
          ['Address', app.address],
          ['Date of Birth', app.dob],
          ['Nationality', app.nationality],
          ['ID Document', app.id_document],
          ['Exam Level(s)', app.exam_level],
          ['Test Date', app.test_date],
          ['Registration Fee', Number(app.total_amount).toLocaleString('en-BD') + ' BDT'],
          ['Payment Method', app.payment_method === 'online' ? 'Online Payment' : 'Bank Deposit'],
          ['Submitted', app.submitted_at]
        ];
        var table = el('table', 'w-full text-sm border-collapse mb-4');
        rows.forEach(function (r) {
          var tr = el('tr');
          var th = el('td', 'border border-surface-container-highest bg-surface-container-low font-semibold p-2 w-2/5', r[0]);
          var td = el('td', 'border border-surface-container-highest p-2', r[1] == null ? '' : String(r[1]));
          tr.appendChild(th);
          tr.appendChild(td);
          table.appendChild(tr);
        });
        section.appendChild(table);

        // Pay action for unpaid/failed
        if (app.retry_link) {
          var payLink = el('a', 'inline-block bg-primary text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:opacity-90 mb-3', 'Complete payment');
          payLink.href = app.retry_link;
          section.appendChild(payLink);
        }

        // Change-request note: upcoming exams only
        if (app.is_upcoming) {
          var note = el('p', 'text-xs text-secondary bg-surface-container-low rounded-lg p-3');
          note.appendChild(document.createTextNode('Need to change or update any information for this upcoming exam? Email us at '));
          var mail = el('a', 'text-primary underline', 'info@nat-test.ku.ac.bd');
          mail.href = 'mailto:info@nat-test.ku.ac.bd';
          note.appendChild(mail);
          note.appendChild(document.createTextNode('.'));
          section.appendChild(note);
        }

        results.appendChild(section);
      });
    }
  });
})();
```

- [ ] **Step 4: Verify** — `grep -c "lookup_" frontend/registration.html` ≥ 8; JS brace balance via python; commit `feat: application lookup card and results modal on registration page` (+ trailer).

---

### Task 4: Runtime verification (local DB)

- [ ] Stand up local env (intake `.env` per previous tasks, php -S on 8096 via preview, MariaDB running). Seed rows via real curl registrations: one with `+8801XXXXXXXXX`-style mobile, one applicant with TWO registrations, one with an apostrophe in the name; set one row's `test_date` to the past and one `payment_status='paid'` via SQL.
- [ ] Endpoint probes via curl: exact match → 200 with applications; name case-variation + mobile format variants → match; wrong DOB → generic 404; array input (`full_name[]=x`) → clean 404/400, no fatal; GET → 405; paid row → no `retry_link`; past test_date → `is_upcoming` false.
- [ ] Browser: card renders between fee and group cards; lookup opens modal with table, badges, pay button (unpaid), upcoming note present/absent correctly; wrong details → inline generic error; close behaviors work.
- [ ] Update `frontend/intake/CLAUDE.md`: add application-lookup.php to the endpoints list as the documented read exception (name+mobile+DOB auth, generic errors, rate limited, logged).
- [ ] Delete `frontend/intake/.env`; commit any fixes; final summary with deploy list.
