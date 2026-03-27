<?php
// lecturer/analytics.php — Test performance analytics

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db         = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$testId     = (int) ($_GET['test_id'] ?? 0);

// ── No test_id: list all tests ────────────────────────────────────────────
if (!$testId) {
    $stmt = $db->prepare("
        SELECT t.*, COUNT(r.id) AS attempts
        FROM tests t
        LEFT JOIN results r ON r.test_id = t.id
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
    ?>
    <div class="page-header">
        <h1>Analytics</h1>
        <p>Select a test to view detailed results.</p>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">My Tests</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Title</th><th>Total Attempts</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tests as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['title']) ?></td>
                    <td><?= $t['attempts'] ?></td>
                    <td><a href="analytics.php?test_id=<?= $t['id'] ?>" class="btn btn-primary btn-sm">View Analytics</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Verify test ownership ─────────────────────────────────────────────────
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

// ── Summary stats — ALL attempts (submitted + timed_out + in_progress) ───
// "total"  = every result row for this test
// "submitted_count" = fully submitted
// "timed_out_count" = auto-submitted when time ran out
// "incomplete_count" = started but never finished (still in_progress)
// "passed" = only where passed flag is set (submitted/timed_out can pass)
$stmt = $db->prepare("
    SELECT
        COUNT(*)                                        AS total,
        SUM(status = 'submitted')                       AS submitted_count,
        SUM(status = 'timed_out')                       AS timed_out_count,
        SUM(status = 'in_progress')                     AS incomplete_count,
        SUM(passed)                                     AS passed,
        ROUND(AVG(CASE WHEN status != 'in_progress' THEN percentage END), 1) AS avg_pct,
        MAX(percentage)                                 AS max_pct,
        MIN(CASE WHEN status != 'in_progress' THEN percentage END) AS min_pct
    FROM results
    WHERE test_id = ?
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Per-student results — ALL statuses, best effort score ────────────────
// For in_progress: tally their saved answers so we show partial marks.
$stmt = $db->prepare("
    SELECT r.*, u.full_name, u.reg_no, u.email
    FROM results r
    JOIN users u ON u.id = r.student_id
    WHERE r.test_id = ?
    ORDER BY
        CASE r.status
            WHEN 'submitted'  THEN 1
            WHEN 'timed_out'  THEN 2
            WHEN 'in_progress' THEN 3
        END,
        r.percentage DESC
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// For in_progress rows, calculate partial score from saved answers
foreach ($results as &$r) {
    if ($r['status'] === 'in_progress') {
        $stmt = $db->prepare("SELECT SUM(marks_awarded) FROM student_answers WHERE result_id = ?");
        $stmt->bind_param('i', $r['id']);
        $stmt->execute();
        $partial = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        $r['score']      = $partial;
        $r['percentage'] = $r['total_marks'] > 0
            ? round($partial / $r['total_marks'] * 100, 2) : 0;
        $r['passed']     = 0; // can't pass without completing
    }
}
unset($r);

// ── Score distribution — only completed attempts (submitted + timed_out) ─
$buckets = ['0–20' => 0, '21–40' => 0, '41–60' => 0, '61–80' => 0, '81–100' => 0];
foreach ($results as $r) {
    if ($r['status'] === 'in_progress') continue; // exclude incomplete from distribution
    $p = (float) $r['percentage'];
    if      ($p <= 20) $buckets['0–20']++;
    elseif  ($p <= 40) $buckets['21–40']++;
    elseif  ($p <= 60) $buckets['41–60']++;
    elseif  ($p <= 80) $buckets['61–80']++;
    else               $buckets['81–100']++;
}
$maxBucket = max($buckets) ?: 1;

// ── Per-question difficulty ───────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT q.question_text, q.marks,
           COUNT(sa.id)      AS attempts,
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
    <p>All attempts — submitted, timed out, and incomplete.</p>
</div>

<!-- Summary stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
        <div class="stat-label">Total Attempts</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['submitted_count'] ?? 0 ?></div>
        <div class="stat-label">Submitted</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['timed_out_count'] ?? 0 ?></div>
        <div class="stat-label">Timed Out</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['incomplete_count'] ?? 0 ?></div>
        <div class="stat-label">Incomplete</div>
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
        <div class="stat-label">Highest</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['min_pct'] ?? 0 ?>%</div>
        <div class="stat-label">Lowest</div>
    </div>
</div>

<!-- Score Distribution (completed only) -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Score Distribution</h2>
        <span class="text-muted" style="font-size:0.82rem;">Submitted &amp; timed-out attempts only</span>
    </div>
    <div class="chart-bar-wrap" style="max-width:600px;">
        <?php foreach ($buckets as $range => $count): ?>
        <div class="chart-bar-row">
            <span class="chart-bar-label"><?= $range ?>%</span>
            <div class="chart-bar-track">
                <div class="chart-bar-fill" style="width:<?= round($count / $maxBucket * 100) ?>%"></div>
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
            <?php foreach ($questionStats as $i => $qs):
                $acc = $qs['attempts'] > 0 ? round($qs['correct_count'] / $qs['attempts'] * 100) : 0;
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td style="font-size:0.88rem;"><?= htmlspecialchars(substr($qs['question_text'], 0, 80)) ?>…</td>
                <td><?= $qs['attempts'] ?></td>
                <td><?= (int)$qs['correct_count'] ?></td>
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

<!-- All results table -->
<?php if (!empty($results)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">All Student Results (<?= count($results) ?>)</h2>
        <a href="export_results.php?test_id=<?= $testId ?>" class="btn btn-outline btn-sm">Export CSV</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Reg No</th>
                    <th>Score</th>
                    <th>%</th>
                    <th>Result</th>
                    <th>Attempt</th>
                    <th>Time Taken</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $rank => $r): ?>
            <tr>
                <td><?= $rank + 1 ?></td>
                <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                <td style="font-size:0.85rem;"><?= htmlspecialchars($r['reg_no'] ?? '—') ?></td>
                <td><?= $r['score'] ?>/<?= $r['total_marks'] ?></td>
                <td>
                    <strong style="color:<?= (float)$r['percentage'] >= ($test['pass_marks'] / $test['total_marks'] * 100) ? '#38a169' : '#e53e3e' ?>">
                        <?= number_format((float)$r['percentage'], 1) ?>%
                    </strong>
                </td>
                <td>
                    <?php if ($r['status'] === 'in_progress'): ?>
                        <span class="badge badge-pending">Incomplete</span>
                    <?php elseif ($r['passed']): ?>
                        <span class="badge badge-active">Pass</span>
                    <?php else: ?>
                        <span class="badge badge-inactive">Fail</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['status'] === 'submitted'): ?>
                        <span class="badge badge-submitted">Submitted</span>
                    <?php elseif ($r['status'] === 'timed_out'): ?>
                        <span class="badge" style="background:#fef3c7;color:#92400e;">Timed Out</span>
                    <?php else: ?>
                        <span class="badge badge-pending">In Progress</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.85rem;">
                    <?= $r['time_taken_seconds'] > 0
                        ? gmdate('i\m s\s', $r['time_taken_seconds'])
                        : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>