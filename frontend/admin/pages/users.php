<?php
/**
 * User Management Page
 * Add/remove admin users (super admin only)
 */

require_once __DIR__ . '/../auth/middleware.php';

// Check if super admin
if (!isSuperAdmin()) {
    setFlashMessage('Access denied. Super admin only.', 'error');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$pageTitle = 'User Management';
$currentPage = 'users';

$conn = getDbConnection();

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token';
    } elseif ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'admin';

        // Validate
        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $error = 'Username must be 3-50 characters (letters, numbers, underscore only)';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address';
        } elseif (!isStrongPassword($password)) {
            $error = 'Password too weak. Requirements: 8+ chars, uppercase, lowercase, number';
        } elseif (!in_array($role, ['admin', 'super_admin'])) {
            $error = 'Invalid role';
        } else {
            // Check if username exists
            $stmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Username already exists';
            } else {
                // Create user
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, email, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $username, $passwordHash, $email, $role);

                if ($stmt->execute()) {
                    logAudit('create_user', 'admin_users', $conn->insert_id, null, compact('username', 'email', 'role'));
                    $success = 'User created successfully';
                } else {
                    $error = 'Failed to create user';
                }
            }
        }
    } elseif ($action === 'delete') {
        $userId = $_POST['user_id'] ?? 0;

        // Can't delete yourself
        if ($userId == $_SESSION['user_id']) {
            $error = 'Cannot delete your own account';
        } else {
            // Get user details for audit
            $stmt = $conn->prepare("SELECT username, email FROM admin_users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user) {
                // Delete user
                $stmt = $conn->prepare("DELETE FROM admin_users WHERE id = ?");
                $stmt->bind_param('i', $userId);
                $stmt->execute();

                logAudit('delete_user', 'admin_users', $userId, $user);
                $success = 'User deleted successfully';
            } else {
                $error = 'User not found';
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = $_POST['user_id'] ?? 0;
        $newPassword = generatePassword(12);

        // Update password
        $stmt = $conn->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt->bind_param('si', $passwordHash, $userId);

        if ($stmt->execute()) {
            logAudit('reset_password', 'admin_users', $userId);
            $success = "Password reset successfully. New password: $newPassword";
        } else {
            $error = 'Failed to reset password';
        }
    } elseif ($action === 'toggle_active') {
        $userId = $_POST['user_id'] ?? 0;

        // Can't deactivate yourself
        if ($userId == $_SESSION['user_id']) {
            $error = 'Cannot deactivate your own account';
        } else {
            // Get current status
            $stmt = $conn->prepare("SELECT is_active FROM admin_users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user) {
                $newStatus = !$user['is_active'];

                // Update status
                $stmt = $conn->prepare("UPDATE admin_users SET is_active = ? WHERE id = ?");
                $stmt->bind_param('ii', $newStatus, $userId);
                $stmt->execute();

                logAudit('toggle_user_status', 'admin_users', $userId, ['is_active' => $user['is_active']], ['is_active' => $newStatus]);
                $success = $newStatus ? 'User activated' : 'User deactivated';
            } else {
                $error = 'User not found';
            }
        }
    }
}

// Get all users
$stmt = $conn->prepare("
    SELECT
        id,
        username,
        email,
        role,
        is_active,
        created_at,
        last_login,
        login_attempts,
        locked_until
    FROM admin_users
    ORDER BY created_at ASC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
    <h1 class="page-title">User Management</h1>
    <p class="page-subtitle">Manage admin panel users</p>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <?php echo e($success); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error">
        <?php echo e($error); ?>
    </div>
<?php endif; ?>

<!-- Add New User Button -->
<div style="margin-bottom: 24px;">
    <button onclick="showAddUserModal()" class="btn btn-primary" style="padding: 12px 24px; font-size: 15px;">
        + Add New User
    </button>
</div>

<!-- Users Table -->
<?php if (!empty($users)): ?>
    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">User</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Email</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Role</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Status</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #4a5568;">Last Login</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 13px; font-weight: 600; color: #4a5568;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 16px;">
                            <div style="font-size: 15px; font-weight: 500; color: #1a202c;">
                                <?php echo e($user['username']); ?>
                                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                    <span style="font-size: 11px; color: #718096;">(You)</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 12px; color: #718096;">
                                Created: <?php echo e(date('M j, Y', strtotime($user['created_at']))); ?>
                            </div>
                        </td>
                        <td style="padding: 16px;">
                            <a href="mailto:<?php echo e($user['email']); ?>" style="color: #667eea; font-size: 14px;">
                                <?php echo e($user['email']); ?>
                            </a>
                        </td>
                        <td style="padding: 16px;">
                            <?php if ($user['role'] === 'super_admin'): ?>
                                <span style="display: inline-block; padding: 4px 12px; background: #667eea20; color: #667eea; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                    Super Admin
                                </span>
                            <?php else: ?>
                                <span style="display: inline-block; padding: 4px 12px; background: #edf2f7; color: #4a5568; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                    Admin
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 16px;">
                            <?php if (!$user['is_active']): ?>
                                <span style="display: inline-block; padding: 4px 12px; background: #fed7d7; color: #c53030; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                    Inactive
                                </span>
                            <?php elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()): ?>
                                <span style="display: inline-block; padding: 4px 12px; background: #fed7d7; color: #c53030; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                    Locked
                                </span>
                            <?php else: ?>
                                <span style="display: inline-block; padding: 4px 12px; background: #c6f6d5; color: #276749; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                    Active
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 16px; font-size: 14px; color: #718096;">
                            <?php echo $user['last_login'] ? e(date('M j, Y g:i A', strtotime($user['last_login']))) : 'Never'; ?>
                        </td>
                        <td style="padding: 16px; text-align: center;">
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <button onclick="resetPassword(<?php echo e($user['id']); ?>, '<?php echo e($user['username']); ?>')"
                                        class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px; margin-right: 4px;">
                                    Reset Password
                                </button>
                                <button onclick="toggleActive(<?php echo e($user['id']); ?>, '<?php echo e($user['username']); ?>', <?php echo e($user['is_active'] ? 'true' : 'false'); ?>)"
                                        class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px; margin-right: 4px;">
                                    <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                                <button onclick="deleteUser(<?php echo e($user['id']); ?>, '<?php echo e($user['username']); ?>')"
                                        class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;">
                                    Delete
                                </button>
                            <?php else: ?>
                                <span style="color: #718096; font-size: 13px;">-</span>
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
        <h3 style="font-size: 18px; font-weight: 600; color: #1a202c; margin-bottom: 8px;">No Users Found</h3>
        <p style="color: #718096; font-size: 14px;">Click "Add New User" to create one.</p>
    </div>
<?php endif; ?>

<!-- Add User Modal -->
<div id="addUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 32px; width: 100%; max-width: 500px; margin: 20px;">
        <h2 style="font-size: 20px; font-weight: 700; color: #1a202c; margin-bottom: 24px;">Add New User</h2>

        <form id="addUserForm" method="POST" style="display: flex; flex-direction: column; gap: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="create">

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Username *</label>
                <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,50}"
                       placeholder="3-50 characters (letters, numbers, underscore)"
                       style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Email *</label>
                <input type="email" name="email" required placeholder="user@example.com"
                       style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Password *</label>
                <input type="password" name="password" required minlength="8"
                       placeholder="Min 8 chars, must include uppercase, lowercase, and number"
                       style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
            </div>

            <div>
                <label style="display: block; font-size: 13px; font-weight: 500; color: #4a5568; margin-bottom: 4px;">Role *</label>
                <select name="role" required style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px;">
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super Admin</option>
                </select>
            </div>

            <div style="display: flex; gap: 12px; padding-top: 16px; border-top: 1px solid #e2e8f0; margin-top: 8px;">
                <button type="submit" class="btn btn-primary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                    Create User
                </button>
                <button type="button" onclick="hideAddUserModal()" class="btn btn-secondary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 32px; width: 100%; max-width: 400px; margin: 20px;">
        <h2 style="font-size: 20px; font-weight: 700; color: #1a202c; margin-bottom: 16px;">Delete User</h2>
        <p style="color: #4a5568; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
            Are you sure you want to delete <strong id="deleteUsername"></strong>?<br><br>
            This action cannot be undone.
        </p>
        <form id="deleteForm" method="POST" style="display: flex; gap: 12px;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="delete_user_id" value="">

            <button type="submit" class="btn btn-danger" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Delete
            </button>
            <button type="button" onclick="hideDeleteModal()" class="btn btn-secondary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Cancel
            </button>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 32px; width: 100%; max-width: 400px; margin: 20px;">
        <h2 style="font-size: 20px; font-weight: 700; color: #1a202c; margin-bottom: 16px;">Reset Password</h2>
        <p style="color: #4a5568; font-size: 14px; line-height: 1.6; margin-bottom: 24px;">
            Generate a new random password for <strong id="resetUsername"></strong>?
        </p>
        <form id="resetForm" method="POST" style="display: flex; gap: 12px;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id" value="">

            <button type="submit" class="btn btn-primary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Generate & Send
            </button>
            <button type="button" onclick="hideResetModal()" class="btn btn-secondary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Cancel
            </button>
        </form>
    </div>
