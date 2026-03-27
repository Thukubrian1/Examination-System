<?php
define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('student');

$db        = getDB();
$studentId = (int) $_SESSION['user_id'];
$testId    = (int) ($_GET['test_id'] ?? 0);

// ---- Load test (active + start_time passed; end_time is NOT checked here) ----
// We deliberately omit AND end_time >= NOW() because:
//   (a) a student with an in_progress attempt past end_time should be auto-submitted,
//       not shown "not available"
//   (b) a new student hitting the page after end_time gets rejected below
$stmt = $db->prepare("
    SELECT * FROM tests
    WHERE id = ? AND is_active = 1 AND start_time <= NOW()
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test) {
    setFlash('error', 'This test is not available.');
    header('Location: ' . BASE_URL . '/student/dashboard.php');
    exit;
}

// ---- Check for existing result ----
$stmt = $db->prepare("SELECT * FROM results WHERE student_id = ? AND test_id = ?");
$stmt->bind_param('ii', $studentId, $testId);
$stmt->execute();
$existingResult = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingResult && in_array($existingResult['status'], ['submitted', 'timed_out'])) {
    setFlash('info', 'You have already submitted this test.');
    header('Location: ' . BASE_URL . '/student/result_detail.php?result_id=' . $existingResult['id']);
    exit;
}

// ---- Reject new attempts after test window has closed ----
// Only block NEW students (no existing attempt). In-progress students are
// handled below — they get auto-submitted with whatever they answered.
$testEndTime  = strtotime($test['end_time']);
$nowTs        = time();
if (!$existingResult && $nowTs > $testEndTime) {
    setFlash('error', 'This test has closed.');
    header('Location: ' . BASE_URL . '/student/dashboard.php');
    exit;
}

