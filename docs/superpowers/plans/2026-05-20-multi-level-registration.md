# Multi-Level Registration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable applicants to register for multiple exam levels (1-5) on the same test date, with automatic fee calculation of 4000 BDT per level, while maintaining full backward compatibility with existing single-level registrations.

**Architecture:** Frontend checkbox selection → JavaScript array → PHP validation → CSV storage in database (comma-separated levels) → Admin display with calculated totals. Database migration adds total_amount column with safe defaults for existing data.

**Tech Stack:** PHP 8.0+, MySQL/MariaDB, vanilla JavaScript, HTML5, CSS3. No framework dependencies.

**Backward Compatibility:** All existing functionality preserved. Single-level registrations work unchanged. Migration sets default total_amount=4000 for existing records.

---

## Task 1: Create Database Migration Script

**Files:**
- Create: `frontend/intake/migrations/add_total_amount_column.sql`

**Context:** First task - prepare database for multi-level support by adding total_amount column. This is safe to do independently as existing records will get DEFAULT 4000.

- [ ] **Step 1: Write migration SQL script**

Create migration file with column addition and default value for existing records:

```sql
-- Add total_amount column to registrations table
-- Migration: 2026-05-20-multi-level-registration
-- Database: nattest_regs

-- Add the column with DEFAULT 4000 for existing single-level registrations
ALTER TABLE registrations
ADD COLUMN total_amount INT NOT NULL DEFAULT 4000
COMMENT 'Total application fee in BDT (4000 × number of levels selected)'
AFTER exam_level;

-- Add index for faster revenue queries
ALTER TABLE registrations
ADD INDEX idx_total_amount (total_amount);

-- Verify the migration
SELECT
    COUNT(*) as total_registrations,
    SUM(total_amount) as total_revenue,
    AVG(total_amount) as avg_fee
FROM registrations;
```

- [ ] **Step 2: Add rollback documentation**

Append rollback instructions to the same file:

```sql
-- ROLLBACK SCRIPT (if needed)
-- To rollback this migration, execute:
-- ALTER TABLE registrations DROP COLUMN total_amount;
-- This will restore the original schema without data loss
```

- [ ] **Step 3: Test migration on local/staging**

Run: `mysql -u nattest_reg -p nattest_regs < frontend/intake/migrations/add_total_amount_column.sql`

Expected: Column added successfully, verification query shows correct counts and sums

- [ ] **Step 4: Commit migration**

```bash
git add frontend/intake/migrations/add_total_amount_column.sql
git commit -m "feat: add total_amount column for multi-level registration support"
```

---

## Task 2: Update Frontend HTML - Level Selection UI

**Files:**
- Modify: `frontend/registration.html:422-429` (replace dropdown with checkboxes)

**Context:** Frontend changes to support multi-level selection. Replaces single-select dropdown with multi-checkbox interface. Independent from backend changes.

- [ ] **Step 1: Replace exam_level dropdown with checkbox container**

Find the exam_level select element (around line 422-429) and replace with:

```html
<div class="flex flex-col gap-2">
  <label class="text-xs font-semibold text-secondary tracking-wider uppercase" for="test_date">Intended Test Date</label>
  <select class="ghost-input py-3 text-lg bg-white" id="test_date">
    <option value="">Select Test Date</option>
    <!-- Options will be loaded from database -->
  </select>
  <span class="field-error" id="test_date-error"></span>
  <span class="field-success" id="test_date-success"></span>
</div>

<!-- REPLACE THE EXAM_LEVEL DROPDOWN (lines 422-429) WITH: -->
<div id="exam_levels_container" class="flex flex-col gap-2 opacity-50">
  <label class="text-xs font-semibold text-secondary tracking-wider uppercase">
    Exam Levels <span class="text-secondary font-normal">(Select one or more)</span>
  </label>
  <div id="exam_levels_checkboxes" class="grid grid-cols-5 gap-3">
    <p class="text-secondary col-span-5 text-sm">Select a test date first to see available levels</p>
  </div>

  <!-- Live Fee Display -->
  <div id="fee_summary" class="mt-4 p-4 bg-primary-container rounded-lg hidden">
    <div class="flex items-center justify-between">
      <span class="text-white font-semibold">Levels Selected:</span>
      <span id="fee_count" class="text-white font-bold">0</span>
    </div>
    <div class="flex items-center justify-between mt-2">
      <span class="text-white font-semibold">Total Fee:</span>
      <span id="fee_total" class="text-white font-bold text-xl">0 BDT</span>
    </div>
    <div class="text-white/80 text-sm mt-2">
      (4000 BDT × <span id="fee_multiplier">0</span> levels)
    </div>
  </div>

  <span class="field-error" id="exam_levels-error"></span>
  <span class="field-success" id="exam_levels-success"></span>
</div>
```

