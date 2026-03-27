<?php
// admin/manage_users.php

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('admin');

$db = getDB();

// Detect / add is_active column
$colRes       = $db->query("SHOW COLUMNS FROM users");
$existingCols = array_column($colRes->fetch_all(MYSQLI_ASSOC), 'Field');
$hasIsActive  = in_array('is_active', $existingCols);
$hasDept      = in_array('department', $existingCols);

if (!$hasIsActive) {
    $db->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
    $hasIsActive = true;
}

// ── Handle POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        setFlash('error', 'Invalid form submission.');
        header('Location: ' . BASE_URL . '/admin/manage_users.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'delete_user') {
        if ($userId === (int) $_SESSION['user_id']) {
            setFlash('error', 'You cannot delete your own account.');
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            setFlash('success', 'User deleted successfully.');
        }

    } elseif ($action === 'toggle_active') {
        if ($userId === (int) $_SESSION['user_id']) {
            setFlash('error', 'You cannot deactivate your own account.');
        } else {
            $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            setFlash('success', 'User status updated.');
        }
    }

    $rf = $_GET['role'] ?? '';
    header('Location: ' . BASE_URL . '/admin/manage_users.php' . ($rf ? '?role=' . urlencode($rf) : ''));
    exit;
}

// ── Fetch users ──
$role_filter = $_GET['role'] ?? '';
if ($role_filter && in_array($role_filter, ['student','lecturer','admin'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE role = ? ORDER BY created_at DESC");
    $stmt->bind_param('s', $role_filter);
} else {
    $stmt = $db->prepare("SELECT * FROM users ORDER BY created_at DESC");
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt   = $db->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role");
$counts = array_column($stmt->fetch_all(MYSQLI_ASSOC), 'cnt', 'role');

$activeCount = $inactiveCount = 0;
foreach ($users as $u) {
    if ($u['is_active'] ?? 1) $activeCount++; else $inactiveCount++;
}

$csrfToken = generateCsrfToken();
$pageTitle  = 'Manage Users';
include __DIR__ . '/../includes/header.php';
?>

<style>
.action-group { display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap; }
tr.user-inactive td { opacity:0.5; }
tr.user-inactive td:last-child { opacity:1; }
</style>

<div class="flex-between mb-2">
    <div></div>
    <a href="<?= BASE_URL ?>/admin/create_user.php" class="btn btn-amber">+ Create New User</a>
</div>

<div class="page-header">
    <h1>User Management</h1>
    <p>Create, edit, deactivate, and remove system accounts.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= $counts['student']    ?? 0 ?></div>
        <div class="stat-label">Students</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $counts['lecturer'] ?? 0 ?></div>
        <div class="stat-label">Lecturers</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $counts['admin']      ?? 0 ?></div>
        <div class="stat-label">Admins</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $activeCount ?></div>
        <div class="stat-label">Active</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $inactiveCount ?></div>
        <div class="stat-label">Inactive</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">All Users (<?= count($users) ?>)</h2>
        <div class="flex gap-1">
            <a href="manage_users.php" class="btn btn-sm <?= !$role_filter               ? 'btn-primary' : 'btn-outline' ?>">All</a>
            <a href="?role=student"    class="btn btn-sm <?= $role_filter==='student'     ? 'btn-primary' : 'btn-outline' ?>">Students</a>
            <a href="?role=lecturer" class="btn btn-sm <?= $role_filter==='lecturer'  ? 'btn-primary' : 'btn-outline' ?>">Lecturers</a>
            <a href="?role=admin"      class="btn btn-sm <?= $role_filter==='admin'       ? 'btn-primary' : 'btn-outline' ?>">Admins</a>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Reg No</th>
                    <?php if ($hasDept): ?><th>Department</th><?php endif; ?>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $isActive = (bool)($u['is_active'] ?? 1);
                $isSelf   = ($u['id'] === (int)$_SESSION['user_id']);
            ?>
            <tr class="<?= !$isActive ? 'user-inactive' : '' ?>">
                <td>
                    <strong><?= htmlspecialchars($u['full_name']) ?></strong>
                    <?php if ($isSelf): ?>
                        <span style="font-size:0.7rem;color:var(--amber);font-weight:700;margin-left:4px;">YOU</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.87rem;"><?= htmlspecialchars($u['email']) ?></td>
                <td>
                    <span class="nav-role-badge nav-role-<?= $u['role'] ?>"><?= $u['role'] === 'lecturer' ? 'Lecturer' : ucfirst($u['role']) ?></span>
                </td>
                <td style="font-size:0.85rem;"><?= htmlspecialchars($u['reg_no'] ?? '—') ?></td>
                <?php if ($hasDept): ?>
                <td style="font-size:0.85rem;"><?= htmlspecialchars($u['department'] ?? '—') ?></td>
                <?php endif; ?>
                <td>
                    <?php if ($isActive): ?>
                        <span class="badge badge-active">Active</span>
                    <?php else: ?>
                        <span class="badge badge-inactive">Inactive</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.82rem;">
                    <?= !empty($u['last_login']) ? date('d M Y, H:i', strtotime($u['last_login'])) : 'Never' ?>
                </td>
                <td>
                    <div class="action-group">

                        <!-- Edit → dedicated page -->
                        <a href="<?= BASE_URL ?>/admin/edit_user.php?id=<?= $u['id'] ?>"
                           class="btn btn-outline btn-sm">✏️ Edit</a>

                        <?php if (!$isSelf): ?>

                        <!-- Deactivate / Activate -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"  value="toggle_active">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit"
                                    class="btn btn-sm <?= $isActive ? 'btn-outline' : 'btn-success' ?>"
                                    style="<?= $isActive ? 'color:#c05621;border-color:#c05621;' : '' ?>"
                                    data-confirm="<?= $isActive
                                        ? 'Deactivate ' . htmlspecialchars($u['full_name']) . '? They will not be able to log in.'
                                        : 'Reactivate ' . htmlspecialchars($u['full_name']) . '?' ?>">
                                <?= $isActive ? '🔒 Deactivate' : '🔓 Activate' ?>
                            </button>
                        </form>

                        <!-- Delete -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action"  value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    data-confirm="Permanently delete <?= htmlspecialchars($u['full_name']) ?>? This cannot be undone.">
                                🗑 Delete
                            </button>
                        </form>

                        <?php else: ?>
                            <span class="text-muted" style="font-size:0.8rem;padding:0 0.25rem;">
                                (Deactivate / Delete unavailable for your own account)
                            </span>
                        <?php endif; ?>

                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>