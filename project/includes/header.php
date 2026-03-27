<?php
// includes/header.php — Shared HTML header and navigation
$pageTitle  = $pageTitle  ?? 'Online Exam System';
$bodyClass  = $bodyClass  ?? '';
$role       = $_SESSION['role']      ?? '';
$userName   = $_SESSION['full_name'] ?? '';
$flash      = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | KYU Exam System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">

<nav class="navbar">
    <div class="nav-brand">
        <span class="nav-logo">⬡</span>
        <span class="nav-title">KYU ExamPortal</span>
    </div>

    <?php if (!empty($_SESSION['user_id'])): ?>
    <ul class="nav-links">
        <?php if ($role === 'admin'): ?>
            <li><a href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/admin/manage_users.php">Users</a></li>
            <li><a href="<?= BASE_URL ?>/admin/create_user.php">Create User</a></li>
        <?php elseif ($role === 'lecturer'): ?>
            <li><a href="<?= BASE_URL ?>/lecturer/dashboard.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/lecturer/create_test.php">New Test</a></li>
            <li><a href="<?= BASE_URL ?>/lecturer/analytics.php">Analytics</a></li>
        <?php elseif ($role === 'student'): ?>
            <li><a href="<?= BASE_URL ?>/student/dashboard.php">Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/student/results.php">My Results</a></li>
        <?php endif; ?>
    </ul>
    <div class="nav-user">
        <span class="nav-user-name"><?= htmlspecialchars($userName) ?></span>
        <span class="nav-role-badge nav-role-<?= $role ?>">
            <?= $role === 'lecturer' ? 'Lecturer' : ucfirst($role) ?>
        </span>
        <a href="<?= BASE_URL ?>/logout.php" class="btn-logout">Logout</a>
    </div>
    <?php endif; ?>
</nav>

<main class="main-content">

<?php if ($flash): ?>
<div class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>