- [ ] **Step 2: Add confirmation modal before payment step**

Insert after the "Proceed to Uploads" button (around line 438):

```html
<!-- Level Confirmation Modal -->
<div id="level_confirmation_modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
  <div class="bg-white rounded-lg p-8 max-w-md mx-4">
    <h3 class="text-xl font-bold text-primary mb-4">Confirm Your Selection</h3>
    <div class="space-y-2 mb-6">
      <p class="text-secondary">You've selected <strong id="confirm_levels_count">0</strong> level(s):</p>
      <p id="confirm_levels_list" class="text-primary font-semibold text-lg"></p>
      <p class="text-lg font-bold text-primary mt-4">Total Fee: <span id="confirm_total">0</span> BDT</p>
    </div>
    <div class="flex gap-4">
      <button id="cancel_confirm_btn" type="button" class="flex-1 px-6 py-3 border-2 border-primary text-primary rounded-lg font-semibold hover:bg-surface-container-low">
        Go Back
      </button>
      <button id="confirm_selection_btn" type="button" class="flex-1 px-6 py-3 bg-primary text-white rounded-lg font-semibold hover:bg-primary/90">
        Confirm & Continue
      </button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Update fee notice in sidebar**

Find the fee notice section (around line 154-162) and update:

```html
<!-- Current: <p class="text-2xl font-bold text-white mb-1">BDT 4,000.00</p> -->
<p class="text-2xl font-bold text-white mb-1">BDT 4,000 per level</p>
<p class="text-xs text-white">Examples: 2 levels = BDT 8,000 | 3 levels = BDT 12,000</p>
```

- [ ] **Step 4: Add payment amount display at top of payment step**

Add at the beginning of Step 2 (Payment Method) section (around line 307):

```html
<div class="p-4 bg-accent/10 rounded-lg border-2 border-accent/30 mb-8">
  <div class="flex items-center gap-2 mb-2">
    <span class="material-symbols-outlined text-primary">payments</span>
    <span class="font-bold text-primary">Total Payment Due</span>
  </div>
  <p id="payment_amount_display" class="text-3xl font-bold text-primary">4000 BDT</p>
  <p id="payment_levels_display" class="text-sm text-secondary">For 1 selected level</p>
