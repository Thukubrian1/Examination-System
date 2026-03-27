<?php
define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('student');

$db        = getDB();
$studentId = (int) $_SESSION['user_id'];

// ── Auto-expire any in_progress results whose test window has now closed ──
// This runs on every dashboard load so the DB is always consistent.
$db->query("
    UPDATE results r
    JOIN tests t ON t.id = r.test_id
    SET r.status              = 'timed_out',
        r.submitted_at        = NOW(),
        r.score               = COALESCE((
            SELECT SUM(sa.marks_awarded)
            FROM student_answers sa
            WHERE sa.result_id = r.id
        ), 0),
        r.percentage          = ROUND(
            COALESCE((
                SELECT SUM(sa.marks_awarded)
                FROM student_answers sa
                WHERE sa.result_id = r.id
            ), 0) / NULLIF(t.total_marks, 0) * 100, 2),
        r.passed              = CASE WHEN COALESCE((
            SELECT SUM(sa.marks_awarded)
            FROM student_answers sa
            WHERE sa.result_id = r.id
        ), 0) >= t.pass_marks THEN 1 ELSE 0 END,
        r.time_taken_seconds  = TIMESTAMPDIFF(SECOND, r.started_at, NOW())
    WHERE r.student_id = $studentId
      AND r.status     = 'in_progress'
      AND t.end_time   < NOW()
");

// ── Fetch tests ───────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT t.*, u.full_name AS lecturer_name,
           r.id          AS result_id,
           r.status      AS attempt_status,
           r.score,
           r.total_marks AS result_total,
           r.percentage,
           r.passed,
           r.started_at
    FROM tests t
    JOIN users u ON u.id = t.lecturer_id
    LEFT JOIN results r ON r.test_id = t.id AND r.student_id = ?
    WHERE t.is_active = 1
    ORDER BY t.end_time DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$tests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Stats ─────────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT COUNT(*), AVG(percentage), SUM(passed)
    FROM results
    WHERE student_id = ? AND status = 'submitted'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
[$totalAttempts, $avgScore, $totalPassed] = $stmt->get_result()->fetch_row();
$stmt->close();

$pageTitle = 'Student Dashboard';
$serverNow = time();  // passed to JS for clock-skew correction
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
    <p>View available tests and check your results below.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= $totalAttempts ?? 0 ?></div>
        <div class="stat-label">Tests Taken</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalPassed ?? 0 ?></div>
        <div class="stat-label">Passed</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= round($avgScore ?? 0, 1) ?>%</div>
        <div class="stat-label">Avg Score</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Available Tests</h2>
        <a href="results.php" class="btn btn-outline btn-sm">My Results</a>
    </div>

    <?php if (empty($tests)): ?>
        <p class="text-muted text-center" style="padding:2rem 0;">No tests are currently available.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Test</th>
                    <th>Lecturer</th>
                    <th>Duration</th>
                    <th>Marks</th>
                    <th>Window</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="testsTableBody">
            <?php foreach ($tests as $t):
                $now    = time();
                $start  = strtotime($t['start_time']);
                $end    = strtotime($t['end_time']);
                $status = $t['attempt_status'] ?? null;

                // Treat in_progress past end_time as timed_out (DB was already updated above)
                if ($status === 'in_progress' && $now > $end) $status = 'timed_out';

                $testOpen    = ($now >= $start && $now <= $end);
                $testEnded   = ($now >  $end);
                $testPending = ($now <  $start);
            ?>
            <tr data-end="<?= $end ?>"
                data-start="<?= $start ?>"
                data-attempt="<?= htmlspecialchars($status ?? '') ?>"
                data-result-id="<?= (int)($t['result_id'] ?? 0) ?>"
                data-test-id="<?= (int)$t['id'] ?>"
                data-title="<?= htmlspecialchars($t['title'], ENT_QUOTES) ?>"
                data-duration="<?= (int)$t['duration_minutes'] ?>">
                <td>
                    <strong><?= htmlspecialchars($t['title']) ?></strong>
                    <?php if ($t['description']): ?>
                    <br><small class="text-muted"><?= htmlspecialchars(substr($t['description'], 0, 70)) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($t['lecturer_name']) ?></td>
                <td><?= $t['duration_minutes'] ?> min</td>
                <td><?= $t['total_marks'] ?></td>
                <td style="font-size:0.8rem;white-space:nowrap;">
                    <?= date('d M H:i', $start) ?> –<br><?= date('d M H:i', $end) ?>
                </td>
                <td class="status-cell">
                    <?php if ($status === 'submitted' || $status === 'timed_out'): ?>
                        <span class="badge badge-submitted">Completed</span>
                    <?php elseif ($status === 'in_progress' && $testOpen): ?>
                        <span class="badge badge-pending">In Progress</span>
                    <?php elseif ($testPending): ?>
                        <span class="badge badge-pending">Upcoming</span>
                    <?php elseif ($testEnded): ?>
                        <span class="badge badge-inactive">Closed</span>
                    <?php else: ?>
                        <span class="badge badge-active">Open</span>
                    <?php endif; ?>
                </td>
                <td class="action-cell">
                    <?php if ($status === 'submitted' || $status === 'timed_out'): ?>
                        <a href="result_detail.php?result_id=<?= $t['result_id'] ?>"
                           class="btn btn-outline btn-sm">View Result</a>
                    <?php elseif ($status === 'in_progress' && $testOpen): ?>
                        <a href="take_exam.php?test_id=<?= $t['id'] ?>"
                           class="btn btn-amber btn-sm">Continue</a>
                    <?php elseif ($testOpen): ?>
                        <a href="take_exam.php?test_id=<?= $t['id'] ?>"
                           class="btn btn-primary btn-sm"
                           data-confirm="Start exam: <?= htmlspecialchars($t['title']) ?>? (<?= $t['duration_minutes'] ?> min)">
                            Start Exam
                        </a>
                    <?php elseif ($testPending): ?>
                        <span class="text-muted" style="font-size:0.82rem;">Not open yet</span>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:0.82rem;">Closed</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
// ── Live status updater ───────────────────────────────────────────────────
// Updates badges and action buttons in real time as test windows open/close,
// without needing a full page reload. Uses server clock to avoid skew.

const serverNow   = <?= $serverNow ?>;
const clientNow   = Math.floor(Date.now() / 1000);
const clockOffset = serverNow - clientNow;

function now() {
    return Math.floor(Date.now() / 1000) + clockOffset;
}

function updateRow(row) {
    const end      = parseInt(row.dataset.end);
    const start    = parseInt(row.dataset.start);
    const attempt  = row.dataset.attempt;   // 'submitted'|'timed_out'|'in_progress'|''
    const resultId = row.dataset.resultId;
    const testId   = row.dataset.testId;
    const title    = row.dataset.title;
    const duration = row.dataset.duration;
    const t        = now();

    const testOpen    = t >= start && t <= end;
    const testEnded   = t > end;
    const testPending = t < start;

    const statusCell = row.querySelector('.status-cell');
    const actionCell = row.querySelector('.action-cell');

    // ── Already completed — status is permanent ──
    if (attempt === 'submitted' || attempt === 'timed_out') {
        statusCell.innerHTML = '<span class="badge badge-submitted">Completed</span>';
        actionCell.innerHTML = resultId > 0
            ? `<a href="result_detail.php?result_id=${resultId}" class="btn btn-outline btn-sm">View Result</a>`
            : '';
        return;
    }

    // ── In progress but window just closed → reload so PHP auto-submits it ──
    if (attempt === 'in_progress' && testEnded) {
        statusCell.innerHTML = '<span class="badge badge-inactive">Closed</span>';
        actionCell.innerHTML = '<span class="text-muted" style="font-size:0.82rem;">Closed</span>';
        // Brief delay then reload so the PHP auto-expire UPDATE runs
        setTimeout(() => location.reload(), 1000);
        return;
    }

    if (testEnded) {
        statusCell.innerHTML = '<span class="badge badge-inactive">Closed</span>';
        actionCell.innerHTML = '<span class="text-muted" style="font-size:0.82rem;">Closed</span>';
        return;
    }

    if (testPending) {
        statusCell.innerHTML = '<span class="badge badge-pending">Upcoming</span>';
        actionCell.innerHTML = '<span class="text-muted" style="font-size:0.82rem;">Not open yet</span>';
        return;
    }

    // ── Test is open ──
    if (attempt === 'in_progress') {
        statusCell.innerHTML = '<span class="badge badge-pending">In Progress</span>';
        actionCell.innerHTML = `<a href="take_exam.php?test_id=${testId}" class="btn btn-amber btn-sm">Continue</a>`;
    } else {
        statusCell.innerHTML = '<span class="badge badge-active">Open</span>';
        actionCell.innerHTML = `<a href="take_exam.php?test_id=${testId}" class="btn btn-primary btn-sm"
            onclick="return confirm('Start exam: ${title}? (${duration} min)')">Start Exam</a>`;
    }
}

function updateAllRows() {
    document.querySelectorAll('#testsTableBody tr[data-end]').forEach(updateRow);
}

updateAllRows();
setInterval(updateAllRows, 5000);   // re-check every 5 s
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>