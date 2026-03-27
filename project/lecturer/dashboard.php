<?php
// lecturer/dashboard.php — lecturer main dashboard

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db         = getDB();
$lecturerId = (int) $_SESSION['user_id'];

// ── Stats ──────────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) FROM tests WHERE lecturer_id = ?");
$stmt->bind_param('i', $lecturerId);
$stmt->execute();
$totalTests = $stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $db->prepare("
    SELECT COUNT(DISTINCT r.student_id)
    FROM results r
    JOIN tests t ON t.id = r.test_id
    WHERE t.lecturer_id = ?
");
$stmt->bind_param('i', $lecturerId);
$stmt->execute();
$totalStudents = $stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $db->prepare("
    SELECT ROUND(AVG(r.percentage), 1)
    FROM results r
    JOIN tests t ON t.id = r.test_id
    WHERE t.lecturer_id = ? AND r.status = 'submitted'
");
$stmt->bind_param('i', $lecturerId);
$stmt->execute();
$avgScore = $stmt->get_result()->fetch_row()[0] ?? 0;
$stmt->close();

// ── My tests — include start/end times for live status update ───────────
$stmt = $db->prepare("
    SELECT t.*,
           COUNT(DISTINCT q.id) AS question_count,
           COUNT(DISTINCT r.id) AS attempt_count
    FROM tests t
    LEFT JOIN questions q ON q.test_id = t.id
    LEFT JOIN results   r ON r.test_id = t.id
    WHERE t.lecturer_id = ?
    GROUP BY t.id
    ORDER BY t.created_at DESC
");
$stmt->bind_param('i', $lecturerId);
$stmt->execute();
$tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Lecturer Dashboard';
$serverNow = time();
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Lecturer Dashboard</h1>
    <p>Manage your tests, questions, and view student performance.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= $totalTests ?></div>
        <div class="stat-label">My Tests</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalStudents ?></div>
        <div class="stat-label">Students Attempted</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $avgScore ?>%</div>
        <div class="stat-label">Avg Score</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">My Tests</h2>
        <a href="create_test.php" class="btn btn-amber">+ Create Test</a>
    </div>

    <?php if (empty($tests)): ?>
        <p class="text-muted text-center" style="padding:2rem 0;">
            No tests yet. <a href="create_test.php">Create your first test.</a>
        </p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Duration</th>
                    <th>Marks</th>
                    <th>Questions</th>
                    <th>Submissions</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="lecturerTestsBody">
            <?php foreach ($tests as $test):
                $now   = time();
                $start = strtotime($test['start_time']);
                $end   = strtotime($test['end_time']);
            ?>
            <tr data-start="<?= $start ?>"
                data-end="<?= $end ?>"
                data-active="<?= (int)$test['is_active'] ?>"
                data-test-id="<?= (int)$test['id'] ?>">
                <td><strong><?= htmlspecialchars($test['title']) ?></strong></td>
                <td><?= $test['duration_minutes'] ?> min</td>
                <td><?= $test['total_marks'] ?></td>
                <td><?= $test['question_count'] ?></td>
                <td><?= $test['attempt_count'] ?></td>
                <td class="status-cell">
                    <?php if (!$test['is_active']): ?>
                        <span class="badge badge-inactive">Inactive</span>
                    <?php elseif ($now < $start): ?>
                        <span class="badge badge-pending">Upcoming</span>
                    <?php elseif ($now > $end): ?>
                        <span class="badge badge-inactive">Ended</span>
                    <?php else: ?>
                        <span class="badge badge-active">Live</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="flex gap-1" style="flex-wrap:wrap;">
                        <a href="<?= BASE_URL ?>/lecturer/view_test.php?id=<?= $test['id'] ?>"
                           class="btn btn-outline btn-sm">View</a>
                        <a href="<?= BASE_URL ?>/lecturer/edit_test.php?id=<?= $test['id'] ?>"
                           class="btn btn-amber btn-sm">Edit</a>
                        <a href="<?= BASE_URL ?>/lecturer/analytics.php?test_id=<?= $test['id'] ?>"
                           class="btn btn-primary btn-sm">Results</a>
                        <a href="<?= BASE_URL ?>/lecturer/toggle_test.php?id=<?= $test['id'] ?>"
                           class="btn btn-sm <?= $test['is_active'] ? 'btn-danger' : 'btn-success' ?>"
                           data-confirm="<?= $test['is_active'] ? 'Deactivate this test?' : 'Activate this test?' ?>">
                            <?= $test['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
// ── Live status badge updater ─────────────────────────────────────────────
// Recalculates each test's status badge every 5 seconds using the
// server's clock, so the transition from "Live" → "Ended" happens
// at exactly the right moment without a page reload.

const serverNow   = <?= $serverNow ?>;
const clientNow   = Math.floor(Date.now() / 1000);
const clockOffset = serverNow - clientNow;

function now() {
    return Math.floor(Date.now() / 1000) + clockOffset;
}

function updateLecturerRow(row) {
    const start  = parseInt(row.dataset.start);
    const end    = parseInt(row.dataset.end);
    const active = parseInt(row.dataset.active);
    const t      = now();

    const statusCell = row.querySelector('.status-cell');
    if (!statusCell) return;

    let badge;
    if (!active) {
        badge = '<span class="badge badge-inactive">Inactive</span>';
    } else if (t < start) {
        badge = '<span class="badge badge-pending">Upcoming</span>';
    } else if (t > end) {
        badge = '<span class="badge badge-inactive">Ended</span>';
    } else {
        badge = '<span class="badge badge-active">Live</span>';
    }

    statusCell.innerHTML = badge;
}

function updateAllLecturerRows() {
    document.querySelectorAll('#lecturerTestsBody tr[data-end]').forEach(updateLecturerRow);
}

updateAllLecturerRows();
setInterval(updateAllLecturerRows, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>