</div>
```

- [ ] **Step 5: Commit HTML changes**

```bash
git add frontend/registration.html
git commit -m "feat: add multi-level checkbox UI with live fee calculation"
```

---

## Task 3: Update Frontend JavaScript - Multi-Level Selection Logic

**Files:**
- Modify: `frontend/js/registration.js`

**Context:** JavaScript logic to handle multi-level selection, fee calculation, and confirmation modal. Depends on Task 2 HTML changes being complete.

- [ ] **Step 1: Add configuration and state variables**

Add to the RegistrationForm CONFIG object (find the CONFIG definition, add after existing config):

```javascript
const RegistrationForm = {
    // ... existing CONFIG ...
    CONFIG: {
        // ... existing config items ...
        FEE_PER_LEVEL: 4000,
        MAX_LEVELS: 5,
        MIN_LEVELS: 1
    },

    // Add state tracking
    selectedLevels: [],
    totalAmount: 0,

    // ... existing methods ...
};
```

- [ ] **Step 2: Add populateExamLevels method for checkboxes**

Find the existing populateExamLevels method (if exists) or add this new method after loadExamDates:

```javascript
populateExamLevels: function(testDate) {
    const container = document.getElementById('exam_levels_checkboxes');
    if (!container) return;

    // Clear existing checkboxes
    container.innerHTML = '';
    this.selectedLevels = [];
    this.totalAmount = 0;
    this.updateFeeDisplay();

    // Show loading state
    container.innerHTML = '<p class="text-secondary col-span-5">Loading available levels...</p>';

    // Fetch available levels for this date
    fetch(`/intake/api/exam-dates/levels.php?date=${encodeURIComponent(testDate)}`)
        .then(response => response.json())
        .then(data => {
            if (data.levels && data.levels.length > 0) {
                container.innerHTML = ''; // Clear loading message

                data.levels.forEach(level => {
                    const checkboxDiv = document.createElement('div');
                    checkboxDiv.className = 'flex items-center gap-2 p-3 bg-surface-container-low rounded-lg cursor-pointer hover:bg-surface-container-high transition-all';
                    checkboxDiv.innerHTML = `
                        <input type="checkbox"
                               id="level_${level}"
                               value="${level}"
                               class="w-5 h-5 text-primary accent-primary"
                               onchange="RegistrationForm.handleLevelSelection('${level}', this.checked)">
                        <label for="level_${level}" class="flex-1 cursor-pointer font-semibold text-primary select-none">${level}</label>
                    `;
                    container.appendChild(checkboxDiv);
                });

                // Enable the container
                document.getElementById('exam_levels_container').classList.remove('opacity-50');
            } else {
                container.innerHTML = '<p class="text-error col-span-5">No levels available for this date</p>';
            }
        })
        .catch(error => {
            console.error('Error loading levels:', error);
            container.innerHTML = '<p class="text-error col-span-5">Error loading levels. Please try again.</p>';
        });
},
```

- [ ] **Step 3: Add handleLevelSelection method**

Add after populateExamLevels:

```javascript
handleLevelSelection: function(level, isSelected) {
    if (isSelected) {
        if (!this.selectedLevels.includes(level)) {
            this.selectedLevels.push(level);
        }
    } else {
        this.selectedLevels = this.selectedLevels.filter(l => l !== level);
    }

    this.updateFeeDisplay();
    this.validateLevelSelection();
},
```

- [ ] **Step 4: Add updateFeeDisplay method**

Add after handleLevelSelection:

```javascript
updateFeeDisplay: function() {
    const count = this.selectedLevels.length;
    const total = count * this.CONFIG.FEE_PER_LEVEL;

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

    this.totalAmount = total;
},
```

- [ ] **Step 5: Add validateLevelSelection method**

Add after updateFeeDisplay:

```javascript
validateLevelSelection: function() {
    const errorEl = document.getElementById('exam_levels-error');
    const successEl = document.getElementById('exam_levels-success');

    if (!errorEl || !successEl) return false;

    if (this.selectedLevels.length < this.CONFIG.MIN_LEVELS) {
        errorEl.textContent = `Please select at least ${this.CONFIG.MIN_LEVELS} level`;
        errorEl.classList.add('show');
        successEl.classList.remove('show');
        return false;
    } else {
        errorEl.classList.remove('show');
        successEl.textContent = `${this.selectedLevels.length} level(s) selected`;
        successEl.classList.add('show');
        return true;
    }
},
```

- [ ] **Step 6: Add confirmation modal methods**

Add these methods after validateLevelSelection:

```javascript
showLevelConfirmation: function() {
    if (!this.validateLevelSelection()) {
        return false;
    }

    const modal = document.getElementById('level_confirmation_modal');
    const countEl = document.getElementById('confirm_levels_count');
    const listEl = document.getElementById('confirm_levels_list');
    const totalEl = document.getElementById('confirm_total');

    countEl.textContent = this.selectedLevels.length;
    listEl.textContent = this.selectedLevels.sort().join(', ');
    totalEl.textContent = this.totalAmount.toLocaleString('en-BD');

    modal.classList.remove('hidden');
    return false; // Prevent automatic navigation
},

