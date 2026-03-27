<?php
// ============================================================
// config/session.php — Secure session + auth helpers
// ============================================================
// MULTI-TAB DESIGN
// ─────────────────
// Every role has its own dedicated session cookie:
//
//   EXAM_ADMIN    → admin users
//   EXAM_LECTURER → lecturer users
//   EXAM_STUDENT  → student users
//   EXAM_SESSION  → used only for the login page CSRF token
//
// Protected pages call requireRole('X') which opens ONLY the
// cookie for role X — never any other role's session.
//
// Tabs are fully isolated: each tab sends a different cookie
// and therefore operates on a completely separate session.
// ============================================================

ini_set('session.cookie_httponly', 1);
// ini_set('session.cookie_secure', 1);  // Uncomment on HTTPS
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode',  1);
ini_set('session.gc_maxlifetime',   7200);
session_set_cookie_params(7200);

// ── Single source of truth: role → cookie name ───────────────────────────
define('ROLE_COOKIE_MAP', [
    'admin'    => 'EXAM_ADMIN',
    'lecturer' => 'EXAM_LECTURER',
    'student'  => 'EXAM_STUDENT',
]);

// ── Role → dashboard URL map ─────────────────────────────────────────────
define('ROLE_DASHBOARD_MAP', [
    'admin'    => '/admin/dashboard.php',
    'lecturer' => '/lecturer/dashboard.php',
    'student'  => '/student/dashboard.php',
]);

/**
 * Open the session for a specific named cookie.
 * If a different session is already active, it is saved and closed first.
 * Safe to call multiple times with the same cookie name (no-op).
 */
function startRoleSession(string $cookieName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === $cookieName) return; // already on the right session
        session_write_close();                       // save & close the current one
    }
    session_name($cookieName);
    session_start();
}

// ============================================================
// Helpers
// ============================================================

/**
 * Verify the current user's account is still active in the DB.
 * Destroys the session and redirects to login if deactivated.
 */
function enforceActiveStatus(string $loginUrl): void
{
    if (empty($_SESSION['user_id'])) return;

    $db       = getDB();
    $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    if (!$colCheck || $colCheck->num_rows === 0) return;

    $stmt = $db->prepare("SELECT is_active FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (int) $row['is_active'] === 0) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
        header('Location: ' . $loginUrl . '?notice=deactivated');
        exit;
    }
}

/**
 * Require an authenticated session.
 * Redirects to login if not logged in or account is deactivated.
 */
function requireLogin(string $loginUrl = ''): void
{
    if ($loginUrl === '') {
        $loginUrl = defined('BASE_URL') ? BASE_URL . '/index.php' : '/index.php';
    }
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . $loginUrl);
        exit;
    }
    enforceActiveStatus($loginUrl);
}

/**
 * Require a specific role on a protected page.
 *
 * Opens ONLY the session cookie for $role — never any other role's cookie.
 * This is what makes tabs fully independent: each protected page opens
 * exactly one session determined by the role it requires.
 *
 * IMPORTANT: Admin pages use requireRole('admin') — NOT requireRole('lecturer').
 *            Lecturer pages use requireRole('lecturer').
 *            Admin users CAN access lecturer pages because admin is a superset,
 *            but when they do, they must be logged in via EXAM_LECTURER session
 *            (i.e. they logged in to a lecturer-role tab separately).
 *            There is NO cross-session fallback — each tab is fully independent.
 */
function requireRole(string $role, string $loginUrl = ''): void
{
    if ($loginUrl === '') {
        $loginUrl = defined('BASE_URL') ? BASE_URL . '/index.php' : '/index.php';
    }

    $cookieMap    = ROLE_COOKIE_MAP;
    $targetCookie = $cookieMap[$role] ?? null;

    if ($targetCookie === null) {
        header('Location: ' . $loginUrl);
        exit;
    }

    // Open ONLY this role's dedicated session.
    startRoleSession($targetCookie);

    // Ensure the user is logged in (redirects if not).
    requireLogin($loginUrl);

    // The session role must exactly match the required role.
    // Exception: admin may also access lecturer-only pages.
    $sessionRole = $_SESSION['role'] ?? '';
    $allowed = ($sessionRole === $role)
        || ($role === 'lecturer' && $sessionRole === 'admin');

    if (!$allowed) {
        // Wrong role in this session — send to login, not to their dashboard.
        // This prevents redirect loops: if someone has an EXAM_LECTURER cookie
        // but tries to access an admin page, we send them to login rather than
        // their dashboard (which would cause a loop).
        header('Location: ' . $loginUrl);
        exit;
    }
}

/**
 * Set a one-time flash message in the current session.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear the flash message from the current session.
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Generate (or reuse) a CSRF token stored in the current session.
 */
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the submitted CSRF token against the current session.
 */
function validateCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) &&
        hash_equals($_SESSION['csrf_token'], $token);
}