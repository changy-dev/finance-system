<?php
session_start();
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
if (!function_exists('csrf_token')) { require_once __DIR__ . '/../core/csrf.php'; }

$user_id = (int) $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE user_id=$user_id")->fetch_assoc();

/* ── HANDLE AJAX SAVE ── */
if (isset($_POST['ajax'])) {
    csrf_verify_json();
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'profile') {
        $name  = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $bio   = trim($_POST['status_message'] ?? '');

        if ($name === '' || mb_strlen($name) < 2) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter your full name.', 'csrf_token' => csrf_token()]);
            exit();
        }
        if (mb_strlen($name) > 100) {
            echo json_encode(['status' => 'error', 'message' => 'Name must be under 100 characters.', 'csrf_token' => csrf_token()]);
            exit();
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.', 'csrf_token' => csrf_token()]);
            exit();
        }
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email=? AND user_id != ?");
        $check_stmt->bind_param("si", $email, $user_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $check_stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'Email address is already in use by another account.', 'csrf_token' => csrf_token()]);
            exit();
        }
        $check_stmt->close();

        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid phone number.', 'csrf_token' => csrf_token()]);
            exit();
        }
        if (mb_strlen($bio) > 300) {
            echo json_encode(['status' => 'error', 'message' => 'Bio must be under 300 characters.', 'csrf_token' => csrf_token()]);
            exit();
        }

        $stmt = $conn->prepare("UPDATE users SET full_name=?, name=?, email=?, phone=?, status_message=? WHERE user_id=?");
        $stmt->bind_param("sssssi", $name, $name, $email, $phone, $bio, $user_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['name']  = $name;
        $_SESSION['email'] = $email;

        echo json_encode(['status' => 'ok', 'name' => $name, 'email' => $email, 'csrf_token' => csrf_token()]);

    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $row = $conn->query("SELECT password FROM users WHERE user_id=$user_id")->fetch_assoc();

        if (!password_verify($current, $row['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect', 'csrf_token' => csrf_token()]);
        } elseif (strlen($new) < 8) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters', 'csrf_token' => csrf_token()]);
        } elseif (!preg_match('/[0-9]/', $new)) {
            echo json_encode(['status' => 'error', 'message' => 'Password needs at least one number', 'csrf_token' => csrf_token()]);
        } elseif (!preg_match('/[A-Z]/', $new)) {
            echo json_encode(['status' => 'error', 'message' => 'Password needs at least one uppercase letter', 'csrf_token' => csrf_token()]);
        } elseif (!preg_match('/[a-z]/', $new)) {
            echo json_encode(['status' => 'error', 'message' => 'Password needs at least one lowercase letter', 'csrf_token' => csrf_token()]);
        } elseif ($new !== $confirm) {
            echo json_encode(['status' => 'error', 'message' => 'Passwords do not match', 'csrf_token' => csrf_token()]);
        } elseif ($new === $current) {
            echo json_encode(['status' => 'error', 'message' => 'New password must be different from the current one', 'csrf_token' => csrf_token()]);
        } else {
            $hashed = password_hash($new, PASSWORD_BCRYPT);
            $stmt   = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
            $stmt->bind_param("si", $hashed, $user_id);
            $stmt->execute(); $stmt->close();
            echo json_encode(['status' => 'ok', 'csrf_token' => csrf_token()]);
        }
    }
    exit();
}

