<?php
// admin/create_user.php — Create a new user (student, lecturer, or admin)

define('BASE_URL', '/SSE2304_CAT2_GROUP15/project');
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

requireRole('admin');

$db      = getDB();
$errors  = [];
$success = null;

// Detect which optional columns exist in users table
$colResult    = $db->query("SHOW COLUMNS FROM users");
$existingCols = [];
while ($col = $colResult->fetch_assoc()) {
    $existingCols[] = $col['Field'];
}
$hasPhone      = in_array('phone',      $existingCols);
$hasDepartment = in_array('department', $existingCols);

// ---- Handle form submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    } else {
        $fullName   = trim($_POST['full_name']        ?? '');
        $email      = trim($_POST['email']            ?? '');
        $password   = trim($_POST['password']         ?? '');
        $confirm    = trim($_POST['confirm_password'] ?? '');
        $role       = trim($_POST['role']             ?? '');
        $regNo      = trim($_POST['reg_no']           ?? '');
        $phone      = trim($_POST['phone']            ?? '');
        $department = trim($_POST['department']       ?? '');

        if (empty($fullName))
            $errors[] = 'Full name is required.';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = 'A valid email address is required.';
        if (strlen($password) < 6)
            $errors[] = 'Password must be at least 6 characters.';
        if ($password !== $confirm)
            $errors[] = 'Passwords do not match.';
        if (!in_array($role, ['student', 'lecturer', 'admin']))
            $errors[] = 'Please select a valid role.';
        if ($role === 'student' && empty($regNo))
            $errors[] = 'Registration number is required for students.';

        if (empty($errors)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = 'An account with this email already exists.';
            }
            $stmt->close();
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $cols  = ['full_name', 'email', 'password_hash', 'role', 'reg_no'];
            $types = 'sssss';
            $vals  = [$fullName, $email, $hash, $role, $regNo];

            if ($hasPhone)      { $cols[] = 'phone';      $types .= 's'; $vals[] = $phone; }
            if ($hasDepartment) { $cols[] = 'department'; $types .= 's'; $vals[] = $department; }

            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $colList      = implode(', ', $cols);
            $sql          = "INSERT INTO users ($colList) VALUES ($placeholders)";

            $stmt = $db->prepare($sql);
            if (!$stmt) {
                $errors[] = 'Database prepare error: ' . $db->error;
            } else {
                $stmt->bind_param($types, ...$vals);
                if ($stmt->execute()) {
                    $stmt->close();
                    $success = ($role === 'lecturer' ? 'Lecturer' : ucfirst($role))
                             . ' account for <strong>' . htmlspecialchars($fullName) . '</strong> ('
                             . htmlspecialchars($email) . ') was created successfully.';
                    $_POST = [];
                } else {
                    $errors[] = 'Database error: ' . $stmt->error;
                    $stmt->close();
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$pageTitle  = 'Create User Account';

$val = [
    'full_name'  => htmlspecialchars($_POST['full_name']  ?? ''),
    'email'      => htmlspecialchars($_POST['email']      ?? ''),
    'role'       => $_POST['role']                        ?? 'student',
    'reg_no'     => htmlspecialchars($_POST['reg_no']     ?? ''),
    'phone'      => htmlspecialchars($_POST['phone']      ?? ''),
    'department' => htmlspecialchars($_POST['department'] ?? ''),
];

include __DIR__ . '/../includes/header.php';
?>

<style>
.cu-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 2rem;
    align-items: start;
}
.role-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}
.role-card { position: relative; cursor: pointer; }
.role-card input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
.role-card-inner {
    border: 2px solid var(--gray-200);
    border-radius: 10px;
    padding: 1.1rem 0.75rem;
    text-align: center;
    transition: all 0.18s ease;
    background: var(--white);
}
.role-card input:checked + .role-card-inner {
    border-color: var(--amber);
    background: #fffbf0;
    box-shadow: 0 0 0 3px rgba(232,160,32,0.15);
}
.role-card:hover .role-card-inner { border-color: var(--amber); }
.role-card-icon  { font-size: 1.8rem; display: block; margin-bottom: 0.4rem; }
.role-card-label { font-size: 0.82rem; font-weight: 600; color: var(--navy); display: block; }
.role-card-desc  { font-size: 0.72rem; color: var(--gray-400); margin-top: 0.2rem; display: block; }

.info-panel {
    background: var(--navy);
    color: var(--white);
    border-radius: var(--radius);
    padding: 1.75rem;
    position: sticky;
    top: 80px;
}
.info-panel h3 {
    font-family: 'DM Serif Display', serif;
    font-size: 1.15rem;
    color: var(--amber);
    margin-bottom: 1rem;
}
.info-item {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.8);
    line-height: 1.5;
}
.info-item-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }

.pw-strength-wrap { margin-top: 0.5rem; }
.pw-strength-bar  { height: 4px; border-radius: 2px; background: var(--gray-200); overflow: hidden; }
.pw-strength-fill { height: 100%; border-radius: 2px; transition: width 0.3s ease, background 0.3s ease; width: 0%; }
.pw-strength-text { font-size: 0.75rem; margin-top: 0.3rem; font-weight: 600; }

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

.success-banner {
    background: #d1fae5;
    border: 1.5px solid #6ee7b7;
    border-radius: var(--radius);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}
.success-banner-icon  { font-size: 1.8rem; flex-shrink: 0; }
.success-banner-title { font-weight: 700; color: #065f46; font-size: 1rem; margin-bottom: 0.25rem; }
.success-banner-body  { color: #047857; font-size: 0.9rem; }
.success-banner-actions { margin-top: 0.75rem; display: flex; gap: 0.75rem; }

.pw-wrap { position: relative; }
.pw-wrap .form-control { padding-right: 2.8rem; }
.pw-toggle {
    position: absolute; right: 0.75rem; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--gray-400); font-size: 1rem; padding: 0; line-height: 1;
}
.pw-toggle:hover { color: var(--navy); }

@media (max-width: 900px) {
    .cu-layout { grid-template-columns: 1fr; }
    .info-panel { position: static; }
}
@media (max-width: 560px) {
    .role-cards { grid-template-columns: 1fr; }
}
</style>

<div class="flex-between mb-2">
    <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-outline btn-sm">← Back to Users</a>
</div>

<div class="page-header">
    <h1>Create User Account</h1>
    <p>Add a new student, lecturer, or admin to the system.</p>
</div>

<?php if ($success): ?>
<div class="success-banner" id="successBanner">
    <span class="success-banner-icon">✅</span>
    <div>
        <div class="success-banner-title">Account Created Successfully!</div>
        <div class="success-banner-body"><?= $success ?></div>
        <div class="success-banner-actions">
            <a href="<?= BASE_URL ?>/admin/create_user.php" class="btn btn-primary btn-sm">+ Create Another</a>
            <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-outline btn-sm">View All Users</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="flash flash-error">
    <strong>Please fix the following:</strong><br>
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
</div>
<?php endif; ?>

<div class="cu-layout">

    <!-- Sidebar -->
    <aside class="info-panel">
        <h3>Account Roles</h3>
        <div class="info-item">
            <span class="info-item-icon">🎓</span>
            <div>
                <strong style="color:var(--white);">Student</strong><br>
                Can view available tests, take exams, and see their results. Requires a registration number.
            </div>
        </div>
        <div class="info-item">
            <span class="info-item-icon">📋</span>
            <div>
                <strong style="color:var(--white);">Lecturer</strong><br>
                Can create and manage tests, view analytics, and export student results.
            </div>
        </div>
        <div class="info-item">
            <span class="info-item-icon">🛡️</span>
            <div>
                <strong style="color:var(--white);">Admin</strong><br>
                Full system access — can manage all users and has lecturer privileges.
            </div>
        </div>
        <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,0.1);">
            <p style="font-size:0.78rem;color:rgba(255,255,255,0.5);line-height:1.6;">
                The new user can log in immediately with the email and password you set.
            </p>
        </div>
    </aside>

    <!-- Form -->
    <div class="card" style="margin-bottom:0;">
        <form method="POST" action="<?= BASE_URL ?>/admin/create_user.php" id="createUserForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <p class="form-section-title">Select Role</p>
            <div class="role-cards">
                <label class="role-card">
                    <input type="radio" name="role" value="student"
                           <?= $val['role'] === 'student' ? 'checked' : '' ?> required>
                    <div class="role-card-inner">
                        <span class="role-card-icon">🎓</span>
                        <span class="role-card-label">Student</span>
                        <span class="role-card-desc">Takes exams</span>
                    </div>
                </label>
                <label class="role-card">
                    <input type="radio" name="role" value="lecturer"
                           <?= $val['role'] === 'lecturer' ? 'checked' : '' ?>>
                    <div class="role-card-inner">
                        <span class="role-card-icon">📋</span>
                        <span class="role-card-label">Lecturer</span>
                        <span class="role-card-desc">Creates tests</span>
                    </div>
                </label>
                <label class="role-card">
                    <input type="radio" name="role" value="admin"
                           <?= $val['role'] === 'admin' ? 'checked' : '' ?>>
                    <div class="role-card-inner">
                        <span class="role-card-icon">🛡️</span>
                        <span class="role-card-label">Admin</span>
                        <span class="role-card-desc">Full access</span>
                    </div>
                </label>
            </div>

            <p class="form-section-title">Personal Information</p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                           value="<?= $val['full_name'] ?>"
                           placeholder="e.g. Alice Wanjiku Kamau" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="email">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= $val['email'] ?>"
                           placeholder="e.g. alice@students.kyu.ac.ke" required>
                </div>
            </div>

            <?php if ($hasPhone || $hasDepartment): ?>
            <div class="form-row">
                <?php if ($hasPhone): ?>
                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           value="<?= $val['phone'] ?>" placeholder="e.g. 0712 345 678">
                </div>
                <?php endif; ?>
                <?php if ($hasDepartment): ?>
                <div class="form-group">
                    <label class="form-label" for="department">Department / Faculty</label>
                    <input type="text" id="department" name="department" class="form-control"
                           value="<?= $val['department'] ?>" placeholder="e.g. School of Engineering">
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="form-group" id="regNoWrap">
                <label class="form-label" for="reg_no">Registration Number <span id="regStar">*</span></label>
                <input type="text" id="reg_no" name="reg_no" class="form-control"
                       value="<?= $val['reg_no'] ?>" placeholder="e.g. KYU/2022/001">
                <span class="form-hint">Required for students.</span>
            </div>

            <p class="form-section-title">Account Security</p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="password">Password *</label>
                    <div class="pw-wrap">
                        <input type="password" id="password" name="password" class="form-control"
                               placeholder="Min. 6 characters" required minlength="6"
                               autocomplete="new-password">
                        <button type="button" class="pw-toggle" onclick="togglePw('password', this)" title="Show password">👁</button>
                    </div>
                    <div class="pw-strength-wrap">
                        <div class="pw-strength-bar">
                            <div class="pw-strength-fill" id="pwFill"></div>
                        </div>
                        <span class="pw-strength-text" id="pwText" style="color:var(--gray-400);">Enter a password</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password *</label>
                    <div class="pw-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                               placeholder="Repeat the password" required autocomplete="new-password">
                        <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', this)" title="Show password">👁</button>
                    </div>
                    <span class="form-hint" id="matchHint">&nbsp;</span>
                </div>
            </div>

            <div class="flex-between mt-3" style="padding-top:1rem;border-top:1px solid var(--gray-200);">
                <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-amber" id="submitBtn">
                    ✚ Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Role card → show/hide reg no field
