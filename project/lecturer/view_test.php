<?php
// lecturer/view_test.php — View test details and manage questions
// ============================================================

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db          = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$testId      = (int) ($_GET['id'] ?? 0);

// Fetch test (ensure ownership)
$stmt = $db->prepare("SELECT * FROM tests WHERE id = ? AND lecturer_id = ?");
$stmt->bind_param('ii', $testId, $lecturerId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test) {
    setFlash('error', 'Test not found or access denied.');
    header('Location: ' . BASE_URL . '/lecturer/dashboard.php');
    exit;
}

// Fetch questions with options
$stmt = $db->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY question_order");
$stmt->bind_param('i', $testId);
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($questions as &$q) {
    $stmt = $db->prepare("SELECT * FROM options WHERE question_id = ?");
    $stmt->bind_param('i', $q['id']);
    $stmt->execute();
    $q['options'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
unset($q);

// Recent attempts
$stmt = $db->prepare("
    SELECT r.*, u.full_name, u.reg_no
    FROM results r
    JOIN users u ON u.id = r.student_id
    WHERE r.test_id = ?
    ORDER BY r.submitted_at DESC
    LIMIT 20
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Test: ' . $test['title'];
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
    <a href="<?= BASE_URL ?>/lecturer/dashboard.php" class="btn btn-outline btn-sm">← Back</a>
    <div class="flex gap-1">
        <a href="<?= BASE_URL ?>/lecturer/edit_test.php?id=<?= $testId ?>" class="btn btn-amber btn-sm">✏️ Edit Test</a>
        <a href="<?= BASE_URL ?>/lecturer/analytics.php?test_id=<?= $testId ?>" class="btn btn-primary btn-sm">View Analytics</a>
    </div>
</div>

<div class="page-header">
    <h1><?= htmlspecialchars($test['title']) ?></h1>
    <p>
        Duration: <?= $test['duration_minutes'] ?> min &nbsp;|&nbsp;
        Total Marks: <?= $test['total_marks'] ?> &nbsp;|&nbsp;
        Pass Marks: <?= $test['pass_marks'] ?>
        <br>
        Available: <?= date('d M Y, H:i', strtotime($test['start_time'])) ?> →
                   <?= date('d M Y, H:i', strtotime($test['end_time'])) ?>
    </p>
</div>

<!-- Questions -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Questions (<?= count($questions) ?>)</h2>
    </div>

    <?php foreach ($questions as $i => $q): ?>
    <div style="border:1px solid #e2e8f0;border-radius:8px;padding:1.2rem;margin-bottom:1rem;">
        <div class="flex-between mb-1">
            <strong style="color:#0d1b2a;">Q<?= $i+1 ?>. <?= htmlspecialchars($q['question_text']) ?></strong>
            <span class="badge badge-active"><?= $q['marks'] ?> mk<?= $q['marks']>1?'s':'' ?></span>
        </div>
        <ul style="list-style:none;display:flex;flex-direction:column;gap:4px;margin-top:0.5rem;">
            <?php foreach ($q['options'] as $opt): ?>
            <li style="padding:0.4rem 0.75rem;border-radius:5px;font-size:0.88rem;
                background:<?= $opt['is_correct'] ? '#d1fae5' : '#f8fafc' ?>;
                border: 1px solid <?= $opt['is_correct'] ? '#6ee7b7' : '#e2e8f0' ?>">
                <?= $opt['is_correct'] ? '✓ ' : '' ?><?= htmlspecialchars($opt['option_text']) ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
</div>

<!-- Recent Attempts -->
<?php if (!empty($attempts)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Recent Submissions</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Student</th><th>Reg No</th><th>Score</th><th>%</th><th>Status</th><th>Submitted</th></tr>
            </thead>
            <tbody>
            <?php foreach ($attempts as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['full_name']) ?></td>
                <td><?= htmlspecialchars($a['reg_no'] ?? '—') ?></td>
                <td><?= $a['score'] ?>/<?= $a['total_marks'] ?></td>
                <td><?= $a['percentage'] ?>%</td>
                <td><span class="badge <?= $a['passed'] ? 'badge-active' : 'badge-inactive' ?>"><?= $a['passed'] ? 'Passed' : 'Failed' ?></span></td>
                <td><?= $a['submitted_at'] ? date('d M, H:i', strtotime($a['submitted_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>