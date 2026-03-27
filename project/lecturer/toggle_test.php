<?php
// lecturer/toggle_test.php — Toggle test active/inactive
define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('lecturer');

$db = getDB();
$lecturerId = (int) $_SESSION['user_id'];
$testId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare("UPDATE tests SET is_active = NOT is_active WHERE id = ? AND lecturer_id = ?");
$stmt->bind_param('ii', $testId, $lecturerId);
$stmt->execute();
$stmt->close();

setFlash('success', 'Test status updated.');
header('Location: ' . BASE_URL . '/lecturer/dashboard.php');
exit;