$user_display_name = !empty($user['full_name']) ? $user['full_name'] : (!empty($user['name']) ? $user['name'] : 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — Fintrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/fintrack.css">
<style>
.user-profile-badge { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg,#FFD18A,#FF8A5A); display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; color: #1F1B2F; flex-shrink: 0; box-shadow: var(--shadow-sm); }
.user-profile-summary { display: flex; align-items: center; gap: 18px; margin-bottom: 24px; padding: 18px 20px; background: var(--surface-2); border-radius: var(--radius-lg); border: 1px solid var(--line); }
.user-profile-info h3 { font-size: 16px; font-weight: 700; color: var(--text-1); margin: 0 0 2px; }
.user-profile-info p { font-size: 12px; color: var(--text-2); margin: 0 0 6px; }
.settings-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--line); padding-bottom: 10px; }
.settings-tab { padding: 8px 18px; border-radius: var(--radius); border: 1px solid transparent; background: transparent; font-size: 13.5px; font-weight: 600; color: var(--text-2); cursor: pointer; transition: all .15s; }
.settings-tab:hover { color: var(--text-1); background: var(--surface-2); }
.settings-tab.active { background: var(--surface-2); border-color: var(--line); color: var(--gold); }
.settings-panel { display: none; }
.settings-panel.active { display: block; }
.strength-bar { display: flex; gap: 4px; margin-top: 8px; }
.strength-seg { flex: 1; height: 4px; border-radius: 2px; background: var(--line); transition: background .2s; }
.strength-label { font-size: 11px; margin-top: 4px; color: var(--text-2); }
</style>
</head>
<body>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <div class="brand-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="brand-text">
                    <div class="brand-name">Fintrack</div>
                    <div class="brand-tagline">Personal Finance</div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <div class="nav-section-label">Overview</div>
            <a href="admin_dashboard.php" class="nav-item" title="Dashboard">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
                <span class="nav-label">System Overview</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Management</div>
            <a href="users.php" class="nav-item" title="Users">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                <span class="nav-label">User Directory</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Analytics</div>
            <a href="reports.php" class="nav-item" title="Reports">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/></svg>
                <span class="nav-label">System Reports</span>
            </a>
            <?php else: ?>
            <div class="nav-section-label">Overview</div>
            <a href="../pages/dashboard.php" class="nav-item" title="Dashboard">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
                <span class="nav-label">Dashboard</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Money</div>
            <a href="../pages/transactions.php" class="nav-item" title="Transactions">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span class="nav-label">Transactions</span>
            </a>
            <a href="../pages/budget.php" class="nav-item" title="Budget">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                <span class="nav-label">Budget</span>
            </a>
            <a href="../pages/reports.php" class="nav-item" title="Reports">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/></svg>
                <span class="nav-label">Reports</span>
            </a>
            <?php endif; ?>

            <div class="nav-section-label" style="margin-top:16px;">Account</div>
            <a href="/finance_system/pages/settings.php" class="nav-item active" title="Settings">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="nav-label">Settings</span>
            </a>
            <a href="../auth/logout.php" class="nav-item" title="Logout">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                <span class="nav-label">Logout</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar" id="sidebarAvatar"><?php echo strtoupper(substr($user_display_name, 0, 1)); ?></div>
                <div class="nav-label">
                    <div class="user-name" id="sidebarName"><?php echo htmlspecialchars($user_display_name); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="breadcrumb">
                    <span>Home</span>
                    <span class="breadcrumb-sep">›</span>
                    <span class="breadcrumb-current">Settings</span>
                </div>
            </div>
            <div class="topbar-right">
                <a href="<?php echo (($_SESSION['role'] ?? '') === 'admin') ? 'admin_dashboard.php' : 'dashboard.php'; ?>" class="btn btn-primary">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back to Dashboard
                </a>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <div class="page-header">
                <h1 class="page-title">Settings</h1>
                <p class="page-sub">Manage your profile,and account security</p>
            </div>

            <div class="settings-tabs">
                <button class="settings-tab active" onclick="switchTab('profile', this)">Profile</button>
                <button class="settings-tab" onclick="switchTab('security', this)">Security</button>
            </div>

            <!-- PROFILE -->
            <div class="settings-panel active" id="tab-profile">
                <div class="card">
                    <div class="card-title">Personal Information</div>
                    <div class="card-sub" style="margin-bottom:20px;">Update your name, email, phone number and bio</div>

                    <div class="user-profile-summary">
                        <div class="user-profile-badge" id="avatarInitial">
                            <?php echo strtoupper(substr($user_display_name, 0, 1)); ?>
                        </div>
                        <div class="user-profile-info">
                            <h3 id="displayName"><?php echo htmlspecialchars($user_display_name); ?></h3>
                            <p id="displayEmail"><?php echo htmlspecialchars($user['email']); ?></p>
                            <span class="badge pill-neutral"><?php echo strtoupper($user['role'] ?? 'USER'); ?></span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label>Full Name</label>
                            <input type="text" id="fullName" value="<?php echo htmlspecialchars($user_display_name); ?>" placeholder="Your full name">
                        </div>
                        <div class="field">
                            <label>Email Address</label>
                            <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="name@example.com">
                        </div>
                    </div>
                    <div class="field">
                        <label>Phone Number</label>
                        <input type="tel" id="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+254 700 000 000">
                    </div>
                    <div class="field">
                        <label>Bio / Status Message</label>
                        <textarea id="bio" placeholder="What's your Bio..."><?php echo htmlspecialchars($user['status_message'] ?? ''); ?></textarea>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button class="btn btn-primary" onclick="saveProfile()">Save Changes</button>
                        <button class="btn btn-ghost" onclick="resetProfile()">Reset</button>
                    </div>
                </div>
            </div>


            <!-- SECURITY -->
            <div class="settings-panel" id="tab-security">
                <div class="card" style="margin-bottom:14px;">
                    <div class="card-title">Change Password</div>
                    <div class="card-sub" style="margin-bottom:20px;">Use a strong password with at least 8 characters</div>
                    <div class="field">
                        <label>Current Password</label>
                        <input type="password" id="currentPass" placeholder="Enter current password">
                    </div>
                    <div class="field">
                        <label>New Password</label>
                        <input type="password" id="newPass" placeholder="Enter new password" oninput="checkStrength(this.value)">
                        <div class="strength-bar">
                            <div class="strength-seg" id="s1"></div><div class="strength-seg" id="s2"></div>
                            <div class="strength-seg" id="s3"></div><div class="strength-seg" id="s4"></div>
                        </div>
                        <div class="strength-label" id="strengthLabel">Enter a password</div>
                    </div>
                    <div class="field">
                        <label>Confirm New Password</label>
                        <input type="password" id="confirmPass" placeholder="Confirm new password">
                    </div>
                    <button class="btn btn-primary" onclick="savePassword()">Update Password</button>
                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->

