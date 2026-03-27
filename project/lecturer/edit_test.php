<?php
// lecturer/edit_test.php — Edit an existing test (details + questions)

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db           = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$testId       = (int) ($_GET['id'] ?? 0);
$errors       = [];

// ── Verify ownership ──
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

// ── Check if any students have submitted — warn but still allow edits ──
$stmt = $db->prepare("SELECT COUNT(*) FROM results WHERE test_id = ? AND status = 'submitted'");
$stmt->bind_param('i', $testId);
$stmt->execute();
$submissionCount = (int) $stmt->get_result()->fetch_row()[0];
$stmt->close();

// ════════════════════════════════════════════════════════════
// POST: Save edits
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrf()) {
        $errors[] = 'Invalid form submission.';
    } else {
        $action = $_POST['action'] ?? 'save_all';

        // ── Delete a single question ──
        if ($action === 'delete_question') {
            $qid = (int) ($_POST['question_id'] ?? 0);
            $stmt = $db->prepare(
                "DELETE q FROM questions q
                 JOIN tests t ON t.id = q.test_id
                 WHERE q.id = ? AND t.lecturer_id = ?"
            );
            $stmt->bind_param('ii', $qid, $lecturerId);
            $stmt->execute();
            $stmt->close();

            // Recalculate total marks
            $stmt = $db->prepare("SELECT COALESCE(SUM(marks),0) FROM questions WHERE test_id = ?");
            $stmt->bind_param('i', $testId);
            $stmt->execute();
            $newTotal = (int) $stmt->get_result()->fetch_row()[0];
            $stmt->close();

            $stmt = $db->prepare("UPDATE tests SET total_marks = ? WHERE id = ?");
            $stmt->bind_param('ii', $newTotal, $testId);
            $stmt->execute();
            $stmt->close();

            setFlash('success', 'Question deleted.');
            header('Location: ' . BASE_URL . '/lecturer/edit_test.php?id=' . $testId);
            exit;
        }

        // ── Save all (test details + questions) ──
        $title       = trim($_POST['title']       ?? '');
        $description = trim($_POST['description'] ?? '');
        $duration    = (int) ($_POST['duration']  ?? 0);
        $pass_marks  = (int) ($_POST['pass_marks'] ?? 0);
        $start_time  = trim($_POST['start_time']  ?? '');
        $end_time    = trim($_POST['end_time']    ?? '');
        $randomize   = isset($_POST['randomize']) ? 1 : 0;

        if (empty($title))                    $errors[] = 'Test title is required.';
        if ($duration < 5 || $duration > 300) $errors[] = 'Duration must be 5–300 minutes.';
        if (empty($start_time))               $errors[] = 'Start time is required.';
        if (empty($end_time))                 $errors[] = 'End time is required.';
        if (!empty($start_time) && !empty($end_time) &&
            strtotime($start_time) >= strtotime($end_time))
            $errors[] = 'End time must be after start time.';

        // Validate questions
        $questions = $_POST['questions'] ?? [];
        $totalMarks = 0;
        foreach ($questions as $qi => $q) {
            $qText = trim($q['text'] ?? '');
            $qMark = (int) ($q['marks'] ?? 1);
            if (empty($qText))  $errors[] = 'Question ' . ($qi+1) . ' text is required.';
            if ($qMark < 1)     $errors[] = 'Question ' . ($qi+1) . ' must have at least 1 mark.';
            $totalMarks += $qMark;
            $correctCount = 0;
            foreach ($q['options'] ?? [] as $opt) {
                if (!empty($opt['correct'])) $correctCount++;
            }
            if (count($q['options'] ?? []) < 2)
                $errors[] = 'Question ' . ($qi+1) . ' needs at least 2 options.';
            if ($correctCount !== 1)
                $errors[] = 'Question ' . ($qi+1) . ' must have exactly one correct answer.';
        }

        if ($pass_marks < 0 || $pass_marks > $totalMarks)
            $errors[] = "Pass marks must be between 0 and $totalMarks.";

        if (empty($errors)) {
            $db->begin_transaction();
            try {
                // Update test header
                $stmt = $db->prepare("
                    UPDATE tests
                    SET title=?, description=?, duration_minutes=?, total_marks=?,
                        pass_marks=?, start_time=?, end_time=?, randomize_questions=?
                    WHERE id=? AND lecturer_id=?
                ");
                $stmt->bind_param('ssiiissiis',
                    $title, $description, $duration, $totalMarks,
                    $pass_marks, $start_time, $end_time, $randomize,
                    $testId, $lecturerId
                );
                $stmt->execute();
                $stmt->close();

                // Process each question
                foreach ($questions as $order => $q) {
                    $qText  = trim($q['text']);
                    $qMark  = (int) $q['marks'];
                    $qOrder = $order + 1;
                    $qId    = (int) ($q['id'] ?? 0); // 0 = new question

                    if ($qId > 0) {
                        // Update existing question
                        $stmt = $db->prepare(
                            "UPDATE questions SET question_text=?, marks=?, question_order=?
                             WHERE id=? AND test_id=?"
                        );
                        $stmt->bind_param('siiii', $qText, $qMark, $qOrder, $qId, $testId);
                        $stmt->execute();
                        $stmt->close();

                        // Delete old options and re-insert fresh ones
                        $stmt = $db->prepare("DELETE FROM options WHERE question_id=?");
                        $stmt->bind_param('i', $qId);
                        $stmt->execute();
                        $stmt->close();

                    } else {
                        // New question
                        $stmt = $db->prepare(
                            "INSERT INTO questions (test_id, question_text, marks, question_order)
                             VALUES (?,?,?,?)"
                        );
                        $stmt->bind_param('isii', $testId, $qText, $qMark, $qOrder);
                        $stmt->execute();
                        $qId = $db->insert_id;
                        $stmt->close();
                    }

                    // Insert options
                    foreach ($q['options'] as $opt) {
                        $optText   = trim($opt['text'] ?? '');
                        $isCorrect = isset($opt['correct']) ? 1 : 0;
                        if (empty($optText)) continue;
                        $stmt = $db->prepare(
                            "INSERT INTO options (question_id, option_text, is_correct)
                             VALUES (?,?,?)"
                        );
                        $stmt->bind_param('isi', $qId, $optText, $isCorrect);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $db->commit();
                setFlash('success', 'Test "' . $title . '" saved successfully.');
                header('Location: ' . BASE_URL . '/lecturer/edit_test.php?id=' . $testId);
                exit;

            } catch (Exception $e) {
                $db->rollback();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ── Load current questions (or use POST data on error) ──
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

// If POST had errors, use submitted values for test header fields
if (!empty($errors) && !empty($_POST)) {
    $test['title']               = $_POST['title']       ?? $test['title'];
    $test['description']         = $_POST['description'] ?? $test['description'];
    $test['duration_minutes']    = $_POST['duration']    ?? $test['duration_minutes'];
    $test['pass_marks']          = $_POST['pass_marks']  ?? $test['pass_marks'];
    $test['start_time']          = $_POST['start_time']  ?? $test['start_time'];
    $test['end_time']            = $_POST['end_time']    ?? $test['end_time'];
    $test['randomize_questions'] = isset($_POST['randomize']) ? 1 : 0;
}

$csrfToken = generateCsrfToken();
$pageTitle  = 'Edit Test: ' . $test['title'];
include __DIR__ . '/../includes/header.php';
?>

<style>
.question-block {
    border: 1.5px solid var(--gray-200);
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
    background: var(--white);
    transition: border-color 0.2s;
}
.question-block:hover { border-color: var(--amber); }
.question-block.is-new { border-color: #68d391; background: #f0fff4; }

.option-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.6rem;
}
.option-row input[type="radio"] {
    accent-color: var(--amber);
    width: 16px; height: 16px; flex-shrink: 0; cursor: pointer;
}
.option-row .form-control { flex: 1; }

.q-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.q-label {
    font-weight: 700;
    color: var(--navy);
    font-size: 0.9rem;
}
.q-label .q-num { color: var(--amber); }
.q-label .q-badge {
    font-size: 0.7rem;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 20px;
    padding: 0.15rem 0.5rem;
    margin-left: 0.5rem;
    font-weight: 600;
}
.q-label .q-badge.new {
    background: #d1fae5;
    color: #065f46;
}

.form-section-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--gray-400);
    margin: 1.5rem 0 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.warn-banner {
    background: #fffbeb;
    border: 1.5px solid #fbbf24;
    border-radius: var(--radius);
    padding: 0.85rem 1.2rem;
    margin-bottom: 1.5rem;
    font-size: 0.88rem;
    color: #92400e;
}
</style>

<div class="flex-between mb-2">
    <a href="<?= BASE_URL ?>/lecturer/view_test.php?id=<?= $testId ?>"
       class="btn btn-outline btn-sm">← Back to Test</a>
    <a href="<?= BASE_URL ?>/lecturer/analytics.php?test_id=<?= $testId ?>"
       class="btn btn-primary btn-sm">View Analytics</a>
</div>

<div class="page-header">
    <h1>Edit Test</h1>
    <p>Update test details, questions, and answer options below.</p>
</div>

<?php if ($submissionCount > 0): ?>
<div class="warn-banner">
    ⚠️ <strong><?= $submissionCount ?> student<?= $submissionCount > 1 ? 's have' : ' has' ?> already submitted</strong>
    this test. Editing questions may affect result accuracy.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="flash flash-error">
    <strong>Please fix the following:</strong><br>
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
</div>
<?php endif; ?>

<form method="POST" action="<?= BASE_URL ?>/lecturer/edit_test.php?id=<?= $testId ?>" id="editTestForm">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" name="action" value="save_all">

<!-- ── Test Details ── -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Test Details</h2>
    </div>

    <div class="form-group">
        <label class="form-label" for="title">Test Title *</label>
        <input type="text" id="title" name="title" class="form-control"
               value="<?= htmlspecialchars($test['title']) ?>" required maxlength="200">
    </div>

    <div class="form-group">
        <label class="form-label" for="description">Description</label>
        <textarea id="description" name="description" class="form-control" rows="3"
                  placeholder="Brief description..."><?= htmlspecialchars($test['description'] ?? '') ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label" for="duration">Duration (minutes) *</label>
            <input type="number" id="duration" name="duration" class="form-control"
                   value="<?= (int) $test['duration_minutes'] ?>" min="5" max="300" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="pass_marks">Pass Marks *</label>
            <input type="number" id="pass_marks" name="pass_marks" class="form-control"
                   value="<?= (int) $test['pass_marks'] ?>" min="0" required>
            <span class="form-hint" id="totalMarksHint">
                Total marks calculated from questions below.
            </span>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label" for="start_time">Available From *</label>
            <input type="datetime-local" id="start_time" name="start_time" class="form-control"
                   value="<?= date('Y-m-d\TH:i', strtotime($test['start_time'])) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="end_time">Available Until *</label>
            <input type="datetime-local" id="end_time" name="end_time" class="form-control"
                   value="<?= date('Y-m-d\TH:i', strtotime($test['end_time'])) ?>" required>
        </div>
    </div>

    <div class="form-group">
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
            <input type="checkbox" name="randomize"
                   <?= $test['randomize_questions'] ? 'checked' : '' ?>>
            <span class="form-label" style="margin-bottom:0;">Randomize question order per student</span>
        </label>
    </div>
</div>

<!-- ── Questions ── -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            Questions
            <span id="qCountBadge" style="font-size:0.8rem;font-weight:600;
                color:var(--amber);margin-left:0.5rem;">
                (<?= count($questions) ?>)
            </span>
        </h2>
        <button type="button" class="btn btn-amber btn-sm" id="addQuestionBtn">
            + Add Question
        </button>
    </div>

    <div id="questionsContainer">
    <?php foreach ($questions as $qi => $q): ?>
    <div class="question-block" id="qblock-<?= $qi ?>" data-index="<?= $qi ?>">
        <input type="hidden" name="questions[<?= $qi ?>][id]" value="<?= $q['id'] ?>">

        <div class="q-header">
            <span class="q-label">
                <span class="q-num">Q<span class="q-num-val"><?= $qi + 1 ?></span>.</span>
                <span class="q-badge">Existing</span>
            </span>
            <div class="flex gap-1">
                <!-- Delete this question (server-side) -->
                <button type="button" class="btn btn-danger btn-sm"
                        onclick="deleteQuestion(<?= $q['id'] ?>, '<?= htmlspecialchars(addslashes(substr($q['question_text'], 0, 40))) ?>...')">
                    🗑 Delete
                </button>
            </div>
        </div>

        <div class="form-row" style="grid-template-columns:1fr auto;">
            <div class="form-group" style="grid-column:1;">
                <label class="form-label">Question Text *</label>
                <textarea name="questions[<?= $qi ?>][text]" class="form-control q-text"
                          rows="2" required><?= htmlspecialchars($q['question_text']) ?></textarea>
            </div>
            <div class="form-group" style="width:100px;">
                <label class="form-label">Marks</label>
                <input type="number" name="questions[<?= $qi ?>][marks]" class="form-control q-marks"
                       value="<?= (int) $q['marks'] ?>" min="1" max="10">
            </div>
        </div>

        <p class="form-label" style="margin-bottom:0.6rem;">
            Options <span style="font-weight:400;color:var(--gray-400);">(select the correct one)</span>
        </p>
        <div class="options-container">
        <?php foreach ($q['options'] as $oi => $opt): ?>
            <div class="option-row">
                <input type="radio"
                       name="questions[<?= $qi ?>][correct_option]"
                       value="<?= $oi ?>"
                       <?= $opt['is_correct'] ? 'checked' : '' ?>>
                <input type="text"
                       name="questions[<?= $qi ?>][options][<?= $oi ?>][text]"
                       class="form-control"
                       value="<?= htmlspecialchars($opt['option_text']) ?>"
                       placeholder="Option <?= chr(65 + $oi) ?>"
                       <?= $oi < 2 ? 'required' : '' ?>>
                <!-- Hidden: is this option correct? Set by JS on submit -->
            </div>
        <?php endforeach; ?>
        <!-- Pad to 4 options if fewer exist -->
        <?php for ($oi = count($q['options']); $oi < 4; $oi++): ?>
            <div class="option-row">
                <input type="radio"
                       name="questions[<?= $qi ?>][correct_option]"
                       value="<?= $oi ?>">
                <input type="text"
                       name="questions[<?= $qi ?>][options][<?= $oi ?>][text]"
                       class="form-control"
                       placeholder="Option <?= chr(65 + $oi) ?>">
            </div>
        <?php endfor; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- ── Actions ── -->
<div class="flex-between mt-2" style="margin-bottom:2rem;">
    <a href="<?= BASE_URL ?>/lecturer/view_test.php?id=<?= $testId ?>"
       class="btn btn-outline">Cancel</a>
    <button type="submit" class="btn btn-primary" id="saveBtn">
        💾 Save All Changes
    </button>
</div>

</form>

<!-- Hidden form for server-side question delete -->
<form method="POST" action="<?= BASE_URL ?>/lecturer/edit_test.php?id=<?= $testId ?>"
      id="deleteQForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="delete_question">
    <input type="hidden" name="question_id" id="deleteQId">
</form>

<!-- Question template for JS-added questions -->
<template id="newQuestionTemplate">
    <div class="question-block is-new" data-index="NEW_IDX">
        <input type="hidden" name="questions[NEW_IDX][id]" value="0">
        <div class="q-header">
            <span class="q-label">
                <span class="q-num">Q<span class="q-num-val"></span>.</span>
                <span class="q-badge new">New</span>
            </span>
            <button type="button" class="btn btn-danger btn-sm remove-new-q">✕ Remove</button>
        </div>
        <div class="form-row" style="grid-template-columns:1fr auto;">
            <div class="form-group" style="grid-column:1;">
                <label class="form-label">Question Text *</label>
                <textarea name="questions[NEW_IDX][text]" class="form-control q-text"
                          rows="2" required placeholder="Enter your question..."></textarea>
            </div>
            <div class="form-group" style="width:100px;">
                <label class="form-label">Marks</label>
                <input type="number" name="questions[NEW_IDX][marks]" class="form-control q-marks"
                       value="1" min="1" max="10">
            </div>
        </div>
        <p class="form-label" style="margin-bottom:0.6rem;">
            Options <span style="font-weight:400;color:var(--gray-400);">(select the correct one)</span>
        </p>
        <div class="options-container">
            <div class="option-row">
                <input type="radio" name="questions[NEW_IDX][correct_option]" value="0">
                <input type="text" name="questions[NEW_IDX][options][0][text]"
                       class="form-control" placeholder="Option A" required>
            </div>
            <div class="option-row">
                <input type="radio" name="questions[NEW_IDX][correct_option]" value="1">
                <input type="text" name="questions[NEW_IDX][options][1][text]"
                       class="form-control" placeholder="Option B" required>
            </div>
            <div class="option-row">
                <input type="radio" name="questions[NEW_IDX][correct_option]" value="2">
                <input type="text" name="questions[NEW_IDX][options][2][text]"
                       class="form-control" placeholder="Option C">
            </div>
            <div class="option-row">
                <input type="radio" name="questions[NEW_IDX][correct_option]" value="3">
                <input type="text" name="questions[NEW_IDX][options][3][text]"
                       class="form-control" placeholder="Option D">
            </div>
        </div>
    </div>
</template>

<script>
// ── Convert radio "correct_option" selection into hidden is_correct fields on submit ──
document.getElementById('editTestForm').addEventListener('submit', function () {
    document.querySelectorAll('.question-block').forEach(function (block) {
        const idx       = block.dataset.index;
        const selected  = block.querySelector('input[type="radio"]:checked');
        const optRows   = block.querySelectorAll('.options-container .option-row');
        optRows.forEach(function (row, oi) {
            // Remove any previously injected hidden inputs
            row.querySelectorAll('input.correct-flag').forEach(el => el.remove());
            if (selected && parseInt(selected.value) === oi) {
                const hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = `questions[${idx}][options][${oi}][correct]`;
                hidden.value = '1';
                hidden.className = 'correct-flag';
                row.appendChild(hidden);
            }
        });
    });
    updateTotalMarks(); // final sync
});

// ── Add new question ──
let newQCounter = 9000; // high index to avoid collision with existing question indices
document.getElementById('addQuestionBtn').addEventListener('click', function () {
    const tmpl  = document.getElementById('newQuestionTemplate');
    const clone = tmpl.content.cloneNode(true);
    const idx   = newQCounter++;

    clone.querySelectorAll('[name]').forEach(el => {
        el.name = el.name.replaceAll('NEW_IDX', idx);
    });
    clone.querySelector('[data-index]').dataset.index = idx;
    clone.querySelector('.remove-new-q').addEventListener('click', function () {
        this.closest('.question-block').remove();
        renumberQuestions();
        updateTotalMarks();
    });

    document.getElementById('questionsContainer').appendChild(clone);
    renumberQuestions();
    updateTotalMarks();
    // Scroll to new block
    document.getElementById('questionsContainer').lastElementChild
        .scrollIntoView({ behavior: 'smooth', block: 'center' });
});

// ── Renumber visible Q labels ──
function renumberQuestions() {
    document.querySelectorAll('.question-block').forEach(function (block, i) {
        const numEl = block.querySelector('.q-num-val');
        if (numEl) numEl.textContent = i + 1;
    });
    const badge = document.getElementById('qCountBadge');
    if (badge) badge.textContent = '(' + document.querySelectorAll('.question-block').length + ')';
}

// ── Live total marks hint ──
function updateTotalMarks() {
    let total = 0;
    document.querySelectorAll('.q-marks').forEach(el => {
        total += parseInt(el.value || 0);
    });
    const hint = document.getElementById('totalMarksHint');
    if (hint) hint.textContent = 'Current total: ' + total + ' marks.';
}
document.getElementById('questionsContainer').addEventListener('input', function (e) {
    if (e.target.classList.contains('q-marks')) updateTotalMarks();
});
updateTotalMarks();

// ── Server-side question delete ──
function deleteQuestion(qId, preview) {
    if (!confirm('Delete question: "' + preview + '"?\n\nThis cannot be undone.')) return;
    document.getElementById('deleteQId').value = qId;
    document.getElementById('deleteQForm').submit();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>