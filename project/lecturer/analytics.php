<?php
// lecturer/analytics.php — Test performance analytics
// ============================================================

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db          = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$testId      = (int) ($_GET['test_id'] ?? 0);

// If no test_id, show list of all lecturer tests
if (!$testId) {
    $stmt = $db->prepare("
        SELECT t.*, COUNT(r.id) AS attempts
        FROM tests t
        LEFT JOIN results r ON r.test_id = t.id AND r.status = 'submitted'
        WHERE t.lecturer_id = ?
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ");
    $stmt->bind_param('i', $lecturerId);
    $stmt->execute();
    $tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $pageTitle = 'Analytics';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="page-header"><h1>Analytics</h1><p>Select a test to view detailed results.</p></div>';
    echo '<div class="card"><div class="card-header"><h2 class="card-title">My Tests</h2></div><div class="table-wrap"><table><thead><tr><th>Title</th><th>Attempts</th><th>Actions</th></tr></thead><tbody>';
    foreach ($tests as $t) {
        echo '<tr><td>' . htmlspecialchars($t['title']) . '</td><td>' . $t['attempts'] . '</td>';
        echo '<td><a href="analytics.php?test_id=' . $t['id'] . '" class="btn btn-primary btn-sm">View</a></td></tr>';
    }
    echo '</tbody></table></div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Verify test ownership
$stmt = $db->prepare("SELECT * FROM tests WHERE id = ? AND lecturer_id = ?");
$stmt->bind_param('ii', $testId, $lecturerId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test) {
    setFlash('error', 'Test not found.');
    header('Location: ' . BASE_URL . '/lecturer/analytics.php');
    exit;
}

// Summary stats
$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(passed) AS passed,
        ROUND(AVG(percentage),1) AS avg_pct,
        MAX(percentage) AS max_pct,
        MIN(percentage) AS min_pct
    FROM results
    WHERE test_id = ? AND status = 'submitted'
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Per-student results
$stmt = $db->prepare("
    SELECT r.*, u.full_name, u.reg_no, u.email
    FROM results r
    JOIN users u ON u.id = r.student_id
    WHERE r.test_id = ? AND r.status = 'submitted'
    ORDER BY r.percentage DESC
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Score distribution buckets
$buckets = ['0–20' => 0, '21–40' => 0, '41–60' => 0, '61–80' => 0, '81–100' => 0];
foreach ($results as $r) {
    $p = (float) $r['percentage'];
    if ($p <= 20)       $buckets['0–20']++;
    elseif ($p <= 40)   $buckets['21–40']++;
    elseif ($p <= 60)   $buckets['41–60']++;
    elseif ($p <= 80)   $buckets['61–80']++;
    else                $buckets['81–100']++;
}
$maxBucket = max($buckets) ?: 1;

// Per-question difficulty (% who got it wrong)
$stmt = $db->prepare("
    SELECT q.question_text, q.marks,
           COUNT(sa.id) AS attempts,
           SUM(sa.is_correct) AS correct_count
    FROM questions q
    LEFT JOIN student_answers sa ON sa.question_id = q.id
    WHERE q.test_id = ?
    GROUP BY q.id
    ORDER BY q.question_order
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$questionStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Analytics — ' . $test['title'];
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
    <a href="analytics.php" class="btn btn-outline btn-sm">← All Tests</a>
    <a href="view_test.php?id=<?= $testId ?>" class="btn btn-primary btn-sm">View Test</a>
</div>

<div class="page-header">
    <h1>Analytics: <?= htmlspecialchars($test['title']) ?></h1>
    <p>Performance report for all submitted attempts.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
        <div class="stat-label">Submissions</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['passed'] ?? 0 ?></div>
        <div class="stat-label">Passed</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['avg_pct'] ?? 0 ?>%</div>
        <div class="stat-label">Avg Score</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['max_pct'] ?? 0 ?>%</div>
        <div class="stat-label">Highest Score</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['min_pct'] ?? 0 ?>%</div>
        <div class="stat-label">Lowest Score</div>
    </div>
</div>

<!-- Score Distribution Chart -->
<div class="card">
    <div class="card-header"><h2 class="card-title">Score Distribution</h2></div>
    <div class="chart-bar-wrap" style="max-width:600px;">
        <?php foreach ($buckets as $range => $count): ?>
        <div class="chart-bar-row">
            <span class="chart-bar-label"><?= $range ?>%</span>
            <div class="chart-bar-track">
                <div class="chart-bar-fill" style="width:<?= $maxBucket ? round($count/$maxBucket*100) : 0 ?>%"></div>
            </div>
            <span class="chart-bar-val"><?= $count ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Question difficulty -->
<?php if (!empty($questionStats)): ?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Question Difficulty</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Question</th><th>Attempts</th><th>Correct</th><th>Accuracy</th></tr>
            </thead>
            <tbody>
            <?php foreach ($questionStats as $i => $qs): ?>
            <?php $acc = $qs['attempts'] > 0 ? round($qs['correct_count']/$qs['attempts']*100) : 0; ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><?= htmlspecialchars(substr($qs['question_text'], 0, 80)) ?>...</td>
                <td><?= $qs['attempts'] ?></td>
                <td><?= $qs['correct_count'] ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div style="flex:1;background:#e2e8f0;border-radius:3px;height:8px;">
                            <div style="width:<?= $acc ?>%;background:<?= $acc >= 60 ? '#38a169' : ($acc >= 40 ? '#e8a020' : '#e53e3e') ?>;height:100%;border-radius:3px;"></div>
                        </div>
                        <span style="font-size:0.82rem;font-weight:600;"><?= $acc ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Full results table -->
<?php if (!empty($results)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Student Results</h2>
        <a href="export_results.php?test_id=<?= $testId ?>" class="btn btn-outline btn-sm">Export CSV</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Rank</th><th>Name</th><th>Reg No</th><th>Score</th><th>%</th><th>Status</th><th>Time Taken</th></tr>
            </thead>
            <tbody>
            <?php foreach ($results as $rank => $r): ?>
            <tr>
                <td><?= $rank+1 ?></td>
                <td><?= htmlspecialchars($r['full_name']) ?></td>
                <td><?= htmlspecialchars($r['reg_no'] ?? '—') ?></td>
                <td><?= $r['score'] ?>/<?= $r['total_marks'] ?></td>
                <td><?= $r['percentage'] ?>%</td>
                <td><span class="badge <?= $r['passed'] ? 'badge-active' : 'badge-inactive' ?>"><?= $r['passed'] ? 'Pass' : 'Fail' ?></span></td>
                <td><?= gmdate('i\m s\s', $r['time_taken_seconds']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>