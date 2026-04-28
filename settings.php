<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = (int) $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE user_id=$user_id")->fetch_assoc();

/* ── HANDLE AJAX SAVE ── */
if (isset($_POST['ajax'])) {
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
                move_uploaded_file($_FILES['profile_pic']['tmp_name'], "uploads/" . $file);
                $stmt = $conn->prepare("UPDATE users SET profile_pic=? WHERE user_id=?");
                $stmt->bind_param("si", $file, $user_id);
                $stmt->execute(); $stmt->close();
            }
        }

        $stmt = $conn->prepare("UPDATE users SET full_name=?, phone=?, status_message=? WHERE user_id=?");
        $stmt->bind_param("sssi", $name, $phone, $bio, $user_id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['status' => 'ok', 'name' => $name]);

    } elseif ($action === 'appearance') {
        $theme = in_array($_POST['theme'] ?? '', ['dark','light','auto']) ? $_POST['theme'] : 'dark';
        $lang  = in_array($_POST['language'] ?? '', ['en','sw','fr','ar']) ? $_POST['language'] : 'en';
        $stmt  = $conn->prepare("UPDATE users SET theme=?, language=? WHERE user_id=?");
        $stmt->bind_param("ssi", $theme, $lang, $user_id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['status' => 'ok']);

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
<html lang="en" data-theme="<?php echo htmlspecialchars($user['theme'] ?? 'dark'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — Royal Matrix</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #080c14;
  --surface: #0e1420;
  --surface2: #141b28;
  --border: rgba(255,255,255,0.07);
  --text: #e8edf5;
  --muted: #5a6478;
  --accent: #6c63ff;
  --accent2: #00d4ff;
  --danger: #ff4d6d;
  --success: #00e5a0;
  --warning: #ffb830;
  --radius: 16px;
}
[data-theme="light"] {
  --bg: #f0f4ff;
  --surface: #ffffff;
  --surface2: #f7f9ff;
  --border: rgba(0,0,0,0.08);
  --text: #0f1623;
  --muted: #8892a4;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; }

/* NAV */
.settings-nav {
  width: 260px; min-height: 100vh; background: var(--surface);
  border-right: 1px solid var(--border); padding: 32px 20px;
  display: flex; flex-direction: column; gap: 4px;
  position: sticky; top: 0; height: 100vh; overflow-y: auto;
}
.nav-logo { font-family: 'Syne', sans-serif; font-size: 18px; font-weight: 800; color: var(--text); margin-bottom: 32px; padding: 0 12px; display: flex; align-items: center; gap: 10px; }
.nav-logo span { background: linear-gradient(135deg, var(--accent), var(--accent2)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.nav-section-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--muted); padding: 16px 12px 6px; font-weight: 600; }
.nav-item { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 12px; cursor: pointer; font-size: 14px; font-weight: 500; color: var(--muted); transition: all 0.2s; border: 1px solid transparent; text-decoration: none; }
.nav-item:hover { background: var(--surface2); color: var(--text); }
.nav-item.active { background: linear-gradient(135deg, rgba(108,99,255,0.15), rgba(0,212,255,0.08)); color: var(--text); border-color: rgba(108,99,255,0.25); }
.nav-item .icon { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 16px; background: var(--surface2); }
.nav-item.active .icon { background: linear-gradient(135deg, var(--accent), var(--accent2)); }
.nav-back { margin-top: auto; display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 12px; color: var(--muted); font-size: 13px; cursor: pointer; text-decoration: none; transition: 0.2s; }
.nav-back:hover { color: var(--text); }

/* MAIN */
.main { flex: 1; padding: 48px; max-width: 780px; }
.page-header { margin-bottom: 36px; }
.page-header h1 { font-family: 'Syne', sans-serif; font-size: 28px; font-weight: 800; margin-bottom: 6px; }
.page-header p { color: var(--muted); font-size: 14px; }

/* PANELS */
.panel { display: none; }
.panel.active { display: block; }

/* CARDS */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 28px; margin-bottom: 20px; }
.card-title { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; margin-bottom: 4px; }
.card-desc { color: var(--muted); font-size: 13px; margin-bottom: 24px; }

