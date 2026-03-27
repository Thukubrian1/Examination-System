<?php
// lecturer/create_test.php — Create a new test + add questions
// ============================================================

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db          = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$errors      = [];
$success     = false;

// ============================================================
// POST: Save new test
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrf()) {
        $errors[] = 'Invalid form submission.';
    } else {
        // Sanitize inputs
        $title        = trim($_POST['title']        ?? '');
        $description  = trim($_POST['description']  ?? '');
        $duration     = (int) ($_POST['duration']   ?? 0);
        $pass_marks   = (int) ($_POST['pass_marks'] ?? 0);
        $start_time   = trim($_POST['start_time']   ?? '');
        $end_time     = trim($_POST['end_time']      ?? '');
        $randomize    = isset($_POST['randomize'])  ? 1 : 0;

        // Validation
        if (empty($title))                          $errors[] = 'Test title is required.';
        if ($duration < 5 || $duration > 300)       $errors[] = 'Duration must be between 5 and 300 minutes.';
        if (empty($start_time))                     $errors[] = 'Start time is required.';
        if (empty($end_time))                       $errors[] = 'End time is required.';
        if (!empty($start_time) && !empty($end_time) && strtotime($start_time) >= strtotime($end_time)) {
            $errors[] = 'End time must be after start time.';
        }

        // Questions validation
        $questions = $_POST['questions'] ?? [];
        if (empty($questions))                      $errors[] = 'Add at least one question.';

        $totalMarks = 0;
        foreach ($questions as $qi => $q) {
            $qText = trim($q['text'] ?? '');
            $qMark = (int) ($q['marks'] ?? 1);
            if (empty($qText))  { $errors[] = "Question " . ($qi+1) . " text is required."; }
            if ($qMark < 1)     { $errors[] = "Question " . ($qi+1) . " must have at least 1 mark."; }
            $totalMarks += $qMark;

            $correctCount = 0;
            foreach ($q['options'] ?? [] as $opt) {
                if (!empty($opt['correct'])) $correctCount++;
            }
            if (count($q['options'] ?? []) < 2) {
                $errors[] = "Question " . ($qi+1) . " needs at least 2 options.";
            }
            if ($correctCount !== 1) {
                $errors[] = "Question " . ($qi+1) . " must have exactly one correct answer.";
            }
        }

        if ($pass_marks < 0 || $pass_marks > $totalMarks) {
            $errors[] = "Pass marks must be between 0 and total marks ($totalMarks).";
        }

        if (empty($errors)) {
            // Begin transaction
            $db->begin_transaction();
            try {
                // Insert test
                $stmt = $db->prepare("
                    INSERT INTO tests (lecturer_id, title, description, duration_minutes, total_marks, pass_marks, start_time, end_time, randomize_questions)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('issiiissi', $lecturerId, $title, $description, $duration, $totalMarks, $pass_marks, $start_time, $end_time, $randomize);
                $stmt->execute();
                $testId = $db->insert_id;
                $stmt->close();

                // Insert questions and options
                foreach ($questions as $order => $q) {
                    $qText = trim($q['text']);
                    $qMark = (int) $q['marks'];
                    $qOrder = $order + 1;

                    $stmt = $db->prepare("INSERT INTO questions (test_id, question_text, marks, question_order) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('isii', $testId, $qText, $qMark, $qOrder);
                    $stmt->execute();
                    $questionId = $db->insert_id;
                    $stmt->close();

                    foreach ($q['options'] as $opt) {
                        $optText  = trim($opt['text'] ?? '');
                        $isCorrect = isset($opt['correct']) ? 1 : 0;
                        if (empty($optText)) continue;

                        $stmt = $db->prepare("INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                        $stmt->bind_param('isi', $questionId, $optText, $isCorrect);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $db->commit();
                setFlash('success', "Test \"$title\" created successfully with $totalMarks marks.");
                header('Location: ' . BASE_URL . '/lecturer/dashboard.php');
                exit;

            } catch (Exception $e) {
                $db->rollback();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Create New Test';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Create New Test</h1>
    <p>Build a multiple-choice test with automatic grading.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash flash-error">
    <strong>Please fix the following errors:</strong><br>
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
</div>
<?php endif; ?>

<form method="POST" action="create_test.php" id="testForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <!-- Test Details -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Test Details</h2>
        </div>

        <div class="form-group">
            <label class="form-label" for="title">Test Title *</label>
            <input type="text" id="title" name="title" class="form-control"
                   value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required maxlength="200">
        </div>

        <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="3"
                      placeholder="Brief description of this test..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="duration">Duration (minutes) *</label>
                <input type="number" id="duration" name="duration" class="form-control"
                       value="<?= (int) ($_POST['duration'] ?? 60) ?>" min="5" max="300" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="pass_marks">Pass Marks *</label>
                <input type="number" id="pass_marks" name="pass_marks" class="form-control"
                       value="<?= (int) ($_POST['pass_marks'] ?? 0) ?>" min="0" required>
                <span class="form-hint">Automatically updated based on questions added.</span>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="start_time">Available From *</label>
                <input type="datetime-local" id="start_time" name="start_time" class="form-control"
                       value="<?= htmlspecialchars($_POST['start_time'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="end_time">Available Until *</label>
                <input type="datetime-local" id="end_time" name="end_time" class="form-control"
                       value="<?= htmlspecialchars($_POST['end_time'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                <input type="checkbox" name="randomize" <?= isset($_POST['randomize']) ? 'checked' : '' ?>>
                <span class="form-label" style="margin-bottom:0;">Randomize question order</span>
            </label>
        </div>
    </div>

    <!-- Questions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Questions</h2>
            <button type="button" class="btn btn-amber" id="addQuestion">+ Add Question</button>
        </div>

        <div id="questionsContainer">
            <!-- Populated by JS below (or pre-filled on error) -->
        </div>
    </div>

    <div class="flex-between mt-2">
        <a href="dashboard.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Test</button>
    </div>
</form>

<template id="questionTemplate">
    <div class="question-block" style="border:1.5px solid #e2e8f0;border-radius:10px;padding:1.5rem;margin-bottom:1rem;">
        <div class="flex-between mb-2">
            <strong style="color:#0d1b2a;">Question <span class="q-num"></span></strong>
            <button type="button" class="btn btn-danger btn-sm remove-question">Remove</button>
        </div>
        <div class="form-row">
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Question Text *</label>
                <textarea name="questions[INDEX][text]" class="form-control" rows="2" required placeholder="Enter your question..."></textarea>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Marks</label>
                <input type="number" name="questions[INDEX][marks]" class="form-control" value="1" min="1" max="10">
            </div>
        </div>
        <p class="form-label mt-1">Options (select the correct one)</p>
        <div class="options-container">
            <div class="option-row flex gap-1 mb-1">
                <input type="radio" name="questions[INDEX][options][0][correct]" value="1" style="margin-top:8px;">
                <input type="text" name="questions[INDEX][options][0][text]" class="form-control" placeholder="Option A" required>
            </div>
            <div class="option-row flex gap-1 mb-1">
                <input type="radio" name="questions[INDEX][options][1][correct]" value="1" style="margin-top:8px;">
                <input type="text" name="questions[INDEX][options][1][text]" class="form-control" placeholder="Option B" required>
            </div>
            <div class="option-row flex gap-1 mb-1">
                <input type="radio" name="questions[INDEX][options][2][correct]" value="1" style="margin-top:8px;">
                <input type="text" name="questions[INDEX][options][2][text]" class="form-control" placeholder="Option C">
            </div>
            <div class="option-row flex gap-1 mb-1">
                <input type="radio" name="questions[INDEX][options][3][correct]" value="1" style="margin-top:8px;">
                <input type="text" name="questions[INDEX][options][3][text]" class="form-control" placeholder="Option D">
            </div>
        </div>
    </div>
</template>

<script>
let questionCount = 0;

function addQuestion() {
    const template = document.getElementById('questionTemplate');
    const container = document.getElementById('questionsContainer');
    const clone = template.content.cloneNode(true);
    const idx = questionCount++;

    // Replace INDEX placeholder with actual index
    clone.querySelectorAll('[name]').forEach(el => {
        el.name = el.name.replace('INDEX', idx);
    });
    clone.querySelector('.q-num').textContent = document.querySelectorAll('.question-block').length + 1;

    // Make radio buttons part of the same group per question
    const radios = clone.querySelectorAll('input[type="radio"]');
    radios.forEach(r => { r.name = `questions[${idx}][correct_option]`; });

    // Attach remove handler
    clone.querySelector('.remove-question').addEventListener('click', function () {
        this.closest('.question-block').remove();
        renumberQuestions();
    });

    container.appendChild(clone);
}

function renumberQuestions() {
    document.querySelectorAll('.question-block .q-num').forEach((el, i) => {
        el.textContent = i + 1;
    });
}

document.getElementById('addQuestion').addEventListener('click', addQuestion);

// Add one question by default
addQuestion();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>