</div>

<!-- Toggle Status Modal -->
<div id="toggleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 32px; width: 100%; max-width: 400px; margin: 20px;">
        <h2 id="toggleTitle" style="font-size: 20px; font-weight: 700; color: #1a202c; margin-bottom: 16px;">Toggle User Status</h2>
        <p id="toggleMessage" style="color: #4a5568; font-size: 14px; line-height: 1.6; margin-bottom: 24px;"></p>
        <form id="toggleForm" method="POST" style="display: flex; gap: 12px;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="user_id" id="toggle_user_id" value="">

            <button type="submit" id="toggleButton" class="btn btn-primary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Confirm
            </button>
            <button type="button" onclick="hideToggleModal()" class="btn btn-secondary" style="flex: 1; padding: 12px; font-size: 15px; font-weight: 600;">
                Cancel
            </button>
        </form>
    </div>
</div>

<script>
function showAddUserModal() {
    document.getElementById('addUserModal').style.display = 'flex';
    document.getElementById('addUserForm').reset();
}

function hideAddUserModal() {
    document.getElementById('addUserModal').style.display = 'none';
}

function deleteUser(id, username) {
    document.getElementById('deleteModal').style.display = 'flex';
    document.getElementById('deleteUsername').textContent = username;
    document.getElementById('delete_user_id').value = id;
}

function hideDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

function resetPassword(id, username) {
    document.getElementById('resetModal').style.display = 'flex';
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('reset_user_id').value = id;
}

function hideResetModal() {
    document.getElementById('resetModal').style.display = 'none';
}

function toggleActive(id, username, isActive) {
    document.getElementById('toggleModal').style.display = 'flex';
    document.getElementById('toggle_user_id').value = id;

    if (isActive) {
        document.getElementById('toggleTitle').textContent = 'Deactivate User';
        document.getElementById('toggleMessage').textContent = `Deactivate ${username}? They will not be able to login.`;
        document.getElementById('toggleButton').textContent = 'Deactivate';
        document.getElementById('toggleButton').className = 'btn btn-danger';
    } else {
        document.getElementById('toggleTitle').textContent = 'Activate User';
        document.getElementById('toggleMessage').textContent = `Activate ${username}? They will be able to login again.`;
        document.getElementById('toggleButton').textContent = 'Activate';
        document.getElementById('toggleButton').className = 'btn btn-primary';
    }
}

function hideToggleModal() {
    document.getElementById('toggleModal').style.display = 'none';
}

// Close modals on outside click
document.getElementById('addUserModal').addEventListener('click', function(e) {
    if (e.target === this) hideAddUserModal();
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) hideDeleteModal();
});

document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) hideResetModal();
});

document.getElementById('toggleModal').addEventListener('click', function(e) {
    if (e.target === this) hideToggleModal();
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