/* AVATAR */
.avatar-section { display: flex; align-items: center; gap: 24px; margin-bottom: 28px; }
.avatar-wrap { position: relative; width: 88px; height: 88px; flex-shrink: 0; }
.avatar-wrap img { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; border: 3px solid var(--border); }
.avatar-overlay { position: absolute; inset: 0; border-radius: 50%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; opacity: 0; cursor: pointer; transition: 0.2s; font-size: 18px; }
.avatar-wrap:hover .avatar-overlay { opacity: 1; }
.avatar-info h3 { font-size: 16px; font-weight: 600; margin-bottom: 3px; }
.avatar-info p { color: var(--muted); font-size: 13px; margin-bottom: 10px; }
.avatar-btn { font-size: 12px; padding: 7px 14px; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text); cursor: pointer; font-family: inherit; transition: 0.2s; }
.avatar-btn:hover { border-color: var(--accent); color: var(--accent); }

/* FORM */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }
.form-group label { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
.form-group input, .form-group select, .form-group textarea { background: var(--surface2); border: 1px solid var(--border); border-radius: 10px; padding: 11px 14px; color: var(--text); font-family: inherit; font-size: 14px; transition: 0.2s; width: 100%; outline: none; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(108,99,255,0.12); }
.form-group textarea { resize: vertical; min-height: 80px; }
.form-group select option { background: var(--surface); }

/* TOGGLE */
.toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid var(--border); }
.toggle-row:last-child { border-bottom: none; }
.toggle-info h4 { font-size: 14px; font-weight: 500; margin-bottom: 2px; }
.toggle-info p { color: var(--muted); font-size: 12px; }
.toggle { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; border-radius: 24px; background: var(--surface2); border: 1px solid var(--border); cursor: pointer; transition: 0.3s; }
.toggle-slider::before { content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%; background: var(--muted); top: 2px; left: 2px; transition: 0.3s; }
.toggle input:checked + .toggle-slider { background: var(--accent); border-color: var(--accent); }
.toggle input:checked + .toggle-slider::before { transform: translateX(20px); background: white; }

