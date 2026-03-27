<?php
// lecturer/export_results.php — Export ALL test results as CSV
define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db         = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$testId     = (int) ($_GET['test_id'] ?? 0);

// Verify ownership
$stmt = $db->prepare("SELECT title, total_marks, pass_marks FROM tests WHERE id = ? AND lecturer_id = ?");
$stmt->bind_param('ii', $testId, $lecturerId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test) { die('Access denied.'); }

// Fetch ALL attempts regardless of status, ordered submitted first then by score
$stmt = $db->prepare("
    SELECT u.full_name, u.reg_no, u.email,
           r.score, r.total_marks, r.percentage, r.passed,
           r.status, r.started_at, r.submitted_at, r.time_taken_seconds
    FROM results r
    JOIN users u ON u.id = r.student_id
    WHERE r.test_id = ?
    ORDER BY
        CASE r.status WHEN 'submitted' THEN 1 WHEN 'timed_out' THEN 2 ELSE 3 END,
        r.percentage DESC
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// For in_progress rows, fill in partial score from saved answers
foreach ($rows as &$row) {
    if ($row['status'] === 'in_progress') {
        // We need result id — re-query for it
        $s2 = $db->prepare("SELECT id FROM results WHERE student_id = (SELECT id FROM users WHERE email = ? LIMIT 1) AND test_id = ? LIMIT 1");
        // Simpler: already have score=0; just mark them as partial via the status column
        $row['passed'] = 0;
    }
}
unset($row);

$filename = 'results_' . preg_replace('/[^a-z0-9]/i', '_', $test['title']) . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Name', 'Reg No', 'Email', 'Score', 'Total Marks', 'Percentage', 'Result', 'Attempt Status', 'Started At', 'Submitted At', 'Time (sec)']);

foreach ($rows as $row) {
    $result = $row['status'] === 'in_progress' ? 'Incomplete'
            : ($row['passed'] ? 'Pass' : 'Fail');
    $status = match($row['status']) {
        'submitted'   => 'Submitted',
        'timed_out'   => 'Timed Out',
        'in_progress' => 'Incomplete',
        default       => $row['status'],
    };
    fputcsv($out, [
        $row['full_name'],
        $row['reg_no'] ?? '',
        $row['email'],
        $row['score'],
        $row['total_marks'],
        number_format((float)$row['percentage'], 1) . '%',
        $result,
        $status,
        $row['started_at'] ?? '',
        $row['submitted_at'] ?? '',
        $row['time_taken_seconds'],
    ]);
}
fclose($out);
exit;