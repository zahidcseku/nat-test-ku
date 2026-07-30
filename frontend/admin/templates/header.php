<?php
if (!defined('ADMIN_ACCESS')) {
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle ?? 'Admin Dashboard'); ?> | NAT-TEST Admin</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f7fafc;
            color: #1a202c;
        }

        .header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 24px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
        }

        .logo span {
            color: #667eea;
        }

        .nav {
            display: flex;
            gap: 4px;
        }

        .nav a {
            padding: 8px 16px;
            text-decoration: none;
            color: #718096;
            font-size: 14px;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .nav a:hover {
            background: #f7fafc;
            color: #2d3748;
        }

        .nav a.active {
            background: #edf2f7;
            color: #2d3748;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: #1a202c;
        }

        .user-role {
            font-size: 12px;
            color: #718096;
        }

        .btn-logout {
            padding: 8px 16px;
            background: #edf2f7;
            color: #4a5568;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-logout:hover {
            background: #e2e8f0;
        }

        .main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 24px;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .page-subtitle {
            font-size: 14px;
            color: #718096;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .alert-success {
            background: #c6f6d5;
            color: #276749;
            border: 1px solid #68d391;
        }

        .alert-error {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #fc8181;
        }

        .alert-info {
            background: #bee3f8;
            color: #2c5282;
            border: 1px solid #63b3ed;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
        }

        .btn-secondary {
            background: #edf2f7;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .btn-danger {
            background: #fc8181;
            color: white;
        }

        .btn-danger:hover {
            background: #f56565;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">🎓 NAT-TEST <span>Admin</span></div>

        <nav class="nav">
            <a href="<?php echo BASE_URL; ?>/dashboard.php" <?php echo ($currentPage ?? '') === 'dashboard' ? 'class="active"' : ''; ?>>Dashboard</a>
            <a href="<?php echo BASE_URL; ?>/pages/registrations.php" <?php echo ($currentPage ?? '') === 'registrations' ? 'class="active"' : ''; ?>>Registrations</a>
            <a href="<?php echo BASE_URL; ?>/pages/exam-dates.php" <?php echo ($currentPage ?? '') === 'exam-dates' ? 'class="active"' : ''; ?>>Exam Dates</a>
            <a href="<?php echo BASE_URL; ?>/pages/participants.php" <?php echo ($currentPage ?? '') === 'participants' ? 'class="active"' : ''; ?>>Participants</a>
            <a href="<?php echo BASE_URL; ?>/pages/admission-tickets.php" <?php echo ($currentPage ?? '') === 'admission-tickets' ? 'class="active"' : ''; ?>>Tickets</a>
            <a href="<?php echo BASE_URL; ?>/pages/scores.php" <?php echo ($currentPage ?? '') === 'scores' ? 'class="active"' : ''; ?>>Scores</a>
            <a href="<?php echo BASE_URL; ?>/pages/certificate-requests.php" <?php echo ($currentPage ?? '') === 'certificate-requests' ? 'class="active"' : ''; ?>>Certificates</a>
            <a href="<?php echo BASE_URL; ?>/pages/emails.php" <?php echo ($currentPage ?? '') === 'emails' ? 'class="active"' : ''; ?>>Emails</a>
            <a href="<?php echo BASE_URL; ?>/pages/broadcast-email.php" <?php echo ($currentPage ?? '') === 'broadcast-email' ? 'class="active"' : ''; ?>>Broadcast</a>
            <a href="<?php echo BASE_URL; ?>/pages/email-templates.php" <?php echo ($currentPage ?? '') === 'email-templates' ? 'class="active"' : ''; ?>>Templates</a>
            <a href="<?php echo BASE_URL; ?>/pages/content.php" <?php echo ($currentPage ?? '') === 'content' ? 'class="active"' : ''; ?>>Content</a>
            <?php if (isSuperAdmin()): ?>
            <a href="<?php echo BASE_URL; ?>/pages/users.php" <?php echo ($currentPage ?? '') === 'users' ? 'class="active"' : ''; ?>>Users</a>
            <?php endif; ?>
        </nav>

        <div class="user-menu">
            <div class="user-info">
                <div class="user-name"><?php echo e($_SESSION['username'] ?? ''); ?></div>
                <div class="user-role"><?php echo e(ucfirst($_SESSION['role'] ?? '')); ?></div>
            </div>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="btn-logout">Logout</a>
        </div>
    </header>

    <main class="main">
        <?php
        $flash = getFlashMessage();
        if ($flash):
        ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>