/* THEME CARDS */
.theme-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
.theme-card { border: 2px solid var(--border); border-radius: 12px; padding: 14px; cursor: pointer; transition: 0.2s; text-align: center; }
.theme-card:hover { border-color: var(--accent); }
.theme-card.selected { border-color: var(--accent); background: rgba(108,99,255,0.08); }
.theme-preview { width: 100%; height: 50px; border-radius: 8px; margin-bottom: 8px; }
.theme-preview.dark { background: linear-gradient(135deg, #080c14, #0e1420); }
.theme-preview.light { background: linear-gradient(135deg, #f0f4ff, #ffffff); border: 1px solid #e2e8f0; }
.theme-preview.auto { background: linear-gradient(135deg, #080c14 50%, #f0f4ff 50%); }
.theme-label { font-size: 12px; font-weight: 600; }

/* LANGUAGE GRID */
.lang-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.lang-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 2px solid var(--border); border-radius: 12px; cursor: pointer; transition: 0.2s; font-size: 14px; }
.lang-card:hover { border-color: var(--accent); }
.lang-card.selected { border-color: var(--accent); background: rgba(108,99,255,0.08); }
.lang-flag { font-size: 22px; }
.lang-info small { color: var(--muted); font-size: 11px; display: block; }

/* PASSWORD STRENGTH */
.strength-bar { display: flex; gap: 4px; margin-top: 8px; }
.strength-seg { flex: 1; height: 3px; border-radius: 3px; background: var(--border); transition: 0.3s; }
.strength-label { font-size: 11px; color: var(--muted); margin-top: 4px; }

/* DANGER */
.danger-card { background: rgba(255,77,109,0.04); border: 1px solid rgba(255,77,109,0.2); border-radius: var(--radius); padding: 28px; margin-bottom: 20px; }
.danger-card .card-title { color: var(--danger); }

/* BUTTONS */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: 10px; border: none; font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-primary { background: linear-gradient(135deg, var(--accent), #8b80ff); color: white; box-shadow: 0 4px 15px rgba(108,99,255,0.3); }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(108,99,255,0.4); }
.btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
.btn-secondary:hover { border-color: var(--accent); color: var(--accent); }
.btn-danger { background: rgba(255,77,109,0.1); color: var(--danger); border: 1px solid rgba(255,77,109,0.3); }
.btn-danger:hover { background: var(--danger); color: white; }
.btn-row { display: flex; gap: 10px; margin-top: 4px; }

/* BADGES */
.info-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.badge-admin { background: rgba(255,184,48,0.15); color: var(--warning); }
.badge-user { background: rgba(108,99,255,0.15); color: var(--accent); }
.badge-active { background: rgba(0,229,160,0.15); color: var(--success); }

/* STATS */
.stats-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; margin-bottom: 20px; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; text-align: center; }
.stat-card .stat-val { font-family: 'Syne', sans-serif; font-size: 24px; font-weight: 800; background: linear-gradient(135deg, var(--accent), var(--accent2)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.stat-card .stat-label { font-size: 11px; color: var(--muted); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px; }

/* TOAST */
.toast { position: fixed; bottom: 30px; right: 30px; padding: 14px 22px; border-radius: 12px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; z-index: 9999; transform: translateY(80px); opacity: 0; transition: all 0.35s cubic-bezier(.34,1.56,.64,1); box-shadow: 0 8px 32px rgba(0,0,0,0.4); }
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { background: rgba(0,229,160,0.12); border: 1px solid rgba(0,229,160,0.3); color: var(--success); }
.toast.error { background: rgba(255,77,109,0.12); border: 1px solid rgba(255,77,109,0.3); color: var(--danger); }

@media (max-width: 768px) {
  body { flex-direction: column; }
  .settings-nav { width: 100%; min-height: auto; height: auto; position: relative; flex-direction: row; flex-wrap: wrap; padding: 16px; gap: 8px; }
  .nav-logo, .nav-section-label, .nav-back { display: none; }
  .nav-item { padding: 8px 14px; font-size: 12px; }
  .main { padding: 20px; }
  .form-row { grid-template-columns: 1fr; }
  .theme-grid { grid-template-columns: 1fr 1fr; }
  .stats-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<nav class="settings-nav">
  <div class="nav-logo">⚙️ <span>Settings</span></div>
  <div class="nav-section-label">Account</div>
  <div class="nav-item active" onclick="switchPanel('profile', this)"><div class="icon">👤</div> Profile</div>
  <div class="nav-item" onclick="switchPanel('security', this)"><div class="icon">🔒</div> Security</div>
  <div class="nav-section-label">Preferences</div>
  <div class="nav-item" onclick="switchPanel('appearance', this)"><div class="icon">🎨</div> Appearance</div>
  <div class="nav-item" onclick="switchPanel('notifications', this)"><div class="icon">🔔</div> Notifications</div>
  <div class="nav-section-label">System</div>
  <div class="nav-item" onclick="switchPanel('danger', this)"><div class="icon">⚠️</div> Danger Zone</div>
  <a href="dashboard.php" class="nav-back">← Back to Dashboard</a>
</nav>

<main class="main">

  <!-- PROFILE -->
  <div class="panel active" id="panel-profile">
    <div class="page-header">
      <h1>Profile Settings</h1>
      <p>Manage your personal information and public profile</p>
    </div>
    <div class="stats-row">
      <div class="stat-card"><div class="stat-val"><?php echo ucfirst($user['role']); ?></div><div class="stat-label">Account Type</div></div>
      <div class="stat-card"><div class="stat-val"><?php echo $user['warnings']; ?>/3</div><div class="stat-label">Warnings</div></div>
      <div class="stat-card"><div class="stat-val"><?php echo $user['is_verified'] ? '✓' : '✗'; ?></div><div class="stat-label">Verified</div></div>
    </div>
    <div class="card">
      <div class="card-title">Personal Information</div>
      <div class="card-desc">Update your name, phone number and bio</div>
      <div class="avatar-section">
        <div class="avatar-wrap" onclick="document.getElementById('avatarInput').click()">
          <img id="avatarPreview" src="uploads/<?php echo htmlspecialchars($user['profile_pic'] ?: 'default.png'); ?>" alt="Avatar">
          <div class="avatar-overlay">📷</div>
        </div>
        <div class="avatar-info">
          <h3 id="displayName"><?php echo htmlspecialchars($user['full_name'] ?: $user['name']); ?></h3>
          <p><?php echo htmlspecialchars($user['email']); ?></p>
          <span class="info-badge <?php echo $user['role']==='admin'?'badge-admin':'badge-user'; ?>"><?php echo strtoupper($user['role']); ?></span>
          &nbsp;<span class="info-badge badge-active"><?php echo strtoupper($user['status']); ?></span>
        </div>
        <input type="file" id="avatarInput" accept="image/*" style="display:none" onchange="previewAvatar(this)">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" id="fullName" value="<?php echo htmlspecialchars($user['full_name'] ?: $user['name']); ?>" placeholder="Your full name">
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="tel" id="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+254 700 000 000">
        </div>
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="opacity:0.5;cursor:not-allowed;">
      </div>
      <div class="form-group">
        <label>Bio / Status Message</label>
        <textarea id="bio" placeholder="Tell others a little about yourself..."><?php echo htmlspecialchars($user['status_message'] ?? ''); ?></textarea>
      </div>
      <div class="btn-row">
        <button class="btn btn-primary" onclick="saveProfile()">💾 Save Changes</button>
        <button class="btn btn-secondary" onclick="resetProfile()">↺ Reset</button>
      </div>
    </div>
  </div>

  <!-- SECURITY -->
  <div class="panel" id="panel-security">
    <div class="page-header"><h1>Security</h1><p>Manage your password and account access</p></div>
    <div class="card">
      <div class="card-title">Change Password</div>
      <div class="card-desc">Use a strong password with at least 8 characters</div>
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" id="currentPass" placeholder="Enter current password">
      </div>
      <div class="form-group">
        <label>New Password</label>
        <input type="password" id="newPass" placeholder="Enter new password" oninput="checkStrength(this.value)">
        <div class="strength-bar">
          <div class="strength-seg" id="s1"></div><div class="strength-seg" id="s2"></div>
          <div class="strength-seg" id="s3"></div><div class="strength-seg" id="s4"></div>
        </div>
        <div class="strength-label" id="strengthLabel">Enter a password</div>
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" id="confirmPass" placeholder="Confirm new password">
      </div>
      <button class="btn btn-primary" onclick="savePassword()">🔐 Update Password</button>
    </div>
    <div class="card">
      <div class="card-title">Account Status</div>
      <div class="card-desc">Your current account standing</div>
      <div class="toggle-row">
        <div class="toggle-info"><h4>Account Verified</h4><p>Your identity has <?php echo $user['is_verified']?'':'not '; ?>been verified</p></div>
        <span class="info-badge <?php echo $user['is_verified']?'badge-active':'badge-user'; ?>"><?php echo $user['is_verified']?'VERIFIED':'UNVERIFIED'; ?></span>
      </div>
      <div class="toggle-row">
        <div class="toggle-info"><h4>Warnings</h4><p>Account warnings received</p></div>
        <span class="info-badge <?php echo $user['warnings']>0?'badge-admin':'badge-active'; ?>"><?php echo $user['warnings']; ?> / 3</span>
      </div>
    </div>
  </div>

  <!-- APPEARANCE -->
  <div class="panel" id="panel-appearance">
    <div class="page-header"><h1>Appearance</h1><p>Customize how Royal Matrix looks for you</p></div>
    <div class="card">
      <div class="card-title">Theme</div>
      <div class="card-desc">Choose your preferred color scheme</div>
      <div class="theme-grid">
        <div class="theme-card <?php echo ($user['theme']==='dark'||!$user['theme'])?'selected':''; ?>" onclick="selectTheme('dark',this)">
          <div class="theme-preview dark"></div><div class="theme-label">🌙 Dark</div>
        </div>
        <div class="theme-card <?php echo $user['theme']==='light'?'selected':''; ?>" onclick="selectTheme('light',this)">
          <div class="theme-preview light"></div><div class="theme-label">☀️ Light</div>
        </div>
        <div class="theme-card <?php echo $user['theme']==='auto'?'selected':''; ?>" onclick="selectTheme('auto',this)">
          <div class="theme-preview auto"></div><div class="theme-label">🖥️ Auto</div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-title">Language</div>
      <div class="card-desc">Select your preferred display language</div>
      <div class="lang-grid">
        <?php
        $langs=['en'=>['🇬🇧','English','Default'],'sw'=>['🇰🇪','Swahili','Kiswahili'],'fr'=>['🇫🇷','French','Français'],'ar'=>['🇸🇦','Arabic','العربية']];
        foreach($langs as $code=>[$flag,$name,$native]):?>
        <div class="lang-card <?php echo $user['language']===$code?'selected':''; ?>" onclick="selectLang('<?php echo $code;?>',this)">
          <div class="lang-flag"><?php echo $flag;?></div>
          <div class="lang-info"><strong><?php echo $name;?></strong><small><?php echo $native;?></small></div>
        </div>
        <?php endforeach;?>
      </div>
      <br>
      <button class="btn btn-primary" onclick="saveAppearance()">💾 Save Preferences</button>
    </div>
  </div>

  <!-- NOTIFICATIONS -->
  <div class="panel" id="panel-notifications">
    <div class="page-header"><h1>Notifications</h1><p>Control what alerts and updates you receive</p></div>
    <div class="card">
      <div class="card-title">Email Notifications</div>
      <div class="card-desc">Choose which emails you'd like to receive</div>
      <div class="toggle-row">
        <div class="toggle-info"><h4>Transaction Alerts</h4><p>Get notified when a transaction is added or deleted</p></div>
        <label class="toggle"><input type="checkbox" checked><span class="toggle-slider"></span></label>
      </div>
      <div class="toggle-row">
        <div class="toggle-info"><h4>Budget Warnings</h4><p>Alert when you're close to exceeding your budget</p></div>
        <label class="toggle"><input type="checkbox" checked><span class="toggle-slider"></span></label>
      </div>
      <div class="toggle-row">
        <div class="toggle-info"><h4>Monthly Reports</h4><p>Receive a summary of your finances each month</p></div>
        <label class="toggle"><input type="checkbox"><span class="toggle-slider"></span></label>
      </div>
      <div class="toggle-row">
        <div class="toggle-info"><h4>Security Alerts</h4><p>Notify me of login attempts and password changes</p></div>
        <label class="toggle"><input type="checkbox" checked><span class="toggle-slider"></span></label>
      </div>
    </div>
  </div>

  <!-- DANGER ZONE -->
  <div class="panel" id="panel-danger">
    <div class="page-header"><h1>Danger Zone</h1><p>Irreversible actions — proceed with caution</p></div>
    <div class="danger-card">
      <div class="card-title">⚠️ Delete All Transactions</div>
      <div class="card-desc" style="margin-bottom:16px">Permanently remove all your transaction history. This cannot be undone.</div>
      <button class="btn btn-danger" onclick="confirmAction('delete_transactions')">🗑️ Delete All Transactions</button>
    </div>
    <div class="danger-card">
      <div class="card-title">⚠️ Deactivate Account</div>
      <div class="card-desc" style="margin-bottom:16px">Temporarily disable your account. You can reactivate by logging back in.</div>
      <button class="btn btn-danger" onclick="confirmAction('deactivate')">🚫 Deactivate Account</button>
    </div>
  </div>

</main>

<div class="toast" id="toast"></div>

<script>
function switchPanel(name, el) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('panel-' + name).classList.add('active');
  el.classList.add('active');
  window.scrollTo(0, 0);
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = (type === 'success' ? '✅ ' : '❌ ') + msg;
  t.className = 'toast ' + type + ' show';
  setTimeout(() => t.classList.remove('show'), 3500);
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
  fd.append('ajax', 1); fd.append('action', 'profile');
  fd.append('full_name', document.getElementById('fullName').value);
  fd.append('phone', document.getElementById('phone').value);
  fd.append('status_message', document.getElementById('bio').value);
  const file = document.getElementById('avatarInput').files[0];
  if (file) fd.append('profile_pic', file);
  fetch('settings.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.status === 'ok') { document.getElementById('displayName').textContent = document.getElementById('fullName').value; showToast('Profile updated successfully'); }
      else showToast(d.message || 'Error saving', 'error');
    });
}

function resetProfile() {
  document.getElementById('fullName').value = "<?php echo addslashes($user['full_name'] ?: $user['name']); ?>";
  document.getElementById('phone').value = "<?php echo addslashes($user['phone'] ?? ''); ?>";
  document.getElementById('bio').value = "<?php echo addslashes($user['status_message'] ?? ''); ?>";
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
  const colors = ['#ff4d6d','#ffb830','#6c63ff','#00e5a0'];
  const labels = ['Weak','Fair','Good','Strong'];
  segs.forEach((s,i) => s.style.background = i < score ? colors[score-1] : 'var(--border)');
  label.textContent = val ? (labels[score-1] || 'Weak') : 'Enter a password';
  label.style.color = val ? colors[score-1] : 'var(--muted)';
}

function savePassword() {
  const current = document.getElementById('currentPass').value;
  const newp = document.getElementById('newPass').value;
  const confirm = document.getElementById('confirmPass').value;
  if (!current || !newp || !confirm) return showToast('Please fill all fields', 'error');
  if (newp !== confirm) return showToast('Passwords do not match', 'error');
  const fd = new FormData();
  fd.append('ajax', 1); fd.append('action', 'password');
  fd.append('current_password', current); fd.append('new_password', newp); fd.append('confirm_password', confirm);
  fetch('settings.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.status === 'ok') { showToast('Password updated successfully'); ['currentPass','newPass','confirmPass'].forEach(id => document.getElementById(id).value=''); checkStrength(''); }
      else showToast(d.message, 'error');
    });
}

let selectedTheme = "<?php echo $user['theme'] ?? 'dark'; ?>";
function selectTheme(theme, el) {
  selectedTheme = theme;
  document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  document.documentElement.setAttribute('data-theme', theme === 'auto' ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark') : theme);
}

let selectedLang = "<?php echo $user['language'] ?? 'en'; ?>";
function selectLang(lang, el) {
  selectedLang = lang;
  document.querySelectorAll('.lang-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
}

function saveAppearance() {
  const fd = new FormData();
  fd.append('ajax', 1); fd.append('action', 'appearance');
  fd.append('theme', selectedTheme); fd.append('language', selectedLang);
  fetch('settings.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.status === 'ok') showToast('Appearance saved'); else showToast('Error saving', 'error'); });
}

function confirmAction(action) {
  const msgs = { delete_transactions: 'Are you sure you want to delete ALL your transactions? This cannot be undone!', deactivate: 'Are you sure you want to deactivate your account?' };
  if (confirm(msgs[action])) showToast('Action logged — feature coming soon', 'error');
}
</script>
</body>
</html>
