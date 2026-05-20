<?php
/**
 * Login Handler
 * Processes login form submissions
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate input
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } elseif (isAccountLocked($username)) {
        $error = 'Account is temporarily locked. Please try again later.';
        logLoginAttempt($username, 0);
    } elseif (getLoginAttempts($username) >= MAX_LOGIN_ATTEMPTS) {
        $error = 'Too many failed login attempts. Account locked for 15 minutes.';
        logLoginAttempt($username, 0);
        lockAccount($username);
    } else {
        // Attempt login
        $conn = getDbConnection();
        if ($conn) {
            $stmt = $conn->prepare("
                SELECT id, username, password_hash, email, role, is_active
                FROM admin_users
                WHERE username = ?
            ");

            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            if ($result && password_verify($password, $result['password_hash'])) {
                if (!$result['is_active']) {
                    $error = 'Account is inactive. Contact administrator.';
                } else {
                    // Successful login
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $result['id'];
                    $_SESSION['username'] = $result['username'];
                    $_SESSION['email'] = $result['email'];
                    $_SESSION['role'] = $result['role'];
                    $_SESSION['last_activity'] = time();

                    // Update last login
                    $stmt = $conn->prepare("UPDATE admin_users SET last_login = NOW(), login_attempts = 0 WHERE id = ?");
                    $stmt->bind_param('i', $result['id']);
                    $stmt->execute();

                    // Log successful login
                    logLoginAttempt($username, 1);
                    logAudit('login', 'admin_users', $result['id']);

                    header('Location: ' . BASE_URL . '/dashboard.php');
                    exit;
                }
            } else {
                // Failed login
                $error = 'Invalid username or password';
                logLoginAttempt($username, 0);

                // Increment login attempts
                $stmt = $conn->prepare("UPDATE admin_users SET login_attempts = login_attempts + 1 WHERE username = ?");
                $stmt->bind_param('s', $username);
                $stmt->execute();

                $attempts = getLoginAttempts($username);
                if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                    lockAccount($username);
                    $error = 'Too many failed attempts. Account locked for 15 minutes.';
                }
            }
        } else {
            $error = 'Database connection failed. Please try again.';
        }
    }
}

// Show login page
require_once __DIR__ . '/../templates/login_form.php';