<div class="toast" id="toast"></div>

<script>
let CSRF_TOKEN = "<?php echo csrf_token(); ?>";

function switchTab(name, el) {
    document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    el.classList.add('active');
}

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.classList.remove('show'), 3200);
}

function validateProfileForm() {
    const name  = document.getElementById('fullName').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const bio   = document.getElementById('bio').value.trim();

    if (name.length < 2) return 'Please enter your full name.';
    if (name.length > 100) return 'Name must be under 100 characters.';
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'Please enter a valid email address.';
    if (phone && !/^[0-9+\-\s()]{7,20}$/.test(phone)) return 'Please enter a valid phone number.';
    if (bio.length > 300) return 'Bio must be under 300 characters.';
    return null;
}

function saveProfile() {
    const validationError = validateProfileForm();
    if (validationError) return showToast(validationError, 'error');

    const fd = new FormData();
    fd.append('ajax', 1);
    fd.append('action', 'profile');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('full_name', document.getElementById('fullName').value);
    fd.append('email', document.getElementById('email').value);
    fd.append('phone', document.getElementById('phone').value);
    fd.append('status_message', document.getElementById('bio').value);

    fetch('settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.csrf_token) CSRF_TOKEN = d.csrf_token;
            if (d.status === 'ok') {
                const nameVal = document.getElementById('fullName').value.trim();
                const emailVal = document.getElementById('email').value.trim();
                document.getElementById('displayName').textContent = nameVal;
                document.getElementById('displayEmail').textContent = emailVal;
                document.getElementById('sidebarName').textContent = nameVal;
                const initialChar = nameVal.charAt(0).toUpperCase();
                document.getElementById('sidebarAvatar').textContent = initialChar;
                const avatarInit = document.getElementById('avatarInitial');
                if (avatarInit) avatarInit.textContent = initialChar;

                showToast('Profile updated successfully');
            } else {
                showToast(d.message || 'Error saving profile', 'error');
            }
        })
        .catch(() => showToast('An error occurred while saving profile.', 'error'));
}

function resetProfile() {
    document.getElementById('fullName').value = <?php echo json_encode($user_display_name); ?>;
    document.getElementById('email').value = <?php echo json_encode($user['email']); ?>;
    document.getElementById('phone').value = <?php echo json_encode($user['phone'] ?? ''); ?>;
    document.getElementById('bio').value = <?php echo json_encode($user['status_message'] ?? ''); ?>;
    showToast('Reset to saved values');
}

function checkStrength(val) {
    const segs = ['s1','s2','s3','s4'].map(id => document.getElementById(id));
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const colors = ['#E11D48','#D97706','#4F46E5','#059669'];
    const labels = ['Weak','Fair','Good','Strong'];
    segs.forEach((s,i) => s.style.background = i < score ? colors[score-1] : 'var(--line)');
    label.textContent = val ? (labels[score-1] || 'Weak') : 'Enter a password';
    label.style.color = val ? colors[score-1] : 'var(--text-2)';
}

function savePassword() {
    const current = document.getElementById('currentPass').value;
    const newp = document.getElementById('newPass').value;
    const confirm = document.getElementById('confirmPass').value;
    if (!current || !newp || !confirm) return showToast('Please fill all fields', 'error');
    if (newp.length < 8) return showToast('Password needs at least 8 characters', 'error');
    if (!/[0-9]/.test(newp)) return showToast('Password needs at least one number', 'error');
    if (!/[A-Z]/.test(newp)) return showToast('Password needs at least one uppercase letter', 'error');
    if (!/[a-z]/.test(newp)) return showToast('Password needs at least one lowercase letter', 'error');
    if (newp !== confirm) return showToast('Passwords do not match', 'error');
    if (newp === current) return showToast('New password must be different from the current one', 'error');

    const fd = new FormData();
    fd.append('ajax', 1);
    fd.append('action', 'password');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('current_password', current);
    fd.append('new_password', newp);
    fd.append('confirm_password', confirm);

    fetch('settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.csrf_token) CSRF_TOKEN = d.csrf_token;
            if (d.status === 'ok') {
                showToast('Password updated successfully');
                ['currentPass','newPass','confirmPass'].forEach(id => document.getElementById(id).value = '');
                checkStrength('');
            } else {
                showToast(d.message || 'Error updating password', 'error');
            }
        })
        .catch(() => showToast('An error occurred while updating password.', 'error'));
}
</script>
</body>
</html>
