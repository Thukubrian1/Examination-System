<?php

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

// ── Already-logged-in check ───────────────────────────────────────────────
// For each role, peek at its cookie. If the cookie exists AND the session
// holds a valid user_id, redirect to THAT role's own dashboard.
// Each role has its own dashboard URL — no role shares a dashboard.
// This prevents redirect loops.
//
// We always close the role session before moving on so we end up
// in EXAM_SESSION by the time we render the login form.
// ─────────────────────────────────────────────────────────────────────────

$dashboardMap = [
    'admin'    => BASE_URL . '/admin/dashboard.php',
    'lecturer' => BASE_URL . '/lecturer/dashboard.php',
    'student'  => BASE_URL . '/student/dashboard.php',
];

foreach (ROLE_COOKIE_MAP as $role => $cookieName) {
    if (empty($_COOKIE[$cookieName])) continue;

    startRoleSession($cookieName);

    if (!empty($_SESSION['user_id']) && isset($_SESSION['role'])) {
        $sessionRole = $_SESSION['role'];
        // Only redirect if the role in the session matches this cookie's role.
        // This prevents an admin cookie accidentally redirecting to the wrong place.
        if ($sessionRole === $role && isset($dashboardMap[$sessionRole])) {
            header('Location: ' . $dashboardMap[$sessionRole]);
            exit;
        }
    }

    // Cookie exists but session is invalid or role mismatch — close and continue.
    session_write_close();
}

// Open the generic login session for CSRF token management.
startRoleSession('EXAM_SESSION');

// ─────────────────────────────────────────────────────────────────────────

$errors = [];
$email  = '';
$notice = '';

if (isset($_GET['notice']) && $_GET['notice'] === 'deactivated') {
    $notice = 'Your account has been deactivated. Please contact an administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrf()) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($email))    $errors[] = 'Email address is required.';
        if (empty($password)) $errors[] = 'Password is required.';

        if (empty($errors)) {
            $db = getDB();

            $stmt = $db->prepare(
                "SELECT id, full_name, email, password_hash, role FROM users WHERE email = ? LIMIT 1"
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {

                // Check is_active
                $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'");
                if ($colCheck && $colCheck->num_rows > 0) {
                    $chk = $db->prepare("SELECT is_active FROM users WHERE id = ? LIMIT 1");
                    $chk->bind_param('i', $user['id']);
                    $chk->execute();
                    $row = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if (isset($row['is_active']) && (int) $row['is_active'] === 0) {
                        $errors[] = 'This account has been deactivated. Please contact an administrator.';
                        $user = null;
                    }
                }

                if ($user) {
                    $cookieMap      = ROLE_COOKIE_MAP;
                    $roleCookieName = $cookieMap[$user['role']] ?? ('EXAM_' . strtoupper($user['role']));

                    // Close the generic EXAM_SESSION before opening the role session.
                    session_write_close();
                    startRoleSession($roleCookieName);
                    session_regenerate_id(true);

                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role']      = $user['role'];

                    $upd = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $upd->bind_param('i', $user['id']);
                    $upd->execute();
                    $upd->close();

                    setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');

                    // Each role redirects to its OWN dedicated dashboard.
                    $redirect = $dashboardMap[$user['role']] ?? BASE_URL . '/index.php';
                    header('Location: ' . $redirect);
                    exit;
                }

            } else {
                if (empty($errors)) {
                    $errors[] = 'Invalid email or password.';
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | KYU Online Exam System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="login-logo">
            <span class="hex">&#x2B21;</span>
            <h1>KYU ExamPortal</h1>
            <p>Kirinyaga University &mdash; Online Examination System</p>
        </div>

        <?php if ($notice): ?>
            <div class="flash flash-error">
                &#128274; <?= htmlspecialchars($notice) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="flash flash-error">
                <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/index.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($email) ?>"
                       placeholder="Enter your email address" required autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label" for="loginPassword">Password</label>
                <div style="position:relative;">
                    <input type="password" id="loginPassword" name="password" class="form-control"
                           placeholder="Enter password" required
                           autocomplete="current-password" style="padding-right:2.8rem;">
                    <button type="button" id="loginPwToggle"
                            style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);
                                   background:none;border:none;cursor:pointer;font-size:1rem;
                                   color:#94a3b8;padding:0;line-height:1;"
                            title="Show password">&#128065;</button>
                </div>
            </div>

            <button type="submit" class="btn btn-amber"
                    style="width:100%;justify-content:center;padding:0.8rem;">
                Sign In
            </button>
        </form>
    </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
(function () {
    var btn   = document.getElementById('loginPwToggle');
    var input = document.getElementById('loginPassword');
    if (btn && input) {
        btn.addEventListener('click', function () {
            if (input.type === 'password') {
                input.type    = 'text';
                btn.innerHTML = '&#128584;';
                btn.title     = 'Hide password';
            } else {
                input.type    = 'password';
                btn.innerHTML = '&#128065;';
                btn.title     = 'Show password';
            }
        });
    }
}());
</script>
</body>
</html>