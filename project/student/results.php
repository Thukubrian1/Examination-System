<?php
// student/results.php — All results for the logged-in student
// ============================================================

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('student');

$db        = getDB();
$studentId = (int) $_SESSION['user_id'];

$stmt = $db->prepare("
    SELECT r.*, t.title, t.total_marks AS test_total, t.pass_marks,
           u.full_name AS lecturer_name
    FROM results r
    JOIN tests t ON t.id = r.test_id
    JOIN users u ON u.id = t.lecturer_id
    WHERE r.student_id = ?
    ORDER BY r.started_at DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary stats
$submitted = array_filter($results, fn($r) => $r['status'] === 'submitted');
$passed    = array_filter($submitted, fn($r) => $r['passed']);
$avgPct    = count($submitted) ? round(array_sum(array_column(array_values($submitted), 'percentage')) / count($submitted), 1) : 0;

$pageTitle = 'My Results';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>My Results</h1>
    <p>A complete history of your exam attempts.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= count($submitted) ?></div>
        <div class="stat-label">Completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= count($passed) ?></div>
        <div class="stat-label">Passed</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= count($submitted) - count($passed) ?></div>
        <div class="stat-label">Failed</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $avgPct ?>%</div>
        <div class="stat-label">Avg Score</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">All Attempts</h2>
        <a href="dashboard.php" class="btn btn-outline btn-sm">← Dashboard</a>
    </div>

    <?php if (empty($results)): ?>
        <p class="text-muted text-center" style="padding:2rem 0;">You have not taken any tests yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Test</th>
                    <th>Lecturer</th>
                    <th>Score</th>
                    <th>Percentage</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['title']) ?></strong></td>
                <td><?= htmlspecialchars($r['lecturer_name']) ?></td>
                <td>
                    <?php if ($r['status'] === 'submitted' || $r['status'] === 'timed_out'): ?>
                        <?= $r['score'] ?>/<?= $r['total_marks'] ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['status'] === 'submitted' || $r['status'] === 'timed_out'): ?>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div style="width:80px;background:#e2e8f0;border-radius:3px;height:8px;">
                                <div style="width:<?= $r['percentage'] ?>%;background:<?= $r['passed'] ? '#38a169' : '#e53e3e' ?>;height:100%;border-radius:3px;"></div>
                            </div>
                            <span style="font-size:0.85rem;font-weight:600;"><?= $r['percentage'] ?>%</span>
                        </div>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['status'] === 'submitted'): ?>
                        <span class="badge <?= $r['passed'] ? 'badge-active' : 'badge-inactive' ?>">
                            <?= $r['passed'] ? 'Passed' : 'Failed' ?>
                        </span>
                    <?php elseif ($r['status'] === 'timed_out'): ?>
                        <span class="badge badge-inactive">Timed Out</span>
                    <?php else: ?>
                        <span class="badge badge-pending">In Progress</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.85rem;">
                    <?= $r['submitted_at']
                        ? date('d M Y', strtotime($r['submitted_at']))
                        : date('d M Y', strtotime($r['started_at'])) ?>
                </td>
                <td>
                    <?php if (in_array($r['status'], ['submitted', 'timed_out'])): ?>
                        <a href="result_detail.php?result_id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">View</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>