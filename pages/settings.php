<?php
session_start();
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/security.php';
if (!function_exists('csrf_token')) { require_once __DIR__ . '/../core/csrf.php'; }
// $user_id is set by security.php
$user = $conn->query("SELECT * FROM users WHERE user_id=$user_id")->fetch_assoc();

/* ── HANDLE AJAX SAVE ── */
if (isset($_POST['ajax'])) {
    csrf_verify();
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'profile') {
        $name  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $bio   = trim($_POST['status_message'] ?? '');

        if (!empty($_FILES['profile_pic']['name'])) {
            $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
            $mime    = mime_content_type($_FILES['profile_pic']['tmp_name']);
            if (in_array($mime, $allowed)) {
                $ext  = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
                $file = time() . '_' . $user_id . '.' . $ext;
                $uploadDir = __DIR__ . '/../uploads/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $file)) {
                    $stmt = $conn->prepare("UPDATE users SET profile_pic=? WHERE user_id=?");
                    $stmt->bind_param("si", $file, $user_id);
                    $stmt->execute(); $stmt->close();
                }
            }
        }

        $stmt = $conn->prepare("UPDATE users SET full_name=?, phone=?, status_message=? WHERE user_id=?");
        $stmt->bind_param("sssi", $name, $phone, $bio, $user_id);
        $stmt->execute(); $stmt->close();
        $updated = $conn->query("SELECT profile_pic FROM users WHERE user_id=$user_id")->fetch_assoc();
        echo json_encode(['status' => 'ok', 'name' => $name, 'profile_pic' => $updated['profile_pic'] ?: 'default.png']);

    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $row = $conn->query("SELECT password FROM users WHERE user_id=$user_id")->fetch_assoc();

        if (!password_verify($current, $row['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect']);
        } elseif (strlen($new) < 8) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters']);
        } elseif ($new !== $confirm) {
            echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
        } else {
            $hashed = password_hash($new, PASSWORD_BCRYPT);
            $stmt   = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
            $stmt->bind_param("si", $hashed, $user_id);
            $stmt->execute(); $stmt->close();
            echo json_encode(['status' => 'ok']);
        }
    }
    exit();
}
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
            <div class="nav-section-label" style="margin-top:16px;">Account</div>
            <a href="../pages/settings.php" class="nav-item active" title="Settings">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="nav-label">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'] ?: $user['name'], 0, 1)); ?></div>
                <div class="nav-label">
                    <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['name']); ?></div>
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
                <a href="../pages/dashboard.php" class="btn btn-ghost">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back to Dashboard
                </a>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <div class="page-header">
                <h1 class="page-title">Settings</h1>
                <p class="page-sub">Manage your profile and account security</p>
            </div>

            <div class="settings-tabs">
                <button class="settings-tab active" onclick="switchTab('profile', this)">Profile</button>
                <button class="settings-tab" onclick="switchTab('security', this)">Security</button>
            </div>

            <!-- PROFILE -->
            <div class="settings-panel active" id="tab-profile">
                <div class="card">
                    <div class="card-title">Personal Information</div>
                    <div class="card-sub" style="margin-bottom:20px;">Update your name, phone number and bio</div>

                    <div class="avatar-section">
                        <div class="avatar-wrap" onclick="document.getElementById('avatarInput').click()">
                            <img id="avatarPreview" src="../uploads/<?php echo htmlspecialchars($user['profile_pic'] ?: 'default.png'); ?>" alt="Avatar">
                            <div class="avatar-overlay">📷<br>Change</div>
                        </div>
                        <div class="avatar-info">
                            <h3 id="displayName"><?php echo htmlspecialchars($user['full_name'] ?: $user['name']); ?></h3>
                            <p id="displayPhone"><?php echo htmlspecialchars($user['phone'] ?? 'No phone set'); ?></p>
                            <span class="badge pill-neutral" style="margin-top:6px;"><?php echo strtoupper($user['role']); ?></span>
                        </div>
                        <input type="file" id="avatarInput" accept="image/*" style="display:none" onchange="previewAvatar(this)">
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label>Full Name</label>
                            <input type="text" id="fullName" value="<?php echo htmlspecialchars($user['full_name'] ?: $user['name']); ?>" placeholder="Your full name">
                        </div>
                        <div class="field">
                            <label>Phone Number</label>
                            <input type="tel" id="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+254 700 000 000">
                        </div>
                    </div>
                    <div class="field">
                        <label>Email Address</label>
                        <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="opacity:.5;cursor:not-allowed;">
                    </div>
                    <div class="field">
                        <label>Bio / Status Message</label>
                        <textarea id="bio" placeholder="Tell others a little about yourself..."><?php echo htmlspecialchars($user['status_message'] ?? ''); ?></textarea>
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
const CSRF_TOKEN = "<?php echo csrf_token(); ?>";

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

function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => document.getElementById('avatarPreview').src = e.target.result;
        reader.readAsDataURL(input.files[0]);
    }
}

function saveProfile() {
    const fd = new FormData();
    fd.append('ajax', 1); fd.append('action', 'profile'); fd.append('csrf_token', CSRF_TOKEN);
    fd.append('full_name', document.getElementById('fullName').value);
    fd.append('phone', document.getElementById('phone').value);
    fd.append('status_message', document.getElementById('bio').value);
    const file = document.getElementById('avatarInput').files[0];
    if (file) fd.append('profile_pic', file);
    fetch('settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                document.getElementById('displayName').textContent = document.getElementById('fullName').value;
                const phone = document.getElementById('phone').value;
                document.getElementById('displayPhone').textContent = phone || 'No phone set';
                if (d.profile_pic) document.getElementById('avatarPreview').src = '../uploads/' + d.profile_pic + '?v=' + Date.now();
                document.getElementById('avatarInput').value = '';
                showToast('Profile updated successfully');
            } else {
                showToast(d.message || 'Error saving', 'error');
            }
        });
}

function resetProfile() {
    document.getElementById('fullName').value = <?php echo json_encode($user['full_name'] ?: $user['name']); ?>;
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
    if (newp !== confirm) return showToast('Passwords do not match', 'error');
    const fd = new FormData();
    fd.append('ajax', 1); fd.append('action', 'password'); fd.append('csrf_token', CSRF_TOKEN);
    fd.append('current_password', current); fd.append('new_password', newp); fd.append('confirm_password', confirm);
    fetch('settings.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                showToast('Password updated successfully');
                ['currentPass','newPass','confirmPass'].forEach(id => document.getElementById(id).value = '');
                checkStrength('');
            } else {
                showToast(d.message, 'error');
            }
        });
}
</script>
</body>
</html>