// ---- Create or resume result record ----------------------------------------
// Use INSERT IGNORE so that if a duplicate row already exists (same student+test),
// we simply do nothing and then re-fetch the existing row.
// This makes the page fully idempotent on refresh — no duplicate records.
$stmt = $db->prepare("
    INSERT IGNORE INTO results (student_id, test_id, total_marks, started_at, status)
    VALUES (?, ?, ?, NOW(), 'in_progress')
");
$stmt->bind_param('iii', $studentId, $testId, $test['total_marks']);
$stmt->execute();
$stmt->close();

// Always re-fetch from DB so started_at is the authoritative DB value,
// not time() which shifts on every page load.
$stmt = $db->prepare("SELECT * FROM results WHERE student_id = ? AND test_id = ? LIMIT 1");
$stmt->bind_param('ii', $studentId, $testId);
$stmt->execute();
$resultRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$resultId  = (int) $resultRow['id'];
$startedAt = strtotime($resultRow['started_at']);  // fixed DB timestamp, never changes

// ---- Calculate remaining time ----
// $testEndTime and $nowTs already declared above.
// remaining = MIN(personal duration left, seconds until test window closes).
// e.g. 40-min test, starts at 9:50 PM closing at 10:00 PM → student gets 10 min.
$elapsed             = $nowTs - $startedAt;
$durationSecs        = $test['duration_minutes'] * 60;
$remainingByDuration = max(0, $durationSecs - $elapsed);        // personal countdown
$remainingByWindow   = max(0, $testEndTime - $nowTs);           // window deadline
$remaining           = min($remainingByDuration, $remainingByWindow); // the tighter limit
$jsEndTime           = $testEndTime;  // passed to JS

// ---- Auto-submit server-side if time has already elapsed ----
if ($remaining <= 0 && $resultRow && $resultRow['status'] === 'in_progress') {
    // Tally whatever answers were saved
    $stmt = $db->prepare("SELECT SUM(marks_awarded) FROM student_answers WHERE result_id = ?");
    $stmt->bind_param('i', $resultId);
    $stmt->execute();
    $score = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $pct    = $test['total_marks'] > 0 ? round($score / $test['total_marks'] * 100, 2) : 0;
    $passed = $score >= $test['pass_marks'] ? 1 : 0;
    $taken  = $elapsed;

    $stmt = $db->prepare("
        UPDATE results
        SET score = ?, percentage = ?, passed = ?,
            submitted_at = NOW(), status = 'timed_out', time_taken_seconds = ?
        WHERE id = ?
    ");
    $stmt->bind_param('idiis', $score, $pct, $passed, $taken, $resultId);
    $stmt->execute();
    $stmt->close();

    setFlash('error', 'Time is up! Your exam has been auto-submitted.');
    header('Location: ' . BASE_URL . '/student/result_detail.php?result_id=' . $resultId);
    exit;
}

// ---- Load questions ----
$stmt = $db->prepare("SELECT * FROM questions WHERE test_id = ? ORDER BY question_order");
$stmt->bind_param('i', $testId);
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($test['randomize_questions']) {
    srand($studentId + $testId);
    shuffle($questions);
}

foreach ($questions as &$q) {
    $stmt = $db->prepare("SELECT * FROM options WHERE question_id = ?");
    $stmt->bind_param('i', $q['id']);
    $stmt->execute();
    $q['options'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
unset($q);

// ---- Fetch already-saved answers ----
$stmt = $db->prepare("SELECT question_id, selected_option_id FROM student_answers WHERE result_id = ?");
$stmt->bind_param('i', $resultId);
$stmt->execute();
$savedRows    = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$savedAnswers = array_column($savedRows, 'selected_option_id', 'question_id');

// ============================================================
// POST: Handle answer save (AJAX) or final submit
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        echo json_encode(['error' => 'Invalid token']); exit;
    }

    $action = $_POST['action'] ?? '';

    // ---- AJAX: save a single answer ----
    if ($action === 'save_answer') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $optionId   = (int) ($_POST['option_id']   ?? 0);

        $stmt = $db->prepare("SELECT is_correct FROM options WHERE id = ? AND question_id = ?");
        $stmt->bind_param('ii', $optionId, $questionId);
        $stmt->execute();
        $opt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$opt) { echo json_encode(['error' => 'Invalid option']); exit; }

        $isCorrect = $opt['is_correct'];

        $stmt = $db->prepare("SELECT marks FROM questions WHERE id = ?");
        $stmt->bind_param('i', $questionId);
        $stmt->execute();
        $qRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $marksAwarded = $isCorrect ? ($qRow['marks'] ?? 1) : 0;

        $stmt = $db->prepare("
            INSERT INTO student_answers (result_id, question_id, selected_option_id, is_correct, marks_awarded)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE selected_option_id = VALUES(selected_option_id),
                                    is_correct         = VALUES(is_correct),
                                    marks_awarded      = VALUES(marks_awarded)
        ");
        $stmt->bind_param('iiiii', $resultId, $questionId, $optionId, $isCorrect, $marksAwarded);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['ok' => true, 'is_correct' => (bool) $isCorrect]);
        exit;
    }

    // ---- Final submit (manual or auto) ----
    if ($action === 'submit') {
        $stmt = $db->prepare("SELECT SUM(marks_awarded) FROM student_answers WHERE result_id = ?");
        $stmt->bind_param('i', $resultId);
        $stmt->execute();
        $score = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $pct    = $test['total_marks'] > 0 ? round($score / $test['total_marks'] * 100, 2) : 0;
        $passed = $score >= $test['pass_marks'] ? 1 : 0;
        $taken  = time() - $startedAt;

        $stmt = $db->prepare("
            UPDATE results
            SET score = ?, percentage = ?, passed = ?,
                submitted_at = NOW(), status = 'submitted', time_taken_seconds = ?
            WHERE id = ?
        ");
        $stmt->bind_param('idiis', $score, $pct, $passed, $taken, $resultId);
        $stmt->execute();
        $stmt->close();

        header('Location: ' . BASE_URL . '/student/result_detail.php?result_id=' . $resultId);
        exit;
    }
}

$csrfToken  = generateCsrfToken();
$pageTitle  = 'Exam: ' . $test['title'];
// Pass both the computed remaining seconds AND the absolute end timestamp to JS
// so the client can enforce both constraints independently.
include __DIR__ . '/../includes/header.php';
?>

<div class="exam-layout">
    <!-- Questions Panel -->
    <div>
        <div style="margin-bottom:1rem;">
            <h2 style="font-family:'DM Serif Display',serif;color:#0d1b2a;"><?= htmlspecialchars($test['title']) ?></h2>
            <p class="text-muted">Answer all questions. Your answers are saved automatically.</p>
        </div>

        <form id="examForm" method="POST" action="take_exam.php?test_id=<?= $testId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action"     value="submit">

            <?php foreach ($questions as $qi => $q): ?>
            <div class="exam-question" id="question-<?= $qi ?>" style="<?= $qi > 0 ? 'display:none;' : '' ?>">
                <div class="question-num">Question <?= $qi+1 ?> of <?= count($questions) ?> — <?= $q['marks'] ?> mark<?= $q['marks']>1?'s':'' ?></div>
                <div class="question-text"><?= htmlspecialchars($q['question_text']) ?></div>

                <ul class="options-list" data-question-id="<?= $q['id'] ?>">
                    <?php foreach ($q['options'] as $opt): ?>
                    <li class="option-item <?= (($savedAnswers[$q['id']] ?? null) == $opt['id']) ? 'selected' : '' ?>">
                        <label>
                            <input type="radio"
                                   name="q_<?= $q['id'] ?>"
                                   value="<?= $opt['id'] ?>"
                                   data-question-id="<?= $q['id'] ?>"
                                   data-option-id="<?= $opt['id'] ?>"
                                   data-question-idx="<?= $qi ?>"
                                   <?= (($savedAnswers[$q['id']] ?? null) == $opt['id']) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($opt['option_text']) ?>
                        </label>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <div class="flex-between mt-3">
                    <?php if ($qi > 0): ?>
                        <button type="button" class="btn btn-outline" onclick="goToQuestion(<?= $qi-1 ?>)">← Previous</button>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>

                    <?php if ($qi < count($questions) - 1): ?>
                        <button type="button" class="btn btn-primary" onclick="goToQuestion(<?= $qi+1 ?>)">Next →</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-amber" id="submitBtn"
                                data-confirm="Submit your exam? You cannot change answers after submission.">
                            Submit Exam
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </form>
    </div>

    <!-- Sidebar -->
    <div class="exam-sidebar">
        <div class="timer-box" id="timerBox">
            <div class="timer-label">Time Remaining</div>
            <div class="timer-value" id="timerDisplay">--:--</div>
            <div style="font-size:0.7rem;color:var(--gray-400);margin-top:0.3rem;" id="timerNote"></div>
        </div>

        <div class="q-nav">
            <div class="q-nav-title">Questions</div>
            <div class="q-grid" id="qGrid">
                <?php foreach ($questions as $qi => $q): ?>
                <button type="button"
                        class="q-btn <?= isset($savedAnswers[$q['id']]) ? 'answered' : '' ?> <?= $qi === 0 ? 'current' : '' ?>"
                        id="qbtn-<?= $qi ?>"
                        onclick="goToQuestion(<?= $qi ?>)">
                    <?= $qi+1 ?>
                </button>
                <?php endforeach; ?>
            </div>
            <p class="text-muted mt-2" style="font-size:0.75rem;">
                <span style="display:inline-block;width:12px;height:12px;background:#e8a020;border-radius:2px;"></span> Answered
            </p>
        </div>
    </div>
</div>

<script>
// ============================================================
// Exam timer — enforces BOTH the per-student duration limit
// AND the absolute test window end time, taking whichever is
// smaller. This means a student who starts late only gets the
// time remaining before the window closes.
// ============================================================

// PHP hands us:
//   remaining  — seconds left as of when the page was served
//                (already = min(duration_remaining, window_remaining))
//   jsEndTime  — Unix timestamp (seconds) when the test window closes
//   serverNow  — Unix timestamp when PHP generated the page
// We use serverNow vs Date.now() to correct for client/server clock skew.

// remaining is now computed dynamically every tick from startedAtServer + durationSecs
const jsEndTime    = <?= $jsEndTime ?>;         // absolute close time (Unix seconds)
const serverNow    = <?= time() ?>;
const clientNow    = Math.floor(Date.now() / 1000);
const clockOffset  = serverNow - clientNow;     // add this to Date.now()/1000 to get server time

let submitting     = false;                     // guard against double-submit
let currentQ       = 0;
const csrfToken    = <?= json_encode($csrfToken) ?>;
const testId       = <?= $testId ?>;
const answered     = new Set(<?= json_encode(array_keys($savedAnswers)) ?>);

// ---- Helpers ----
function serverTime() {
    return Math.floor(Date.now() / 1000) + clockOffset;
}

function formatTime(s) {
    const m   = Math.floor(s / 60);
    const sec = s % 60;
    return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
}

function autoSubmit() {
    if (submitting) return;
    submitting = true;
    // Disable the submit button to prevent double-click
    const btn = document.getElementById('submitBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
    document.querySelector('#examForm [name="action"]').value = 'submit';
    document.getElementById('examForm').submit();
}

// ---- Timer tick ----
const timerDisplay = document.getElementById('timerDisplay');
const timerBox     = document.getElementById('timerBox');
const timerNote    = document.getElementById('timerNote');

// startedAtServer: the Unix timestamp (server time) when the student started.
// Derived from PHP's $startedAt so it never shifts on refresh.
const startedAtServer = <?= $startedAt ?>;
const durationSecs    = <?= $test['duration_minutes'] * 60 ?>;

function tick() {
    if (submitting) return;

    const now = serverTime();

    // Re-derive both constraints from authoritative values every tick.
    // This means the timer is always correct even after tab sleep or refresh.
    const elapsed         = now - startedAtServer;
    const byDuration      = Math.max(0, durationSecs - elapsed);  // personal timer
    const byWindow        = Math.max(0, jsEndTime - now);          // wall-clock deadline
    const effectiveRemaining = Math.min(byDuration, byWindow);     // tighter of the two

    if (effectiveRemaining <= 0) {
        timerDisplay.textContent = '00:00';
        timerBox.classList.add('timer-warning');
        autoSubmit();
        return;
    }

    timerDisplay.textContent = formatTime(effectiveRemaining);

    // Show a note when the window deadline is the binding constraint
    if (byWindow < byDuration) {
        timerNote.textContent = 'Test closes at ' +
            new Date(jsEndTime * 1000).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    } else {
        timerNote.textContent = '';
    }

    // Warn in last 5 minutes
    if (effectiveRemaining <= 300) {
        timerBox.classList.add('timer-warning');
    }
}

tick();
const timerInterval = setInterval(tick, 1000);

// ---- Question navigation ----
function goToQuestion(idx) {
    document.getElementById('question-' + currentQ).style.display = 'none';
    document.getElementById('qbtn-' + currentQ).classList.remove('current');
    currentQ = idx;
    document.getElementById('question-' + currentQ).style.display = 'block';
    document.getElementById('qbtn-' + currentQ).classList.add('current');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ---- Auto-save on selection ----
document.querySelectorAll('input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function () {
        const questionId = this.dataset.questionId;
        const optionId   = this.dataset.optionId;
        const qi         = parseInt(this.dataset.questionIdx);

        const list = this.closest('.options-list');
        list.querySelectorAll('.option-item').forEach(li => li.classList.remove('selected'));
        this.closest('.option-item').classList.add('selected');

        answered.add(parseInt(questionId));
        document.getElementById('qbtn-' + qi).classList.add('answered');

        const fd = new FormData();
        fd.append('csrf_token',  csrfToken);
        fd.append('action',      'save_answer');
        fd.append('question_id', questionId);
        fd.append('option_id',   optionId);

        fetch('take_exam.php?test_id=' + testId, { method: 'POST', body: fd })
            .then(r => r.json())
            .catch(() => {});
    });
});

// ---- Manual submit confirmation (skip when auto-submitting) ----
document.getElementById('examForm').addEventListener('submit', function (e) {
    if (submitting) return;   // already auto-submitting, let it through
    const msg = document.getElementById('submitBtn')?.dataset.confirm;
    if (msg && !confirm(msg)) { e.preventDefault(); }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>