cancelLevelConfirmation: function() {
    document.getElementById('level_confirmation_modal').classList.add('hidden');
},

confirmLevelSelection: function() {
    document.getElementById('level_confirmation_modal').classList.add('hidden');

    // Update payment display
    const paymentAmount = document.getElementById('payment_amount_display');
    const paymentLevels = document.getElementById('payment_levels_display');

    if (paymentAmount) {
        paymentAmount.textContent = this.totalAmount.toLocaleString('en-BD') + ' BDT';
    }
    if (paymentLevels) {
        paymentLevels.textContent = `For ${this.selectedLevels.length} selected level(s)`;
    }

    // Proceed to next step (will call original nextStep logic)
    const currentStep = this.currentStep || 1;
    this.goToStep(currentStep + 1);
},
```

- [ ] **Step 7: Update nextStep method to show confirmation on step 3**

Find the existing nextStep method and modify it to show confirmation when going from step 3 to step 4:

```javascript
// Modify existing nextStep method
nextStep: function() {
    const currentStep = this.currentStep || 1;

    // Special handling for step 3 (Exam Details) -> step 4 (Documents) transition
    if (currentStep === 3) {
        return this.showLevelConfirmation();
    }

    // ... rest of existing nextStep logic ...
},
```

- [ ] **Step 8: Attach event listeners for confirmation modal buttons**

Add to the DOMContentLoaded event listener (around line 745):

```javascript
// Add confirmation modal button listeners
document.addEventListener('DOMContentLoaded', function() {
    // ... existing code ...

    // Confirmation modal buttons
    const cancelBtn = document.getElementById('cancel_confirm_btn');
    const confirmBtn = document.getElementById('confirm_selection_btn');

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            RegistrationForm.cancelLevelConfirmation();
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            RegistrationForm.confirmLevelSelection();
        });
    }

    // ... rest of existing code ...
});
```

- [ ] **Step 9: Update submitForm to send levels as array**

Find the submitForm method and update the form data submission:

```javascript
submitForm: function(event) {
    // ... existing validation ...

    // Prepare form data
    const formData = new FormData();

    // ... existing field additions ...

    // Add selected levels as array (will be converted to CSV by backend)
    if (this.selectedLevels && this.selectedLevels.length > 0) {
        this.selectedLevels.forEach(level => {
            formData.append('exam_levels[]', level);
        });
    }

    // Add total amount
    formData.append('total_amount', this.totalAmount);

    // ... rest of submission logic ...
},
```

- [ ] **Step 10: Commit JavaScript changes**

```bash
git add frontend/js/registration.js
git commit -m "feat: add multi-level selection logic with fee calculation and confirmation"
```

---

## Task 4: Create API Endpoint for Exam Levels

**Files:**
- Create: `frontend/intake/api/exam-dates/levels.php`

**Context:** New API endpoint to fetch available levels for a given exam date. Independent task, can be done in parallel with frontend changes.

- [ ] **Step 1: Create levels.php endpoint**

```php
<?php
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$testDate = $_GET['date'] ?? '';
if (empty($testDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $testDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing date parameter']);
    exit;
}

try {
    $conn = getDbConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $stmt = $conn->prepare("SELECT id FROM exam_dates WHERE exam_date = ?");
    $stmt->bind_param('s', $testDate);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['levels' => [], 'exam_date' => $testDate]);
        $stmt->close();
        $conn->close();
        exit;
    }

    $dateRow = $result->fetch_assoc();
    $examDateId = $dateRow['id'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT level FROM exam_levels WHERE exam_date_id = ? ORDER BY level");
    $stmt->bind_param('s', $examDateId);
    $stmt->execute();
    $levelsResult = $stmt->get_result();

    $levels = [];
    while ($row = $levelsResult->fetch_assoc()) {
        $levels[] = $row['level'];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'levels' => $levels,
        'exam_date' => $testDate,
        'count' => count($levels)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('Error in levels.php: ' . $e->getMessage());
}
```

- [ ] **Step 2: Commit endpoint**

```bash
git add frontend/intake/api/exam-dates/levels.php
git commit -m "feat: add API endpoint to fetch available levels for exam date"
```

---

## Task 5: Update Backend - Multi-Level Validation

**Files:**
- Modify: `frontend/intake/validate.php`

**Context:** Backend validation to accept exam_levels array and validate multi-level selections. Depends on Task 1 (database schema).

- [ ] **Step 1: Update exam_level validation in validate.php**

Replace existing exam_level validation with multi-level validation:

```php
// Validate exam_levels (array of selected levels)
if (!isset($postData['exam_levels']) || !is_array($postData['exam_levels'])) {
    $errors['exam_levels'] = 'Please select at least one exam level';
} else {
    $levels = $postData['exam_levels'];

    // Remove any empty values
    $levels = array_filter($levels, function($level) {
        return !empty(trim($level));
    });

    // Re-index array
    $levels = array_values($levels);

    // Validate minimum 1 level
    if (count($levels) < 1) {
        $errors['exam_levels'] = 'Please select at least one exam level';
    }

    // Validate maximum 5 levels
    if (count($levels) > 5) {
        $errors['exam_levels'] = 'Cannot select more than 5 levels';
    }

    // Validate each level value
    $validLevels = ['1Q', '2Q', '3Q', '4Q', '5Q'];
    foreach ($levels as $level) {
        $level = trim($level);
        if (!in_array($level, $validLevels, true)) {
            $errors['exam_levels'] = "Invalid level selected: $level";
            break;
        }
    }

    // Convert to comma-separated string for storage
    if (!isset($errors['exam_levels'])) {
        $data['exam_level'] = implode(',', $levels);
        $data['exam_levels_array'] = $levels;
    }
}

// Validate total_amount
if (!isset($postData['total_amount'])) {
    $errors['total_amount'] = 'Total amount is required';
} else {
    $amount = intval($postData['total_amount']);
    $levelCount = isset($data['exam_levels_array']) ? count($data['exam_levels_array']) : 0;
    $expectedAmount = $levelCount * 4000;

    if ($levelCount > 0 && $amount !== $expectedAmount) {
        $errors['total_amount'] = "Amount mismatch. Expected: $expectedAmount, Got: $amount";
    }

    if ($amount <= 0) {
        $errors['total_amount'] = 'Total amount must be greater than zero';
    }

    if (!isset($errors['total_amount'])) {
        $data['total_amount'] = $amount;
    }
}
```

- [ ] **Step 2: Commit validation changes**

```bash
git add frontend/intake/validate.php
git commit -m "feat: add multi-level validation with amount verification"
```

---

## Task 6: Update Backend - Database Storage

**Files:**
- Modify: `frontend/intake/register.php`

**Context:** Update database INSERT to include total_amount field. Depends on Task 1 (migration) and Task 5 (validation).

- [ ] **Step 1: Update INSERT statement to include total_amount**

```php
// Find existing INSERT and update to include total_amount
$stmt = $conn->prepare("
    INSERT INTO registrations (
        id, full_name, email, mobile, address, dob, gender, nationality,
        payment_method, exam_level, total_amount, test_date,
        photo_filename, photo_storage_path, photo_size_bytes,
        id_filename, id_storage_path, id_size_bytes,
        payment_receipt_filename, payment_receipt_storage_path, payment_receipt_size_bytes,
        ip_hash, user_agent, honeypot_tripped, honeypot_value
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
```

- [ ] **Step 2: Update bind_param to include total_amount**

```php
$stmt->bind_param(
    'sssssssssssisississis',  // Added one 's' for total_amount
    $id,
    $data['full_name'],
    $data['email'],
    $data['mobile'],
    $data['address'],
    $data['dob'],
    $data['gender'],
    $data['nationality'],
    $data['payment_method'],
    $data['exam_level'],
    $data['total_amount'],  // NEW
    $data['test_date'],
    $photo['storage_path'],
    $p_size,
    $idDoc['storage_path'],
    $id_size,
    $r_name,
    $r_path,
    $r_size,
    $ipHash,
    $userAgent,
    $hp_tripped,
    $honeypotCheck['value']
);
```

- [ ] **Step 3: Update success response**

```php
$levelsArray = isset($data['exam_levels_array']) ? $data['exam_levels_array'] : [$data['exam_level']];
successResponse([
    'id' => $id,
    'email' => $data['email'],
    'exam_level' => $data['exam_level'],
    'exam_levels' => $levelsArray,
    'level_count' => count($levelsArray),
    'total_amount' => $data['total_amount'],
    'test_date' => $data['test_date']
], 'Registration submitted successfully');
```

- [ ] **Step 4: Commit backend changes**

```bash
git add frontend/intake/register.php
git commit -m "feat: store total_amount and return multi-level data in response"
```

---

## Task 7: Update Admin Panel - Registration Display

**Files:**
- Modify: `frontend/admin/pages/registrations.php`

**Context:** Update admin registration list to display multi-level data. Depends on Task 6 (backend storage complete).

- [ ] **Step 1: Update registration table to show multi-level info**

```php
<td>
    <div class="font-semibold"><?php echo htmlspecialchars($row['exam_level']); ?></div>
    <div class="text-sm text-secondary">
        <?php
        $levelCount = count(explode(',', $row['exam_level']));
        echo number_format($row['total_amount']) . ' BDT (' . $levelCount . ' level(s))';
        ?>
    </div>
</td>
```

- [ ] **Step 2: Commit display changes**

```bash
git add frontend/admin/pages/registrations.php
git commit -m "feat: show multi-level registrations with total amounts"
```

---

## Task 8: Update Admin Panel - Revenue Calculation

**Files:**
- Modify: `frontend/admin/pages/dashboard.php`

**Context:** Update revenue calculation to use SUM(total_amount) instead of COUNT × 4000. Depends on Task 6 (backend storage).

- [ ] **Step 1: Find and update revenue calculation**

```php
$stmt = $conn->prepare("SELECT SUM(total_amount) as total FROM registrations WHERE approved = 1");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$totalRevenue = $row['total'] ?? 0;
```

- [ ] **Step 2: Commit dashboard changes**

```bash
git add frontend/admin/pages/dashboard.php
git commit -m "feat: calculate revenue from total_amount field"
```

---

## Task 9: Update Admin Panel - Level Filtering

**Files:**
- Modify: `frontend/admin/api/registrations/list.php`

**Context:** Update level filter to search within CSV data. Independent change, can be done anytime.

- [ ] **Step 1: Update filter query**

```php
$levelFilter = $_GET['exam_level'] ?? '';
if (!empty($levelFilter)) {
    $sql .= " AND exam_level LIKE ?";
    $params[] = "%$levelFilter%";
    $types .= 's';
}
```

- [ ] **Step 2: Commit filter changes**

```bash
git add frontend/admin/api/registrations/list.php
git commit -m "feat: update level filter to work with multi-level CSV data"
```

---

## Task 10: Update Admin Panel - Export Functionality

**Files:**
- Modify: `frontend/admin/api/registrations/export.php`

**Context:** Add total_amount column to CSV export. Independent change.

- [ ] **Step 1: Add total_amount to export**

```php
// Add to header row
fputcsv($output, [
    'ID',
    'Full Name',
    'Email',
    'Mobile',
    'Exam Levels',
    'Total Amount (BDT)',
    // ...
]);

// Add to data rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['id'],
        $row['full_name'],
        $row['email'],
        $row['mobile'],
        $row['exam_level'],
        $row['total_amount'],
        // ...
    ]);
}
```

- [ ] **Step 2: Commit export changes**

```bash
git add frontend/admin/api/registrations/export.php
git commit -m "feat: add total_amount to registration export CSV"
```

---

## Task 11-19: Testing and Deployment

(Manual testing and deployment tasks - not automated through subagents)
