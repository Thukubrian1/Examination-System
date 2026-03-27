<?php
// student/result_detail.php — Auto-graded result view
// ============================================================

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('student');

$db        = getDB();
$studentId = (int) $_SESSION['user_id'];
$resultId  = (int) ($_GET['result_id'] ?? 0);

// Fetch result (must belong to this student)
$stmt = $db->prepare("
    SELECT r.*, t.title, t.pass_marks, t.duration_minutes
    FROM results r
    JOIN tests t ON t.id = r.test_id
    WHERE r.id = ? AND r.student_id = ?
");
$stmt->bind_param('ii', $resultId, $studentId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$result) {
    setFlash('error', 'Result not found.');
    header('Location: ' . BASE_URL . '/student/dashboard.php');
    exit;
}

// Fetch answered questions with correct/wrong status
$stmt = $db->prepare("
    SELECT q.question_text, q.marks,
           sa.selected_option_id, sa.is_correct, sa.marks_awarded,
           (SELECT option_text FROM options WHERE id = sa.selected_option_id) AS selected_text,
           (SELECT option_text FROM options WHERE question_id = q.id AND is_correct = 1 LIMIT 1) AS correct_text,
           (SELECT id          FROM options WHERE question_id = q.id AND is_correct = 1 LIMIT 1) AS correct_option_id
    FROM student_answers sa
    JOIN questions q ON q.id = sa.question_id
    WHERE sa.result_id = ?
    ORDER BY q.question_order
");
$stmt->bind_param('i', $resultId);
$stmt->execute();
$answers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pct    = (float) $result['percentage'];
$pageTitle = 'Your Result — ' . $result['title'];
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">
    <a href="dashboard.php" class="btn btn-outline btn-sm">← Dashboard</a>
    <a href="results.php" class="btn btn-primary btn-sm">All My Results</a>
</div>

<div class="page-header">
    <h1><?= htmlspecialchars($result['title']) ?></h1>
    <p>
        Submitted: <?= $result['submitted_at'] ? date('d M Y, H:i', strtotime($result['submitted_at'])) : 'Auto-submitted' ?>
        &nbsp;|&nbsp; Time taken: <?= gmdate('i\m s\s', $result['time_taken_seconds']) ?>
    </p>
</div>

<!-- Score Card -->
<div class="card text-center" style="max-width:500px;margin:0 auto 2rem;">
    <div class="score-circle" style="--pct:<?= $pct ?>">
        <div class="score-inner">
            <span class="score-pct"><?= $pct ?>%</span>
            <span class="score-lbl"><?= $result['score'] ?>/<?= $result['total_marks'] ?> marks</span>
        </div>
    </div>
    <div style="font-size:1.5rem;font-weight:700;color:<?= $result['passed'] ? '#38a169' : '#e53e3e' ?>;">
        <?= $result['passed'] ? '🎉 Passed!' : '😞 Not Passed' ?>
    </div>
    <p class="text-muted mt-1">Pass mark: <?= $result['pass_marks'] ?></p>

    <div class="stats-grid" style="margin-top:1.25rem;">
        <div class="stat-card">
            <div class="stat-number"><?= count(array_filter($answers, fn($a) => $a['is_correct'])) ?></div>
            <div class="stat-label">Correct</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= count(array_filter($answers, fn($a) => !$a['is_correct'])) ?></div>
            <div class="stat-label">Wrong</div>
        </div>
    </div>
</div>

<!-- Answer Review -->
<?php if (!empty($answers)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Answer Review</h2>
        <span class="text-muted" style="font-size:0.85rem;"><?= count($answers) ?> questions answered</span>
    </div>

    <?php foreach ($answers as $i => $a): ?>
    <div style="border-left: 4px solid <?= $a['is_correct'] ? '#38a169' : '#e53e3e' ?>;
                padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 8px 8px 0;
                background: <?= $a['is_correct'] ? '#f0fff4' : '#fff5f5' ?>;">
        <div class="flex-between mb-1">
            <strong style="color:#0d1b2a;font-size:0.92rem;">
                Q<?= $i+1 ?>. <?= htmlspecialchars($a['question_text']) ?>
            </strong>
            <span class="badge <?= $a['is_correct'] ? 'badge-active' : 'badge-inactive' ?>">
                <?= $a['is_correct'] ? '+' . $a['marks_awarded'] . ' mk' : '0 mk' ?>
            </span>
        </div>
        <p style="font-size:0.88rem;margin:0.3rem 0;">
            <strong>Your answer:</strong>
            <span style="color:<?= $a['is_correct'] ? '#276749' : '#9b2c2c' ?>">
                <?= htmlspecialchars($a['selected_text'] ?? '(Not answered)') ?>
            </span>
        </p>
        <?php if (!$a['is_correct'] && $a['correct_text']): ?>
        <p style="font-size:0.88rem;color:#276749;margin:0.2rem 0;">
            <strong>Correct answer:</strong> <?= htmlspecialchars($a['correct_text']) ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>