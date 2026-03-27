<?php
// logout.php — Destroy the current tab's role session only.
// Other tabs with different roles remain logged in because they
// each use their own separate session cookie.

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/config/session.php';

// Figure out which role session this tab is using by checking which
// role cookie exists AND contains a valid session. We open each one
// in turn and destroy whichever one has user_id set.
$destroyed = false;

foreach (ROLE_COOKIE_MAP as $role => $cookieName) {
    if (empty($_COOKIE[$cookieName])) continue;

    startRoleSession($cookieName);

    if (!empty($_SESSION['user_id'])) {
        // This is the active session for this tab — destroy it.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        $destroyed = true;
        break;
    }

    // This cookie's session has no user — close it and check the next.
    session_write_close();
}

header('Location: ' . BASE_URL . '/index.php');
exit;