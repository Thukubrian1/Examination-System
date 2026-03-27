<?php
// admin/dashboard.php — Admin-only dashboard

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

// Admin pages use requireRole('admin') exclusively.
// This opens EXAM_ADMIN session only — no crossover with EXAM_LECTURER.
requireRole('admin');

$db = getDB();

// ── Stats ──
$totalUsers    = $db->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$totalStudents = $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetch_row()[0];
$totalLecturers = $db->query("SELECT COUNT(*) FROM users WHERE role='lecturer'")->fetch_row()[0];
$totalTests    = $db->query("SELECT COUNT(*) FROM tests")->fetch_row()[0];
$totalResults  = $db->query("SELECT COUNT(*) FROM results WHERE status='submitted'")->fetch_row()[0];

// Active/Inactive
$colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'");
$hasIsActive = $colCheck && $colCheck->num_rows > 0;
$activeUsers   = $hasIsActive ? $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetch_row()[0] : $totalUsers;
$inactiveUsers = $hasIsActive ? $db->query("SELECT COUNT(*) FROM users WHERE is_active=0")->fetch_row()[0] : 0;

// Recent users
$recentUsers = $db->query("SELECT id, full_name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// Recent tests
$recentTests = $db->query("
    SELECT t.id, t.title, t.total_marks, t.is_active, t.created_at, u.full_name AS lecturer_name
    FROM tests t
    JOIN users u ON u.id = t.lecturer_id
    ORDER BY t.created_at DESC
    LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Admin Dashboard</h1>
    <p>System overview — manage users, monitor tests and results.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= $totalUsers ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalStudents ?></div>
        <div class="stat-label">Students</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalLecturers ?></div>
        <div class="stat-label">Lecturers</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalTests ?></div>
        <div class="stat-label">Tests Created</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalResults ?></div>
        <div class="stat-label">Submissions</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $inactiveUsers ?></div>
        <div class="stat-label">Inactive Users</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

    <!-- Recent Users -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recent Users</h2>
            <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-amber btn-sm">Manage All</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Name</th><th>Role</th><th>Joined</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($u['full_name']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($u['email']) ?></small>
                    </td>
                    <td>
                        <span class="nav-role-badge nav-role-<?= $u['role'] ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td style="font-size:0.82rem;">
                        <?= date('d M Y', strtotime($u['created_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Tests -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recent Tests</h2>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Title</th><th>Lecturer</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentTests as $t): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($t['title']) ?></strong></td>
                    <td style="font-size:0.85rem;"><?= htmlspecialchars($t['lecturer_name']) ?></td>
                    <td>
                        <?php if ($t['is_active']): ?>
                            <span class="badge badge-active">Active</span>
                        <?php else: ?>
                            <span class="badge badge-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Quick Links -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Quick Actions</h2>
    </div>
    <div class="flex gap-2" style="flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-primary">👥 Manage Users</a>
        <a href="<?= BASE_URL ?>/admin/create_user.php"  class="btn btn-amber">+ Create User</a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>