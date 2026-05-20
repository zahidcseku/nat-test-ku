<?php
/**
 * Content Management Page
 * Placeholder - links to existing Streamlit admin for now
 */

require_once __DIR__ . '/../auth/middleware.php';

$pageTitle = 'Content Management';
$currentPage = 'content';

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Content Management</h1>
    <p class="page-subtitle">Manage website content and resources</p>
</div>

<div style="background: white; border-radius: 12px; padding: 48px; text-align: center; border: 1px solid #e2e8f0;">
    <div style="font-size: 48px; margin-bottom: 16px;">📝</div>
    <h2 style="font-size: 20px; font-weight: 600; color: #1a202c; margin-bottom: 16px;">Content Editor</h2>
    <p style="color: #4a5568; font-size: 15px; line-height: 1.6; margin-bottom: 24px; max-width: 500px; margin-left: auto; margin-right: auto;">
        The content management system is currently available via the Streamlit admin interface.
    </p>
    <div style="background: #f7fafc; border-radius: 8px; padding: 20px; max-width: 600px; margin-left: auto; margin-right: auto;">
        <h3 style="font-size: 14px; font-weight: 600; color: #1a202c; margin-bottom: 12px;">To Access Content Editor:</h3>
        <ol style="color: #4a5568; font-size: 14px; line-height: 1.8; padding-left: 20px;">
            <li>Navigate to the local admin directory: <code style="background: white; padding: 2px 6px; border-radius: 4px;">cd /Users/zahid/projects/NAT_TEST_KU/admin</code></li>
            <li>Run the Streamlit admin: <code style="background: white; padding: 2px 6px; border-radius: 4px;">streamlit run home.py</code></li>
            <li>Open your browser to: <code style="background: white; padding: 2px 6px; border-radius: 4px;">http://localhost:8501</code></li>
        </ol>
    </div>
    <p style="color: #718096; font-size: 13px; margin-top: 24px;">
        Note: A web-based content editor will be added to this admin panel in future updates.
    </p>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
