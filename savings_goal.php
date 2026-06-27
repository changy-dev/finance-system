<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$user_id = (int) $_SESSION['user_id'];

/* ── HANDLE ACTIONS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name       = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $target     = floatval($_POST['target_amount'] ?? 0);
        $saved      = floatval($_POST['current_saved'] ?? 0);
        $deadline   = trim($_POST['deadline'] ?? '');
        $emoji      = htmlspecialchars(trim($_POST['emoji'] ?? '🎯'), ENT_QUOTES, 'UTF-8');
        if ($name && $target > 0) {
            $stmt = $conn->prepare("INSERT INTO savings_goals (user_id,name,emoji,target_amount,current_saved,deadline) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('issdds', $user_id, $name, $emoji, $target, $saved, $deadline ?: null);
            echo json_encode(['success' => $stmt->execute()]);
        } else { echo json_encode(['success'=>false,'message'=>'Name and target required']); }
        exit;
    }

    if ($action === 'deposit') {
        $goal_id = (int)($_POST['goal_id'] ?? 0);
        $amount  = floatval($_POST['amount'] ?? 0);
        if ($goal_id && $amount > 0) {
            $stmt = $conn->prepare("UPDATE savings_goals SET current_saved = current_saved + ? WHERE goal_id=? AND user_id=?");
            $stmt->bind_param('dii', $amount, $goal_id, $user_id);
            echo json_encode(['success' => $stmt->execute()]);
        } else { echo json_encode(['success'=>false]); }
        exit;
    }

    if ($action === 'delete') {
        $goal_id = (int)($_POST['goal_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM savings_goals WHERE goal_id=? AND user_id=?");
        $stmt->bind_param('ii', $goal_id, $user_id);
        echo json_encode(['success' => $stmt->execute()]);
        exit;
    }
}

/* ── FETCH GOALS ── */
$goals = $conn->query("SELECT * FROM savings_goals WHERE user_id=$user_id ORDER BY created_at DESC");
$goals_arr = [];
while ($g = $goals->fetch_assoc()) $goals_arr[] = $g;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Savings Goals — Finance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{--ink:#0d0f12;--ink-2:#3a3f4a;--ink-3:#7a8394;--line:#e8eaed;--line-2:#f1f3f5;--surface:#fff;--page:#f6f7f9;--accent:#6366f1;--accent-lt:#eef2ff;--green:#10b981;--green-lt:#ecfdf5;--amber:#f59e0b;--amber-lt:#fffbeb;--red:#f43f5e;--red-lt:#fff1f2;--sidebar-w:240px;--r:10px;--r-lg:14px;--shadow:0 1px 3px rgba(0,0,0,.06);}
body{font-family:'DM Sans',sans-serif;background:var(--page);color:var(--ink);font-size:14px;}
.layout{display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--ink);position:fixed;top:0;left:0;height:100vh;display:flex;flex-direction:column;z-index:50;}
.sidebar-brand{padding:28px 20px 24px;border-bottom:1px solid rgba(255,255,255,.07);}
.brand-mark{display:flex;align-items:center;gap:10px;}
.brand-icon{width:34px;height:34px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;}
.brand-icon svg{width:18px;height:18px;color:#fff;}
.brand-name{font-size:15px;font-weight:600;color:#fff;}
.brand-tagline{font-size:11px;color:rgba(255,255,255,.4);margin-top:1px;}
.sidebar-nav{padding:16px 12px;flex:1;}
.nav-label{font-size:10px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.25);padding:0 8px;margin:8px 0 6px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:7px;color:rgba(255,255,255,.5);text-decoration:none;font-size:13.5px;margin-bottom:2px;transition:background .15s,color .15s;}
.nav-item svg{width:16px;height:16px;flex-shrink:0;}
.nav-item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.85);}
.nav-item.active{background:rgba(99,102,241,.2);color:#a5b4fc;font-weight:500;}
.sidebar-footer{padding:16px 12px;border-top:1px solid rgba(255,255,255,.06);}
.user-row{display:flex;align-items:center;gap:10px;padding:8px 10px;}
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#fff;flex-shrink:0;}
.user-name{font-size:12.5px;color:rgba(255,255,255,.7);font-weight:500;}
.main{flex:1;margin-left:var(--sidebar-w);padding:32px 36px;display:flex;flex-direction:column;gap:24px;}
.topbar{display:flex;align-items:center;justify-content:space-between;}
.page-title{font-size:22px;font-weight:600;letter-spacing:-.4px;}
.page-sub{font-size:13px;color:var(--ink-3);margin-top:2px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--r);font-size:13px;font-weight:500;cursor:pointer;border:none;transition:all .15s;text-decoration:none;}
.btn svg{width:14px;height:14px;}
.btn-primary{background:var(--accent);color:#fff;}
.btn-primary:hover{background:#4f46e5;}
.btn-ghost{background:var(--surface);color:var(--ink-2);border:1px solid var(--line);}
.goals-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;}
.goal-card{background:var(--surface);border-radius:var(--r-lg);padding:22px;border:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column;gap:14px;}
.goal-header{display:flex;align-items:flex-start;justify-content:space-between;}
.goal-emoji{font-size:28px;line-height:1;}
.goal-info{flex:1;margin-left:12px;}
.goal-name{font-size:15px;font-weight:600;margin-bottom:2px;}
.goal-deadline{font-size:11.5px;color:var(--ink-3);}
.goal-delete{background:none;border:none;cursor:pointer;color:var(--ink-3);padding:4px;border-radius:6px;display:flex;}
.goal-delete:hover{background:var(--red-lt);color:var(--red);}
.goal-delete svg{width:14px;height:14px;}
.goal-amounts{display:flex;justify-content:space-between;align-items:baseline;}
.saved-amount{font-size:20px;font-weight:600;font-family:'DM Mono',monospace;color:var(--green);}
.target-amount{font-size:13px;color:var(--ink-3);font-family:'DM Mono',monospace;}
.progress-bar{height:8px;background:var(--line-2);border-radius:100px;overflow:hidden;}
.progress-fill{height:100%;border-radius:100px;background:var(--green);transition:width .6s ease;}
.progress-fill.warning{background:var(--amber);}
.progress-fill.complete{background:var(--green);}
.progress-meta{display:flex;justify-content:space-between;font-size:11.5px;color:var(--ink-3);}
.goal-actions{display:flex;gap:8px;}
.deposit-input{flex:1;height:34px;padding:0 10px;border-radius:8px;border:1px solid var(--line);font-size:13px;font-family:inherit;outline:none;background:var(--page);}
.deposit-input:focus{border-color:var(--accent);background:#fff;}
.btn-sm{padding:5px 12px;font-size:12.5px;}
.empty-state{text-align:center;padding:64px 20px;background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line);}
.empty-state .icon{font-size:48px;margin-bottom:16px;}
.empty-state h3{font-size:18px;font-weight:600;margin-bottom:8px;}
.empty-state p{color:var(--ink-3);font-size:14px;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--surface);border-radius:var(--r-lg);width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--line);}
.modal-title{font-size:16px;font-weight:600;}
.modal-close{background:none;border:none;cursor:pointer;color:var(--ink-3);}
.modal-close svg{width:18px;height:18px;}
.modal-body{padding:24px;}
.form-group{margin-bottom:14px;}
.form-label{font-size:11.5px;font-weight:600;color:var(--ink-2);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px;}
.form-control{width:100%;height:38px;padding:0 12px;border-radius:8px;border:1px solid var(--line);font-size:13.5px;font-family:inherit;color:var(--ink);background:var(--page);outline:none;transition:border-color .15s;}
.form-control:focus{border-color:var(--accent);background:#fff;}
.emoji-picker{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;}
.emoji-btn{font-size:20px;border:2px solid transparent;background:var(--page);border-radius:8px;padding:4px 6px;cursor:pointer;transition:border-color .15s;}
.emoji-btn.selected{border-color:var(--accent);background:var(--accent-lt);}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding:16px 24px;border-top:1px solid var(--line);}
.toast{position:fixed;bottom:24px;right:24px;background:var(--ink);color:#fff;padding:12px 18px;border-radius:var(--r);font-size:13px;font-weight:500;z-index:500;transform:translateY(8px);opacity:0;transition:all .25s;pointer-events:none;}
.toast.show{opacity:1;transform:translateY(0);}
.toast.success{background:#065f46;}
.toast.error{background:#9f1239;}
@media(max-width:768px){.main{margin-left:0;padding:20px 16px;}.sidebar{display:none;}.goals-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
  <div class="sidebar-brand"><div class="brand-mark"><div class="brand-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M12 6v6l4 2"/></svg></div><div><div class="brand-name">Finance</div><div class="brand-tagline">Personal tracker</div></div></div></div>
  <nav class="sidebar-nav">
    <div class="nav-label">Menu</div>
    <a href="dashboard.php" class="nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard</a>
    <a href="transactions.php" class="nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>Transactions</a>
    <a href="budget.php" class="nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>Budget</a>
    <a href="savings_goals.php" class="nav-item active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 9c0 4-4 4-4 4H7v5H3V9a6 6 0 0 1 12 0h2z"/><circle cx="21" cy="14" r="3"/></svg>Savings Goals</a>
    <a href="recurring.php" class="nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>Recurring</a>
    <a href="debts.php" class="nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>Debts & Loans</a>
    <div class="nav-label" style="margin-top:16px;">Account</div>
    <a href="settings.php" class="nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings</a>
    <a href="logout.php" class="nav-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>Logout</a>
  </nav>
  <div class="sidebar-footer"><div class="user-row"><div class="user-avatar"><?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?></div><div class="user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></div></div></div>
</aside>

<main class="main">
  <div class="topbar">
    <div>
      <div class="page-title">Savings Goals</div>
      <div class="page-sub">Track progress toward your financial targets</div>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
      New Goal
    </button>
  </div>

  <?php if (empty($goals_arr)): ?>
  <div class="empty-state">
    <div class="icon">🎯</div>
    <h3>No savings goals yet</h3>
    <p>Start tracking your first goal — a holiday, an emergency fund, a new laptop, anything!</p>
    <br>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')">Create your first goal</button>
  </div>
  <?php else: ?>
  <div class="goals-grid" id="goalsGrid">
    <?php foreach ($goals_arr as $g):
      $pct = $g['target_amount'] > 0 ? min(100, round($g['current_saved'] / $g['target_amount'] * 100)) : 0;
      $remaining = max(0, $g['target_amount'] - $g['current_saved']);
      $fill_cls  = $pct >= 100 ? 'complete' : ($pct >= 80 ? 'warning' : '');
    ?>
    <div class="goal-card" id="goal-<?php echo $g['goal_id']; ?>">
      <div class="goal-header">
        <div style="font-size:28px;line-height:1"><?php echo htmlspecialchars($g['emoji'] ?? '🎯'); ?></div>
        <div class="goal-info">
          <div class="goal-name"><?php echo htmlspecialchars($g['name']); ?></div>
          <?php if (!empty($g['deadline'])): ?>
          <div class="goal-deadline">Target: <?php echo date('d M Y', strtotime($g['deadline'])); ?></div>
          <?php endif; ?>
        </div>
        <button class="goal-delete" onclick="deleteGoal(<?php echo $g['goal_id']; ?>)" title="Delete goal">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
        </button>
      </div>
      <div class="goal-amounts">
        <span class="saved-amount">KES <?php echo number_format($g['current_saved']); ?></span>
        <span class="target-amount">of KES <?php echo number_format($g['target_amount']); ?></span>
      </div>
      <div>
        <div class="progress-bar"><div class="progress-fill <?php echo $fill_cls; ?>" style="width:<?php echo $pct; ?>%"></div></div>
        <div class="progress-meta">
          <span><?php echo $pct; ?>% saved</span>
          <span><?php echo $pct >= 100 ? '🎉 Goal reached!' : 'KES '.number_format($remaining).' to go'; ?></span>
        </div>
      </div>
      <?php if ($pct < 100): ?>
      <div class="goal-actions">
        <input type="number" class="deposit-input" id="dep-<?php echo $g['goal_id']; ?>" placeholder="Amount to add…" min="1" step="any">
        <button class="btn btn-primary btn-sm" onclick="deposit(<?php echo $g['goal_id']; ?>)">Add</button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
</div>

<!-- ADD GOAL MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">New Savings Goal</div>
      <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Pick an emoji</label>
        <div class="emoji-picker" id="emojiPicker">
          <?php foreach(['🎯','🏠','✈️','🚗','💻','📱','🎓','💍','🏖️','💊','🐶','🎸','🏋️','📦','💰'] as $em): ?>
          <button type="button" class="emoji-btn <?php echo $em==='🎯'?'selected':''; ?>" onclick="selectEmoji(this,'<?php echo $em; ?>')"><?php echo $em; ?></button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="selEmoji" value="🎯">
      </div>
      <div class="form-group">
        <label class="form-label">Goal Name</label>
        <input type="text" id="gName" class="form-control" placeholder="e.g. Emergency Fund, Holiday…">
      </div>
      <div class="form-group">
        <label class="form-label">Target Amount (KES)</label>
        <input type="number" id="gTarget" class="form-control" min="1" step="any" placeholder="0.00">
      </div>
      <div class="form-group">
        <label class="form-label">Already Saved (KES)</label>
        <input type="number" id="gSaved" class="form-control" min="0" step="any" placeholder="0.00" value="0">
      </div>
      <div class="form-group">
        <label class="form-label">Target Date (optional)</label>
        <input type="date" id="gDeadline" class="form-control">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
      <button class="btn btn-primary" onclick="addGoal()">Create Goal</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
function selectEmoji(btn, em) {
  document.querySelectorAll('.emoji-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('selEmoji').value = em;
}

async function addGoal() {
  const name   = document.getElementById('gName').value.trim();
  const target = document.getElementById('gTarget').value;
  const saved  = document.getElementById('gSaved').value || 0;
  const dead   = document.getElementById('gDeadline').value;
  const emoji  = document.getElementById('selEmoji').value;
  if (!name || !target) { showToast('Name and target are required.','error'); return; }
  const fd = new FormData();
  fd.append('action','add'); fd.append('name',name); fd.append('target_amount',target);
  fd.append('current_saved',saved); fd.append('deadline',dead); fd.append('emoji',emoji);
  const res = await fetch('savings_goals.php', {method:'POST', body:fd});
  const json = await res.json();
  if (json.success) { showToast('Goal created!','success'); setTimeout(()=>location.reload(),800); }
  else showToast(json.message||'Error','error');
}

async function deposit(goalId) {
  const amt = document.getElementById('dep-'+goalId).value;
  if (!amt || amt <= 0) { showToast('Enter an amount.','error'); return; }
  const fd = new FormData();
  fd.append('action','deposit'); fd.append('goal_id',goalId); fd.append('amount',amt);
  const res = await fetch('savings_goals.php', {method:'POST', body:fd});
  const json = await res.json();
  if (json.success) { showToast('Savings updated!','success'); setTimeout(()=>location.reload(),800); }
  else showToast('Error','error');
}

async function deleteGoal(goalId) {
  if (!confirm('Delete this savings goal?')) return;
  const fd = new FormData();
  fd.append('action','delete'); fd.append('goal_id',goalId);
  const res = await fetch('savings_goals.php', {method:'POST', body:fd});
  const json = await res.json();
  if (json.success) { document.getElementById('goal-'+goalId).remove(); showToast('Goal deleted.','success'); }
  else showToast('Error','error');
}

function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast show ' + type;
  setTimeout(()=>t.classList.remove('show'),3000);
}
document.getElementById('addModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
</script>
</body>
</html>