<?php
/**
 * Participants View Page
 * View approved registrations only
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Participants';
$currentPage = 'participants';

$conn = getDbConnection();

// Get filter parameters
$examDate = $_GET['exam_date'] ?? '';
$examLevel = $_GET['exam_level'] ?? '';

// Build query
$where = ['r.approved = 1'];
$params = [];
$types = '';

if (!empty($examDate)) {
    $where[] = 'r.test_date = ?';
    $params[] = $examDate;
    $types .= 's';
}

if (!empty($examLevel)) {
    $where[] = 'r.exam_level = ?';
    $params[] = $examLevel;
    $types .= 's';
}

$whereClause = implode(' AND ', $where);

// Get approved registrations
$query = "
    SELECT r.*
    FROM registrations r
    WHERE $whereClause
    ORDER BY r.test_date ASC, r.full_name ASC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get exam dates for filter
$stmt = $conn->prepare("
    SELECT DISTINCT ed.exam_date
    FROM exam_dates ed
    INNER JOIN registrations r ON r.test_date = ed.exam_date
    WHERE r.approved = 1
    ORDER BY ed.exam_date ASC
");
$stmt->execute();
$examDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Statistics
$totalParticipants = count($participants);
$totalRevenue = $totalParticipants * 4000;

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Participants</h1>
    <p class="page-subtitle">Approved registrations and admission tickets</p>
</div>

<!-- Statistics -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 13px; color: #718096; margin-bottom: 4px;">Total Participants</div>
        <div style="font-size: 32px; font-weight: 700; color: #1a202c;"><?php echo number_format($totalParticipants); ?></div>
    </div>
    <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 13px; color: #718096; margin-bottom: 4px;">Total Revenue</div>
        <div style="font-size: 32px; font-weight: 700; color: #1a202c;"><?php echo formatCurrency($totalRevenue); ?></div>
    </div>
</div>

<!-- Filters -->
<div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam Date</label>
            <select name="exam_date" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">All Dates</option>
                <?php foreach ($examDates as $date): ?>
                    <option value="<?php echo e($date['exam_date']); ?>" <?php echo $examDate === $date['exam_date'] ? 'selected' : ''; ?>>
                        <?php echo e(formatDate($date['exam_date'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Exam Level</label>
            <select name="exam_level" style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                <option value="">All Levels</option>
                <?php foreach (['1Q', '2Q', '3Q', '4Q', '5Q'] as $level): ?>
                    <option value="<?php echo e($level); ?>" <?php echo $examLevel === $level ? 'selected' : ''; ?>>
                        <?php echo e($level); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display: flex; align-items: end; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="flex: 1;">Apply Filters</button>
            <a href="<?php echo BASE_URL; ?>/api/registrations/export.php?status=approved" class="btn btn-secondary">
                📥 Export
            </a>
        </div>
    </form>
</div>

<!-- Participants Table -->
<?php if (!empty($participants)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">ID</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Name</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Email</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Mobile</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Level</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Test Date</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Approved</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Ticket</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $participant): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 16px; font-size: 14px;">#<?php echo e($participant['id']); ?></td>
                        <td style="padding: 12px 16px; font-size: 14px; font-weight: 500;">
                            <?php echo e($participant['full_name']); ?>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;">
                            <a href="mailto:<?php echo e($participant['email']); ?>" style="color: #667eea;">
                                <?php echo e($participant['email']); ?>
                            </a>
                        </td>
                        <td style="padding: 12px 16px; font-size: 14px;"><?php echo e($participant['mobile']); ?></td>
                        <td style="padding: 12px 16px; font-size: 14px;"><?php echo e($participant['exam_level']); ?></td>
                        <td style="padding: 12px 16px; font-size: 14px;"><?php echo e(formatDate($participant['test_date'])); ?></td>
                        <td style="padding: 12px 16px; font-size: 14px; color: #718096;">
                            <?php echo e(date('M j, Y', strtotime($participant['approved_at']))); ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <?php if (!empty($participant['admission_ticket_sent'])): ?>
                                <span style="color: #48bb78; font-size: 13px;">✓ Sent</span>
                            <?php else: ?>
                                <span style="color: #ed8936; font-size: 13px;">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
        <div style="font-size: 48px; margin-bottom: 16px;">👥</div>
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Participants Found</h3>
        <p style="color: #718096; font-size: 14px;">No approved registrations match your filters.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
