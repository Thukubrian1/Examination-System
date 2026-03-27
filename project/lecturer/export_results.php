<?php
// lecturer/export_results.php — Export test results as CSV
define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$testId = (int) ($_GET['test_id'] ?? 0);

// Verify ownership
$stmt = $db->prepare("SELECT title FROM tests WHERE id = ? AND lecturer_id = ?");
$stmt->bind_param('ii', $testId, $lecturerId);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$test) { die('Access denied.'); }

$stmt = $db->prepare("
    SELECT u.full_name, u.reg_no, u.email,
           r.score, r.total_marks, r.percentage, r.passed,
           r.submitted_at, r.time_taken_seconds
    FROM results r
    JOIN users u ON u.id = r.student_id
    WHERE r.test_id = ? AND r.status = 'submitted'
    ORDER BY r.percentage DESC
");
$stmt->bind_param('i', $testId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$filename = 'results_' . preg_replace('/[^a-z0-9]/i', '_', $test['title']) . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Name', 'Reg No', 'Email', 'Score', 'Total', 'Percentage', 'Passed', 'Submitted At', 'Time (sec)']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['full_name'], $row['reg_no'], $row['email'],
        $row['score'], $row['total_marks'], $row['percentage'],
        $row['passed'] ? 'Yes' : 'No', $row['submitted_at'], $row['time_taken_seconds']
    ]);
}
fclose($out);
exit;