<?php
// admin/edit_user.php — Edit an existing user account

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('admin');

$db     = getDB();
$userId = (int) ($_GET['id'] ?? 0);
$errors = [];
$success = null;

// Detect optional columns
$colRes       = $db->query("SHOW COLUMNS FROM users");
$existingCols = array_column($colRes->fetch_all(MYSQLI_ASSOC), 'Field');
$hasPhone     = in_array('phone',      $existingCols);
$hasDept      = in_array('department', $existingCols);
$hasIsActive  = in_array('is_active',  $existingCols);

// ── Load the user being edited ──
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . BASE_URL . '/admin/manage_users.php');
    exit;
}

// Prevent admin editing their own account here (they should use profile page)
$isSelf = ($userId === (int) $_SESSION['user_id']);

// ════════════════════════════════════════════
// POST: Save changes
// ════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrf()) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $fullName   = trim($_POST['full_name']        ?? '');
        $email      = trim($_POST['email']            ?? '');
        $role       = trim($_POST['role']             ?? '');
        $regNo      = trim($_POST['reg_no']           ?? '');
        $phone      = trim($_POST['phone']            ?? '');
        $department = trim($_POST['department']       ?? '');
        $newPass    = trim($_POST['new_password']     ?? '');
        $confirmPass= trim($_POST['confirm_password'] ?? '');

        // ── Validation ──
        if (empty($fullName))
            $errors[] = 'Full name is required.';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = 'A valid email address is required.';
        if (!in_array($role, ['student', 'lecturer', 'admin']))
            $errors[] = 'Please select a valid role.';
        if ($role === 'student' && empty($regNo))
            $errors[] = 'Registration number is required for students.';
        if (!empty($newPass)) {
            if (strlen($newPass) < 6)
                $errors[] = 'New password must be at least 6 characters.';
            if ($newPass !== $confirmPass)
                $errors[] = 'Passwords do not match.';
        }

        // Email uniqueness (exclude current user)
        if (empty($errors)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $stmt->bind_param('si', $email, $userId);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0)
                $errors[] = 'This email is already used by another account.';
            $stmt->close();
        }

        if (empty($errors)) {
            // Build UPDATE dynamically
            $setClauses = ['full_name = ?', 'email = ?', 'role = ?', 'reg_no = ?'];
            $types      = 'ssss';
            $vals       = [$fullName, $email, $role, $regNo];

            if ($hasPhone) {
                $setClauses[] = 'phone = ?';
                $types  .= 's';
                $vals[]  = $phone;
            }
            if ($hasDept) {
                $setClauses[] = 'department = ?';
                $types  .= 's';
                $vals[]  = $department;
            }
            if (!empty($newPass)) {
                $hash          = password_hash($newPass, PASSWORD_DEFAULT);
                $setClauses[]  = 'password_hash = ?';
                $types        .= 's';
                $vals[]        = $hash;
            }

            $types .= 'i';
            $vals[] = $userId;

            $sql  = "UPDATE users SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                $errors[] = 'DB prepare error: ' . $db->error;
            } else {
                $stmt->bind_param($types, ...$vals);
                if ($stmt->execute()) {
                    $success = 'Account for <strong>' . htmlspecialchars($fullName) . '</strong> updated successfully.';
                    // Refresh $user with latest values
                    $user['full_name']  = $fullName;
                    $user['email']      = $email;
                    $user['role']       = $role;
                    $user['reg_no']     = $regNo;
                    $user['phone']      = $phone;
                    $user['department'] = $department;
                } else {
                    $errors[] = 'Database error: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$pageTitle  = 'Edit User: ' . $user['full_name'];
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
    <style>
        .eu-layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 2rem;
            align-items: start;
        }

        /* Role selector cards */
        .role-cards { display: grid; grid-template-columns: repeat(3,1fr); gap: 0.75rem; margin-bottom:1.5rem; }
        .role-card  { position: relative; cursor: pointer; }
        .role-card input[type="radio"] { position:absolute; opacity:0; width:0; height:0; }
        .role-card-inner {
            border: 2px solid var(--gray-200); border-radius: 10px;
            padding: 1rem 0.6rem; text-align: center;
            transition: all 0.18s ease; background: var(--white);
        }
        .role-card input:checked + .role-card-inner {
            border-color: var(--amber); background: #fffbf0;
            box-shadow: 0 0 0 3px rgba(232,160,32,0.15);
        }
        .role-card:hover .role-card-inner { border-color: var(--amber); }
        .role-card-icon  { font-size:1.6rem; display:block; margin-bottom:0.3rem; }
        .role-card-label { font-size:0.82rem; font-weight:600; color:var(--navy); display:block; }
        .role-card-desc  { font-size:0.7rem; color:var(--gray-400); margin-top:0.15rem; display:block; }

        /* User info sidebar */
        .user-panel {
            background: var(--navy); color: var(--white);
            border-radius: var(--radius); padding: 1.75rem;
            position: sticky; top: 80px;
        }
        .user-panel-avatar {
            width: 64px; height: 64px; border-radius: 50%;
            background: var(--amber); display: flex; align-items: center;
            justify-content: center; font-size: 1.6rem; font-weight: 700;
            color: var(--navy); margin: 0 auto 1rem; font-family: 'DM Serif Display', serif;
        }
        .user-panel-name  { text-align:center; font-family:'DM Serif Display',serif; font-size:1.05rem; color:var(--white); margin-bottom:0.25rem; }
        .user-panel-email { text-align:center; font-size:0.78rem; color:rgba(255,255,255,0.5); margin-bottom:1.25rem; }
        .user-panel-meta  { font-size:0.82rem; color:rgba(255,255,255,0.7); }
        .user-panel-meta dt { color:rgba(255,255,255,0.45); font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; margin-top:0.85rem; }
        .user-panel-meta dd { margin:0; font-weight:500; }

        /* Section titles */
        .form-section-title {
            font-size:0.75rem; font-weight:700; text-transform:uppercase;
            letter-spacing:1px; color:var(--gray-400);
            margin:1.5rem 0 1rem; padding-bottom:0.5rem;
            border-bottom:1px solid var(--gray-200);
        }

        /* Password toggle */
        .pw-wrap { position:relative; }
        .pw-wrap .form-control { padding-right:2.8rem; }
        .pw-toggle {
            position:absolute; right:0.75rem; top:50%;
            transform:translateY(-50%);
            background:none; border:none; cursor:pointer;
            color:var(--gray-400); font-size:1rem; padding:0; line-height:1;
        }
        .pw-toggle:hover { color:var(--navy); }

        /* Success banner */
        .success-banner {
            background:#d1fae5; border:1.5px solid #6ee7b7;
            border-radius:var(--radius); padding:1rem 1.25rem;
            margin-bottom:1.5rem; display:flex; align-items:center; gap:0.75rem;
        }
        .success-banner-icon { font-size:1.5rem; flex-shrink:0; }
        .success-banner-body { color:#065f46; font-size:0.9rem; }

        /* Self-edit warning */
        .self-warning {
            background:#fffbeb; border:1.5px solid #fbbf24;
            border-radius:var(--radius); padding:0.85rem 1.2rem;
            font-size:0.88rem; color:#92400e; margin-bottom:1.5rem;
        }

        @media (max-width:900px) {
            .eu-layout { grid-template-columns:1fr; }
            .user-panel { position:static; }
            .role-cards { grid-template-columns:repeat(3,1fr); }
        }
        @media (max-width:560px) {
            .role-cards { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>

<?php
$userName = $_SESSION['full_name'] ?? '';
$flash    = getFlash();
?>
<nav class="navbar">
    <div class="nav-brand">
        <span class="nav-logo">⬡</span>
        <span class="nav-title">KYU ExamPortal</span>
    </div>
    <ul class="nav-links">
        <li><a href="<?= BASE_URL ?>/lecturer/dashboard.php">Dashboard</a></li>
        <li><a href="<?= BASE_URL ?>/lecturer/create_test.php">New Test</a></li>
        <li><a href="<?= BASE_URL ?>/lecturer/analytics.php">Analytics</a></li>
        <li><a href="<?= BASE_URL ?>/admin/manage_users.php">Users</a></li>
    </ul>
    <div class="nav-user">
        <span class="nav-user-name"><?= htmlspecialchars($userName) ?></span>
        <span class="nav-role-badge nav-role-admin">Admin</span>
        <a href="<?= BASE_URL ?>/logout.php" class="btn-logout">Logout</a>
    </div>
</nav>

<main class="main-content">

<?php if ($flash): ?>
<div class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
    <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<div class="flex-between mb-2">
    <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-outline btn-sm">← Back to Users</a>
</div>

<div class="page-header">
    <h1>Edit User Account</h1>
    <p>Update details, role, and credentials for this account.</p>
</div>

<?php if ($isSelf): ?>
<div class="self-warning">
    ⚠️ You are editing <strong>your own account</strong>. Be careful not to remove your admin role.
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="success-banner" id="successBanner">
    <span class="success-banner-icon">✅</span>
    <div class="success-banner-body"><?= $success ?></div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="flash flash-error">
    <strong>Please fix the following:</strong><br>
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
</div>
<?php endif; ?>

<div class="eu-layout">

    <!-- ── Left: User info panel ── -->
    <aside class="user-panel">
        <div class="user-panel-avatar">
            <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
        </div>
        <div class="user-panel-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="user-panel-email"><?= htmlspecialchars($user['email']) ?></div>

        <dl class="user-panel-meta">
            <dt>Role</dt>
            <dd>
                <span class="nav-role-badge nav-role-<?= $user['role'] ?>">
                    <?= $user['role'] === 'lecturer' ? 'Lecturer' : ucfirst($user['role']) ?>
                </span>
            </dd>

            <?php if (!empty($user['reg_no'])): ?>
            <dt>Reg No</dt>
            <dd><?= htmlspecialchars($user['reg_no']) ?></dd>
            <?php endif; ?>

            <?php if ($hasDept && !empty($user['department'])): ?>
            <dt>Department</dt>
            <dd><?= htmlspecialchars($user['department']) ?></dd>
            <?php endif; ?>

            <?php if ($hasPhone && !empty($user['phone'])): ?>
            <dt>Phone</dt>
            <dd><?= htmlspecialchars($user['phone']) ?></dd>
            <?php endif; ?>

            <dt>Status</dt>
            <dd>
                <?php $isActive = (bool)($user['is_active'] ?? 1); ?>
                <span style="color:<?= $isActive ? '#68d391' : '#fc8181' ?>;font-weight:600;">
                    <?= $isActive ? '● Active' : '● Inactive' ?>
                </span>
            </dd>

            <dt>Member Since</dt>
            <dd><?= !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : '—' ?></dd>

            <dt>Last Login</dt>
            <dd><?= !empty($user['last_login']) ? date('d M Y, H:i', strtotime($user['last_login'])) : 'Never' ?></dd>
        </dl>
    </aside>

    <!-- ── Right: Edit form ── -->
    <div class="card" style="margin-bottom:0;">
        <form method="POST"
              action="<?= BASE_URL ?>/admin/edit_user.php?id=<?= $userId ?>"
              id="editUserForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- Role -->
            <p class="form-section-title">Role</p>
            <div class="role-cards">
                <label class="role-card">
                    <input type="radio" name="role" value="student"
                           <?= $user['role'] === 'student' ? 'checked' : '' ?> required>
                    <div class="role-card-inner">
                        <span class="role-card-icon">🎓</span>
                        <span class="role-card-label">Student</span>
                        <span class="role-card-desc">Takes exams</span>
                    </div>
                </label>
                <label class="role-card">
                    <input type="radio" name="role" value="lecturer"
                           <?= $user['role'] === 'lecturer' ? 'checked' : '' ?>>
                    <div class="role-card-inner">
                        <span class="role-card-icon">📋</span>
                        <span class="role-card-label">Lecturer</span>
                        <span class="role-card-desc">Creates tests</span>
                    </div>
                </label>
                <label class="role-card">
                    <input type="radio" name="role" value="admin"
                           <?= $user['role'] === 'admin' ? 'checked' : '' ?>>
                    <div class="role-card-inner">
                        <span class="role-card-icon">🛡️</span>
                        <span class="role-card-label">Admin</span>
                        <span class="role-card-desc">Full access</span>
                    </div>
                </label>
            </div>

            <!-- Personal info -->
            <p class="form-section-title">Personal Information</p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                           value="<?= htmlspecialchars($user['full_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="email">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
            </div>

            <?php if ($hasPhone || $hasDept): ?>
            <div class="form-row">
                <?php if ($hasPhone): ?>
                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                           placeholder="e.g. 0712 345 678">
                </div>
                <?php endif; ?>
                <?php if ($hasDept): ?>
                <div class="form-group">
                    <label class="form-label" for="department">Department / Faculty</label>
                    <input type="text" id="department" name="department" class="form-control"
                           value="<?= htmlspecialchars($user['department'] ?? '') ?>"
                           placeholder="e.g. School of Engineering">
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Reg No (students only) -->
            <div class="form-group" id="regNoWrap">
                <label class="form-label" for="reg_no">Registration Number *</label>
                <input type="text" id="reg_no" name="reg_no" class="form-control"
                       value="<?= htmlspecialchars($user['reg_no'] ?? '') ?>"
                       placeholder="e.g. KYU/2022/001">
                <span class="form-hint">Required for students.</span>
            </div>

            <!-- Password -->
            <p class="form-section-title">Change Password
                <span style="font-weight:400;font-size:0.8rem;text-transform:none;letter-spacing:0;">
                    — leave blank to keep the current password
                </span>
            </p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="new_password">New Password</label>
                    <div class="pw-wrap">
                        <input type="password" id="new_password" name="new_password"
                               class="form-control" placeholder="Min. 6 characters"
                               minlength="6" autocomplete="new-password">
                        <button type="button" class="pw-toggle"
                                onclick="togglePw('new_password', this)"
                                title="Show password">👁</button>
                    </div>
                    <div class="pw-strength-wrap" style="margin-top:0.5rem;">
                        <div style="height:4px;border-radius:2px;background:var(--gray-200);overflow:hidden;">
                            <div id="pwFill" style="height:100%;border-radius:2px;transition:width 0.3s,background 0.3s;width:0;"></div>
                        </div>
                        <span id="pwText" style="font-size:0.75rem;font-weight:600;color:var(--gray-400);">—</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <div class="pw-wrap">
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="form-control" placeholder="Repeat new password"
                               autocomplete="new-password">
                        <button type="button" class="pw-toggle"
                                onclick="togglePw('confirm_password', this)"
                                title="Show password">👁</button>
                    </div>
                    <span id="matchHint" class="form-hint">&nbsp;</span>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex-between mt-3"
                 style="padding-top:1rem;border-top:1px solid var(--gray-200);">
                <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-outline">
                    Cancel
                </a>
                <button type="submit" class="btn btn-amber">
                    💾 Save Changes
                </button>
            </div>
        </form>
    </div>

</div>
</main>

<footer class="site-footer">
    <p>Kirinyaga University &mdash; SSE 2304 Online Examination System &copy; <?= date('Y') ?></p>
</footer>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
// ── Role cards → show/hide reg no ──
const roleRadios = document.querySelectorAll('input[name="role"]');
const regNoWrap  = document.getElementById('regNoWrap');
const regInput   = document.getElementById('reg_no');

function updateRoleFields() {
    const isStudent = document.querySelector('input[name="role"]:checked')?.value === 'student';
    regNoWrap.style.display = isStudent ? '' : 'none';
    regInput.required = isStudent;
}
roleRadios.forEach(r => r.addEventListener('change', updateRoleFields));
updateRoleFields();

// ── Password show/hide toggle ──
function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
        btn.title = 'Hide password';
    } else {
        input.type = 'password';
        btn.textContent = '👁';
        btn.title = 'Show password';
    }
}

// ── Password strength ──
const pwInput = document.getElementById('new_password');
const pwFill  = document.getElementById('pwFill');
const pwText  = document.getElementById('pwText');
const levels  = [
    { w:'0%',   c:'var(--gray-200)', l:'—',        s:'color:var(--gray-400)' },
    { w:'25%',  c:'var(--red)',      l:'Weak',      s:'color:var(--red)' },
    { w:'50%',  c:'#e8a020',         l:'Fair',      s:'color:#e8a020' },
    { w:'75%',  c:'#68d391',         l:'Good',      s:'color:#276749' },
    { w:'100%', c:'var(--green)',    l:'Strong',    s:'color:var(--green)' },
];
function scorePw(pw) {
    let s = 0;
    if (pw.length >= 6)  s++;
    if (pw.length >= 12) s++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    return Math.min(4, s);
}
pwInput.addEventListener('input', function () {
    const score = this.value.length === 0 ? 0 : Math.max(1, scorePw(this.value));
    const lvl   = levels[score];
    pwFill.style.width      = lvl.w;
    pwFill.style.background = lvl.c;
    pwText.textContent      = lvl.l;
    pwText.style.cssText    = lvl.s;
    checkMatch();
});

// ── Password match ──
const confirmInput = document.getElementById('confirm_password');
const matchHint    = document.getElementById('matchHint');
function checkMatch() {
    const pw = pwInput.value, cf = confirmInput.value;
    if (!cf) { matchHint.textContent = '\u00a0'; matchHint.style.color = ''; return; }
    if (pw === cf) {
        matchHint.textContent      = '✓ Passwords match';
        matchHint.style.color      = 'var(--green)';
        matchHint.style.fontWeight = '600';
    } else {
        matchHint.textContent      = '✗ Passwords do not match';
        matchHint.style.color      = 'var(--red)';
        matchHint.style.fontWeight = '600';
    }
}
confirmInput.addEventListener('input', checkMatch);

// ── Submit guard ──
document.getElementById('editUserForm').addEventListener('submit', function (e) {
    const pw = pwInput.value, cf = confirmInput.value;
    if (pw && pw !== cf) {
        e.preventDefault();
        confirmInput.focus();
        checkMatch();
    }
});

// Scroll to success banner if present
const banner = document.getElementById('successBanner');
if (banner) banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
</script>
</body>
</html>