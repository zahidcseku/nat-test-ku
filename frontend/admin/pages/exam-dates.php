<?php
/**
 * Exam Dates Management Page
 * Add, edit, delete exam dates and assign levels
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Exam Dates';
$currentPage = 'exam-dates';

$conn = getDbConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('Invalid CSRF token', 'error');
    } elseif ($action === 'create' || $action === 'update') {
        $examId = $_POST['exam_id'] ?? null;
        $examDate = $_POST['exam_date'] ?? '';
        $deadline = $_POST['registration_deadline'] ?? '';
        $levels = $_POST['levels'] ?? [];
        // Per-level caps: associative array keyed by level name. Empty/absent
        // means "unlimited" (NULL).
        $levelCaps = $_POST['level_caps'] ?? [];

        // Validate
        if (empty($examDate) || empty($deadline) || empty($levels)) {
            setFlashMessage('Please fill in all required fields', 'error');
        } elseif (strtotime($deadline) >= strtotime($examDate)) {
            setFlashMessage('Registration deadline must be before exam date', 'error');
        } else {
            // Normalize caps: only keep caps for selected levels; cast to
            // int or null. Non-numeric / empty strings become null.
            $normalizedCaps = [];
            foreach ($levels as $level) {
                $raw = $levelCaps[$level] ?? '';
                if (is_string($raw)) {
                    $raw = trim($raw);
                }
                if ($raw === '' || !is_numeric($raw)) {
                    $normalizedCaps[$level] = null;
                } else {
                    $cap = (int)$raw;
                    $normalizedCaps[$level] = ($cap > 0) ? $cap : null;
                }
            }

            if ($action === 'create') {
                // Check for duplicate
                $stmt = $conn->prepare("SELECT id FROM exam_dates WHERE exam_date = ?");
                $stmt->bind_param('s', $examDate);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    setFlashMessage('Exam date already exists', 'error');
                } else {
                    // Insert exam date (id is a CHAR(36) UUID, app-generated)
                    $examId = generateFileUuid();
                    $stmt = $conn->prepare("INSERT INTO exam_dates (id, exam_date, registration_deadline) VALUES (?, ?, ?)");
                    $stmt->bind_param('sss', $examId, $examDate, $deadline);
                    $stmt->execute();

                    // Insert levels with their caps
                    $stmt = $conn->prepare("INSERT INTO exam_levels (exam_date_id, level, registration_cap) VALUES (?, ?, ?)");
                    foreach ($levels as $level) {
                        $cap = $normalizedCaps[$level];
                        // bind_param accepts null for any type and stores NULL.
                        $stmt->bind_param('ssi', $examId, $level, $cap);
                        $stmt->execute();
                    }

                    logAudit('create_exam_date', 'exam_dates', $examId, null, ['exam_date' => $examDate, 'levels' => $levels, 'caps' => $normalizedCaps]);
                    setFlashMessage('Exam date created successfully', 'success');
                }
            } else {
                // Update exam date
                $stmt = $conn->prepare("UPDATE exam_dates SET exam_date = ?, registration_deadline = ? WHERE id = ?");
                $stmt->bind_param('sss', $examDate, $deadline, $examId);
                $stmt->execute();

                // Get old levels (with caps) for audit
                $stmt = $conn->prepare("SELECT level, registration_cap FROM exam_levels WHERE exam_date_id = ?");
                $stmt->bind_param('s', $examId);
                $stmt->execute();
                $oldLevels = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $oldLevelMap = [];
                foreach ($oldLevels as $ol) {
                    $oldLevelMap[$ol['level']] = $ol['registration_cap'];
                }

                // Delete old levels and insert new ones (with caps)
                $stmt = $conn->prepare("DELETE FROM exam_levels WHERE exam_date_id = ?");
                $stmt->bind_param('s', $examId);
                $stmt->execute();

                $stmt = $conn->prepare("INSERT INTO exam_levels (exam_date_id, level, registration_cap) VALUES (?, ?, ?)");
                foreach ($levels as $level) {
                    $cap = $normalizedCaps[$level];
                    $stmt->bind_param('ssi', $examId, $level, $cap);
                    $stmt->execute();
                }

                logAudit('update_exam_date', 'exam_dates', $examId,
                    ['levels' => $oldLevelMap],
                    ['exam_date' => $examDate, 'levels' => $normalizedCaps]
                );
                setFlashMessage('Exam date updated successfully', 'success');
            }

            header('Location: ' . BASE_URL . '/pages/exam-dates.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $examId = $_POST['exam_id'] ?? '';

        // Check if any registrations exist for this exam
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM registrations WHERE test_date = (SELECT exam_date FROM exam_dates WHERE id = ?)");
        $stmt->bind_param('s', $examId);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['count'];

        if ($count > 0) {
            setFlashMessage('Cannot delete exam date with existing registrations', 'error');
        } else {
            // Get exam details for audit
            $stmt = $conn->prepare("SELECT exam_date FROM exam_dates WHERE id = ?");
            $stmt->bind_param('s', $examId);
            $stmt->execute();
            $exam = $stmt->get_result()->fetch_assoc();

            // Delete levels first
            $stmt = $conn->prepare("DELETE FROM exam_levels WHERE exam_date_id = ?");
            $stmt->bind_param('s', $examId);
            $stmt->execute();

            // Delete exam date
            $stmt = $conn->prepare("DELETE FROM exam_dates WHERE id = ?");
            $stmt->bind_param('s', $examId);
            $stmt->execute();

            logAudit('delete_exam_date', 'exam_dates', $examId, $exam);
            setFlashMessage('Exam date deleted successfully', 'success');
        }

        header('Location: ' . BASE_URL . '/pages/exam-dates.php');
        exit;
    }
}

// Get all exam dates with levels and per-level caps.
// GROUP_CONCAT builds 'LEVEL:CAP|LEVEL:CAP|...' with empty CAP when NULL;
// we split that into a [level => cap] map in PHP.
$stmt = $conn->prepare("
    SELECT
        ed.*,
        GROUP_CONCAT(CONCAT(el.level, ':', COALESCE(el.registration_cap, '')) ORDER BY el.level SEPARATOR '|') as levels_with_caps
    FROM exam_dates ed
    LEFT JOIN exam_levels el ON ed.id = el.exam_date_id
    GROUP BY ed.id
    ORDER BY ed.exam_date ASC
");
$stmt->execute();
$examDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Parse the level:cap pairs into a [level => cap] map per exam, and
// pre-fetch paid counts per (date) so the list view can render live
// fill counts alongside each level.
foreach ($examDates as &$examRow) {
    $levelCapMap = [];
    $levelList = [];
    if (!empty($examRow['levels_with_caps'])) {
        foreach (explode('|', $examRow['levels_with_caps']) as $pair) {
            $parts = explode(':', $pair, 2);
            $lvl = $parts[0];
            $cap = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : null;
            $levelCapMap[$lvl] = $cap;
            $levelList[] = $lvl;
        }
    }
    $examRow['levels'] = $levelList;
    $examRow['level_caps'] = $levelCapMap;
    $examRow['paid_by_level'] = countPaidByLevel($conn, $examRow['exam_date']);
}
unset($examRow);

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Exam Dates</h1>
    <p class="page-subtitle">Manage examination dates and available levels</p>
</div>

<!-- Add New Exam Date Button -->
<div style="margin-bottom: 24px;">
    <button onclick="showModal()" class="btn btn-primary" style="padding: 12px 24px; font-size: 15px;">
        + Add New Exam Date
    </button>
</div>

<!-- Exam Dates Table -->
<?php if (!empty($examDates)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Exam Date</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Registration Deadline</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Levels</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($examDates as $exam): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 16px;">
                            <div style="font-size: 15px; font-weight: 500; color: #1a202c;">
                                <?php echo e(formatDate($exam['exam_date'])); ?>
                            </div>
                        </td>
                        <td style="padding: 16px;">
                            <div style="font-size: 14px; color: #4a5568;">
                                <?php echo e(formatDate($exam['registration_deadline'])); ?>
                            </div>
                        </td>
                        <td style="padding: 16px;">
                            <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                <?php
                                $levels = $exam['levels'];
                                $levelCaps = $exam['level_caps'];
                                $paidByLevel = $exam['paid_by_level'];
                                foreach ($levels as $level):
                                    $cap = $levelCaps[$level] ?? null;
                                    $paid = $paidByLevel[$level] ?? 0;
                                    if ($cap === null) {
                                        $label = e($level) . ' <span style="font-weight:400;color:#718096;">' . $paid . '/∞</span>';
                                        $bg = '#edf2f7'; $color = '#2d3748';
                                    } elseif ($paid >= $cap) {
                                        $label = e($level) . ' <span style="font-weight:400;">' . $paid . '/' . $cap . ' (full)</span>';
                                        $bg = '#fed7d7'; $color = '#822727';
                                    } else {
                                        $label = e($level) . ' <span style="font-weight:400;color:#718096;">' . $paid . '/' . $cap . '</span>';
                                        $bg = '#edf2f7'; $color = '#2d3748';
                                    }
                                ?>
                                    <span style="display: inline-block; padding: 4px 8px; background: <?php echo $bg; ?>; border-radius: 4px; font-size: 12px; font-weight: 600; color: <?php echo $color; ?>;">
                                        <?php echo $label; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td style="padding: 16px; text-align: center;">
                            <button onclick='editExam(<?php echo json_encode([
                                "id" => $exam["id"],
                                "exam_date" => $exam["exam_date"],
                                "registration_deadline" => $exam["registration_deadline"],
                                "level_caps" => $exam["level_caps"]
                            ]); ?>)'
                                    class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                Edit
                            </button>
                            <button onclick="deleteExam('<?php echo e($exam['id']); ?>', '<?php echo e(formatDate($exam['exam_date'])); ?>')"
                                    class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;">
                                Delete
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
        <div style="font-size: 48px; margin-bottom: 16px;">📅</div>
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Exam Dates</h3>
        <p style="color: #718096; font-size: 14px;">Click "Add New Exam Date" to create one.</p>
    </div>
<?php endif; ?>

<!-- Modal Form -->
<div id="examModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 32px; width: 100%; max-width: 500px; margin: 20px;">
        <h2 id="modalTitle" style="font-size: 20px; font-weight: 700; color: #1a202c; margin-bottom: 24px;">
            Add New Exam Date
        </h2>

        <form id="examForm" method="POST" style="display: flex; flex-direction: column; gap: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="exam_id" value="">

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam Date *</label>
                <input type="date" name="exam_date" id="exam_date" required
                       style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Registration Deadline *</label>
                <input type="date" name="registration_deadline" id="registration_deadline" required
                       style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Available Levels (select at least one) *</label>
                <p style="font-size: 12px; color: #718096; margin: 0 0 8px 0;">Leave cap blank for unlimited. Cap counts only paid registrations.</p>
                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px;">
                    <?php foreach (['1Q/N1', '2Q/N2', '3Q/N3', '4Q/N4', '5Q/N5'] as $level): ?>
                        <div data-level-cell="<?php echo e($level); ?>" style="display: flex; flex-direction: column; gap: 6px; padding: 8px; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" name="levels[]" value="<?php echo e($level); ?>" id="level_<?php echo e($level); ?>" data-level-checkbox onchange="toggleCapInput(this)">
                                <span style="font-size: 13px; font-weight: 500;"><?php echo e($level); ?></span>
                            </label>
                            <input type="number" name="level_caps[<?php echo e($level); ?>]" min="1" step="1" placeholder="∞"
                                   style="width: 100%; padding: 4px 6px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px;" disabled>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: flex; gap: 12px; padding-top: 16px; border-top: 1px solid #e2e8f0; margin-top: 8px;">
                <button type="submit" class="btn btn-primary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                    Save Exam Date
                </button>
                <button type="button" onclick="hideModal()" class="btn btn-secondary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 32px; width: 100%; max-width: 400px; margin: 20px;">
        <h2 style="font-size: 20px; font-weight: 700; color: #1a202c; margin-bottom: 16px;">Delete Exam Date</h2>
        <p style="color: #4a5568; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
            Are you sure you want to delete <strong id="deleteExamDate"></strong>?<br><br>
            This action cannot be undone.
        </p>
        <form id="deleteForm" method="POST" style="display: flex; gap: 12px;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="exam_id" id="delete_exam_id" value="">

            <button type="submit" class="btn btn-danger" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Delete
            </button>
            <button type="button" onclick="hideDeleteModal()" class="btn btn-secondary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Cancel
            </button>
        </form>
    </div>
</div>

<script>
function showModal() {
    document.getElementById('examModal').style.display = 'flex';
    document.getElementById('modalTitle').textContent = 'Add New Exam Date';
    document.getElementById('examForm').elements['action'].value = 'create';
    document.getElementById('examForm').exam_id.value = '';
    document.getElementById('examForm').reset();
    // Reset all cap inputs to disabled + empty after the reset().
    document.querySelectorAll('input[name^="level_caps["]').forEach(inp => {
        inp.value = '';
        inp.disabled = true;
    });
}

function hideModal() {
    document.getElementById('examModal').style.display = 'none';
}

// Enable/disable the cap input based on its sibling checkbox state.
function toggleCapInput(checkbox) {
    const level = checkbox.value;
    const inputs = document.querySelectorAll('input[name="level_caps[' + level + ']"]');
    inputs.forEach(inp => { inp.disabled = !checkbox.checked; });
}

// Accepts the parsed exam row from PHP (id, exam_date, registration_deadline,
// level_caps as a {level: cap|null} object).
function editExam(exam) {
    document.getElementById('examModal').style.display = 'flex';
    document.getElementById('modalTitle').textContent = 'Edit Exam Date';
    document.getElementById('examForm').elements['action'].value = 'update';
    document.getElementById('examForm').exam_id.value = exam.id;
    document.getElementById('exam_date').value = exam.exam_date;
    document.getElementById('registration_deadline').value = exam.registration_deadline;

    // Uncheck everything + clear cap inputs first.
    document.querySelectorAll('input[name="levels[]"]').forEach(cb => {
        cb.checked = false;
        toggleCapInput(cb);
    });
    document.querySelectorAll('input[name^="level_caps["]').forEach(inp => {
        inp.value = '';
    });

    // Check + populate cap for each saved level.
    const caps = exam.level_caps || {};
    Object.keys(caps).forEach(level => {
        const cb = document.getElementById('level_' + level);
        if (cb) {
            cb.checked = true;
            toggleCapInput(cb);
        }
        const capInput = document.querySelector('input[name="level_caps[' + level + ']"]');
        if (capInput && caps[level] !== null && caps[level] !== undefined) {
            capInput.value = caps[level];
        }
    });
}

function deleteExam(id, examDate) {
    document.getElementById('deleteModal').style.display = 'flex';
    document.getElementById('deleteExamDate').textContent = examDate;
    document.getElementById('delete_exam_id').value = id;
}

function hideDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Close modals on outside click
document.getElementById('examModal').addEventListener('click', function(e) {
    if (e.target === this) hideModal();
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) hideDeleteModal();
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
