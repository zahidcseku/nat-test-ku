<?php
/**
 * Dashboard Page
 * Main admin dashboard with statistics and recent activity
 */

require_once __DIR__ . '/auth/middleware.php';

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

$conn = getDbConnection();

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'revenue' => 0
];

if ($conn) {
    // Total registrations
    $result = $conn->query("SELECT COUNT(*) as count FROM registrations");
    $stats['total'] = $result->fetch_assoc()['count'];

    // Pending registrations (not yet reviewed - approved is NULL or 0)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM registrations WHERE approved IS NULL OR approved = 0");
    $stmt->execute();
    $stats['pending'] = $stmt->get_result()->fetch_assoc()['count'];

    // Approved registrations
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM registrations WHERE approved = 1");
    $stmt->execute();
    $stats['approved'] = $stmt->get_result()->fetch_assoc()['count'];

    // Rejected registrations (for now, same as pending since we need to add rejection tracking)
    $stats['rejected'] = 0; // Will need to add rejection tracking to schema

    // Revenue (BDT 4,000 per approved registration)
    $stats['revenue'] = $stats['approved'] * 4000;

    // Recent activity (last 10 actions)
    $stmt = $conn->prepare("
        SELECT
            al.*,
            au.username
        FROM audit_log al
        LEFT JOIN admin_users au ON al.user_id = au.id
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recentActivity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Calculate approval rate
$approvalRate = $stats['total'] > 0
    ? round(($stats['approved'] / $stats['total']) * 100, 1)
    : 0;

require_once __DIR__ . '/templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Welcome back, <?php echo e($_SESSION['username']); ?>!</p>
</div>

<!-- Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; margin-bottom: 32px;">
    <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
        <div style="font-size: 14px; color: #718096; margin-bottom: 8px;">Total Registrations</div>
        <div style="font-size: 36px; font-weight: 700; color: #1a202c;"><?php echo number_format($stats['total']); ?></div>
    </div>

    <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
        <div style="font-size: 14px; color: #718096; margin-bottom: 8px;">Pending Review</div>
        <div style="font-size: 36px; font-weight: 700; color: #ed8936;"><?php echo number_format($stats['pending']); ?></div>
    </div>

    <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
        <div style="font-size: 14px; color: #718096; margin-bottom: 8px;">Approved</div>
        <div style="font-size: 36px; font-weight: 700; color: #48bb78;"><?php echo number_format($stats['approved']); ?></div>
    </div>

    <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
        <div style="font-size: 14px; color: #718096; margin-bottom: 8px;">Approval Rate</div>
        <div style="font-size: 36px; font-weight: 700; color: #667eea;"><?php echo $approvalRate; ?>%</div>
    </div>

    <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
        <div style="font-size: 14px; color: #718096; margin-bottom: 8px;">Total Revenue</div>
        <div style="font-size: 36px; font-weight: 700; color: #1a202c;"><?php echo formatCurrency($stats['revenue']); ?></div>
    </div>
</div>

<!-- Quick Actions -->
<div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0; margin-bottom: 32px;">
    <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px;">Quick Actions</h2>
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="<?php echo BASE_URL; ?>/pages/registrations.php?status=pending" class="btn btn-primary">
            Review Pending Registrations
        </a>
        <a href="<?php echo BASE_URL; ?>/pages/exam-dates.php" class="btn btn-secondary">
            Manage Exam Dates
        </a>
        <a href="<?php echo BASE_URL; ?>/api/registrations/export.php" class="btn btn-secondary">
            Export All Registrations
        </a>
    </div>
</div>

<!-- Recent Activity -->
<div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid #e2e8f0;">
    <h2 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 16px;">Recent Activity</h2>

    <?php if (!empty($recentActivity)): ?>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <?php foreach ($recentActivity as $activity): ?>
                <div style="padding: 12px; background: #f7fafc; border-radius: 8px; border-left: 3px solid #667eea;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 4px;">
                        <div style="font-weight: 500; color: #1a202c;">
                            <?php echo e(ucfirst(str_replace('_', ' ', $activity['action']))); ?>
                        </div>
                        <div style="font-size: 12px; color: #718096;">
                            <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                        </div>
                    </div>
                    <div style="font-size: 13px; color: #718096;">
                        By <?php echo e($activity['username'] ?? 'System'); ?>
                        <?php if ($activity['table_name']): ?>
                            on <?php echo e($activity['table_name']); ?>
                            <?php if ($activity['record_id']): ?>
                            #<?php echo e($activity['record_id']); ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color: #718096; font-size: 14px;">No recent activity</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