const roleRadios = document.querySelectorAll('input[name="role"]');
const regNoWrap  = document.getElementById('regNoWrap');
const regInput   = document.getElementById('reg_no');

function updateRoleFields() {
    const sel       = document.querySelector('input[name="role"]:checked')?.value;
    const isStudent = sel === 'student';
    regNoWrap.style.display = isStudent ? '' : 'none';
    regInput.required = isStudent;
}
roleRadios.forEach(r => r.addEventListener('change', updateRoleFields));
updateRoleFields();

// Password strength meter
const pwInput = document.getElementById('password');
const pwFill  = document.getElementById('pwFill');
const pwText  = document.getElementById('pwText');
const levels  = [
    { w:'0%',   c:'var(--gray-200)', l:'Enter a password', s:'color:var(--gray-400)' },
    { w:'25%',  c:'var(--red)',      l:'Weak',             s:'color:var(--red)' },
    { w:'50%',  c:'#e8a020',         l:'Fair',             s:'color:#e8a020' },
    { w:'75%',  c:'#68d391',         l:'Good',             s:'color:#276749' },
    { w:'100%', c:'var(--green)',    l:'Strong',           s:'color:var(--green)' },
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

// Password match hint
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

// Password show/hide toggle
function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type      = 'text';
        btn.textContent = '🙈';
        btn.title       = 'Hide password';
    } else {
        input.type      = 'password';
        btn.textContent = '👁';
        btn.title       = 'Show password';
    }
}

// Submit guard
document.getElementById('createUserForm').addEventListener('submit', function(e) {
    if (!document.querySelector('input[name="role"]:checked')) {
        e.preventDefault();
        alert('Please select a role.');
        return;
    }
    if (pwInput.value !== confirmInput.value) {
        e.preventDefault();
        confirmInput.focus();
        checkMatch();
    }
});

// Scroll to success banner if present
const banner = document.getElementById('successBanner');
if (banner) banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>