<?php
session_start();
require_once 'db.php';
require_once 'csrf.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int) $_SESSION['user_id'];

/* ══════════════════════════════════════════════
   AJAX / POST HANDLER
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    csrf_verify_json();

    $action = $_POST['action'] ?? '';

    /* ── ADD record ── */
    if ($action === 'add') {
        $record_type   = in_array($_POST['record_type'] ?? '', ['debt','loan']) ? $_POST['record_type'] : null;
        $title         = mb_substr(trim($_POST['title']        ?? ''), 0, 255);
        $counterparty  = mb_substr(trim($_POST['counterparty'] ?? ''), 0, 255);
        $principal     = floatval($_POST['principal']    ?? 0);
        $amount_paid   = floatval($_POST['amount_paid']  ?? 0);
        $interest_rate = ($_POST['interest_rate'] ?? '') !== '' ? floatval($_POST['interest_rate']) : null;
        $due_date      = !empty($_POST['due_date'])  ? $_POST['due_date']  : null;
        $notes         = mb_substr(trim($_POST['notes'] ?? ''), 0, 1000);

        if (!$record_type || !$title || !$counterparty || $principal <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields.']); exit;
        }

        $status = 'active';
        if ($amount_paid >= $principal) $status = 'paid';
        elseif ($due_date && $due_date < date('Y-m-d') && $amount_paid < $principal) $status = 'overdue';

        $stmt = $conn->prepare("
            INSERT INTO debts_loans
              (user_id, record_type, title, counterparty, principal, amount_paid, interest_rate, due_date, status, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('isssdddsss',
            $user_id, $record_type, $title, $counterparty,
            $principal, $amount_paid, $interest_rate, $due_date, $status, $notes
        );
        echo json_encode(['success' => $stmt->execute(), 'id' => $conn->insert_id]);
        exit;
    }

    /* ── LOG PAYMENT ── */
    if ($action === 'pay') {
        $debt_id  = (int)($_POST['debt_id'] ?? 0);
        $amount   = floatval($_POST['amount'] ?? 0);
        $paid_on  = !empty($_POST['paid_on']) ? $_POST['paid_on'] : date('Y-m-d');
        $note     = mb_substr(trim($_POST['note'] ?? ''), 0, 255);

        if ($debt_id <= 0 || $amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment data.']); exit;
        }

        // Verify ownership
        $chk = $conn->prepare("SELECT id, principal, amount_paid FROM debts_loans WHERE id=? AND user_id=?");
        $chk->bind_param('ii', $debt_id, $user_id);
        $chk->execute();
        $rec = $chk->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(['success' => false, 'message' => 'Record not found.']); exit; }

        // Insert payment log
        $ins = $conn->prepare("INSERT INTO debt_payments (debt_id, user_id, amount, paid_on, note) VALUES (?,?,?,?,?)");
        $ins->bind_param('iidss', $debt_id, $user_id, $amount, $paid_on, $note);
        $ins->execute();

        // Update amount_paid + recalculate status
        $new_paid = $rec['amount_paid'] + $amount;
        $status   = ($new_paid >= $rec['principal']) ? 'paid' : 'active';

        $upd = $conn->prepare("UPDATE debts_loans SET amount_paid=?, status=? WHERE id=? AND user_id=?");
        $upd->bind_param('dsii', $new_paid, $status, $debt_id, $user_id);
        echo json_encode(['success' => $upd->execute(), 'new_paid' => $new_paid, 'status' => $status]);
        exit;
    }

    /* ── DELETE record ── */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM debts_loans WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $id, $user_id);
        echo json_encode(['success' => $stmt->execute()]);
        exit;
    }

    /* ── MARK status ── */
    if ($action === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active','paid','overdue','written_off']) ? $_POST['status'] : null;
        if (!$id || !$status) { echo json_encode(['success' => false]); exit; }
        $stmt = $conn->prepare("UPDATE debts_loans SET status=? WHERE id=? AND user_id=?");
        $stmt->bind_param('sii', $status, $id, $user_id);
        echo json_encode(['success' => $stmt->execute()]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

/* ══════════════════════════════════════════════
   FETCH DATA
══════════════════════════════════════════════ */

// Auto-mark overdue on page load
$conn->query("
    UPDATE debts_loans
    SET status='overdue'
    WHERE user_id=$user_id
      AND status='active'
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
      AND amount_paid < principal
");

$filter_type   = $_GET['type']   ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

$where = "user_id = $user_id";
if (in_array($filter_type,   ['debt','loan']))                       $where .= " AND record_type='$filter_type'";
if (in_array($filter_status, ['active','paid','overdue','written_off'])) $where .= " AND status='$filter_status'";

$records = $conn->query("SELECT * FROM debts_loans WHERE $where ORDER BY
    FIELD(status,'overdue','active','paid','written_off'), due_date ASC, created_at DESC");

/* ── Summary stats ── */
$stats = $conn->query("
    SELECT
      SUM(IF(record_type='debt'  AND status NOT IN('paid','written_off'), principal - amount_paid, 0)) AS total_owe,
      SUM(IF(record_type='loan'  AND status NOT IN('paid','written_off'), principal - amount_paid, 0)) AS total_owed,
      COUNT(IF(status='overdue', 1, NULL))   AS overdue_count,
      COUNT(IF(status='active',  1, NULL))   AS active_count
    FROM debts_loans WHERE user_id = $user_id
")->fetch_assoc();

$total_owe    = (float)($stats['total_owe']    ?? 0);
$total_owed   = (float)($stats['total_owed']   ?? 0);
$overdue_cnt  = (int)  ($stats['overdue_count'] ?? 0);
$net_position = $total_owed - $total_owe;

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Debts & Loans – Fintrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

:root {
  --ink:       #0f1117;
  --surface:   #161b27;
  --card:      #1c2333;
  --border:    rgba(255,255,255,.07);
  --text:      #e2e8f0;
  --muted:     rgba(255,255,255,.4);
  --accent:    #6366f1;
  --accent-lt: rgba(99,102,241,.18);
  --green:     #10b981;
  --green-lt:  rgba(16,185,129,.15);
  --red:       #f43f5e;
  --red-lt:    rgba(244,63,94,.15);
  --amber:     #f59e0b;
  --amber-lt:  rgba(245,158,11,.15);
  --blue:      #0ea5e9;
  --sidebar-w: 240px;
  --radius:    10px;
}
html,body{height:100%;background:var(--ink);color:var(--text);font-family:'DM Sans',sans-serif;font-size:14px}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--ink);position:fixed;top:0;left:0;height:100vh;display:flex;flex-direction:column;z-index:50;border-right:1px solid var(--border)}
.sidebar-brand{padding:28px 20px 24px;border-bottom:1px solid var(--border)}
.brand-mark{display:flex;align-items:center;gap:10px}
.brand-icon{width:34px;height:34px;background:var(--accent);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff}
.brand-icon svg{width:18px;height:18px}
.brand-name{font-size:15px;font-weight:600;letter-spacing:-.3px}
.brand-tagline{font-size:11px;color:var(--muted);margin-top:1px}
.sidebar-nav{padding:16px 12px;flex:1}
.nav-section-label{font-size:10px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.25);padding:0 8px;margin:8px 0 6px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:7px;color:var(--muted);text-decoration:none;font-size:13.5px;font-weight:400;transition:background .15s,color .15s;margin-bottom:2px}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nav-item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.85)}
.nav-item.active{background:var(--accent-lt);color:#a5b4fc;font-weight:500}
.nav-item .badge{margin-left:auto;background:var(--accent-lt);color:#a5b4fc;font-size:10px;font-weight:600;padding:1px 6px;border-radius:10px;font-family:'DM Mono',monospace}
.sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
.user-row{display:flex;align-items:center;gap:10px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0}
.user-name{font-size:13px;font-weight:500}
.user-role{font-size:11px;color:var(--muted)}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh}
.main{flex:1;margin-left:var(--sidebar-w);display:flex;flex-direction:column;min-height:100vh}
.topbar{height:56px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:40}
.topbar-left .breadcrumb{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted)}
.breadcrumb-current{color:var(--text);font-weight:500}
.breadcrumb-sep{opacity:.4}
.topbar-right{display:flex;align-items:center;gap:10px}
.content{padding:28px 28px 60px;flex:1}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13.5px;font-weight:500;cursor:pointer;border:none;transition:opacity .15s,background .15s;font-family:inherit}
.btn svg{width:15px;height:15px}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{opacity:.9}
.btn-ghost{background:rgba(255,255,255,.06);color:var(--text)}
.btn-ghost:hover{background:rgba(255,255,255,.1)}
.btn-sm{padding:5px 12px;font-size:12.5px}
.btn-danger{background:var(--red-lt);color:var(--red)}
.btn-danger:hover{background:rgba(244,63,94,.25)}
.btn-green{background:var(--green-lt);color:var(--green)}
.btn-green:hover{background:rgba(16,185,129,.25)}

/* ── SUMMARY CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px}
.stat-label{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px}
.stat-value{font-size:26px;font-weight:600;font-family:'DM Mono',monospace;line-height:1}
.stat-sub{font-size:11.5px;color:var(--muted);margin-top:5px}
.stat-card.danger .stat-value{color:var(--red)}
.stat-card.success .stat-value{color:var(--green)}
.stat-card.warning .stat-value{color:var(--amber)}
.stat-card.neutral .stat-value{color:var(--accent)}

/* ── FILTER BAR ── */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:22px;flex-wrap:wrap}
.filter-group{display:flex;gap:2px;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:3px}
.filter-btn{padding:5px 14px;border-radius:6px;font-size:12.5px;font-weight:500;color:var(--muted);cursor:pointer;border:none;background:transparent;transition:background .15s,color .15s;font-family:inherit}
.filter-btn.active{background:var(--accent);color:#fff}
.filter-btn:hover:not(.active){background:rgba(255,255,255,.07);color:var(--text)}
.filter-spacer{flex:1}

/* ── TABLE CARD ── */
.table-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.table-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--border)}
.table-title{font-size:15px;font-weight:600}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);padding:12px 18px;text-align:left;border-bottom:1px solid var(--border);background:rgba(255,255,255,.015);white-space:nowrap}
td{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.04);font-size:13.5px;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.02)}

/* ── BADGES ── */
.badge-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
.badge-debt{background:var(--red-lt);color:var(--red)}
.badge-loan{background:var(--green-lt);color:var(--green)}
.badge-active{background:var(--accent-lt);color:#a5b4fc}
.badge-paid{background:var(--green-lt);color:var(--green)}
.badge-overdue{background:var(--red-lt);color:var(--red)}
.badge-written_off{background:rgba(255,255,255,.06);color:var(--muted)}

/* ── PROGRESS BAR ── */
.progress-wrap{display:flex;align-items:center;gap:8px}
.progress-bar{flex:1;height:5px;background:rgba(255,255,255,.08);border-radius:3px;overflow:hidden;min-width:60px}
.progress-fill{height:100%;border-radius:3px;transition:width .4s}
.progress-fill.green{background:var(--green)}
.progress-fill.amber{background:var(--amber)}
.progress-fill.red{background:var(--red)}
.progress-pct{font-size:11.5px;font-family:'DM Mono',monospace;color:var(--muted);min-width:32px;text-align:right}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
.empty-icon{font-size:40px;margin-bottom:12px}
.empty-title{font-size:15px;font-weight:500;color:var(--text);margin-bottom:6px}

/* ── MODALS ── */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;align-items:center;justify-content:center;padding:20px}
.modal-backdrop.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:14px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:22px 24px 0}
.modal-title{font-size:16px;font-weight:600}
.modal-close{background:none;border:none;color:var(--muted);cursor:pointer;padding:4px;border-radius:6px;transition:color .15s}
.modal-close:hover{color:var(--text)}
.modal-close svg{width:20px;height:20px}
.modal-body{padding:20px 24px 24px}

/* ── FORM ── */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:16px}
.form-group:last-child{margin-bottom:0}
label{display:block;font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
input,select,textarea{width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:var(--text);padding:10px 12px;font-size:13.5px;font-family:inherit;outline:none;transition:border-color .15s}
input:focus,select:focus,textarea:focus{border-color:var(--accent)}
select option{background:var(--card)}
textarea{resize:vertical;min-height:70px}
.toggle-row{display:flex;gap:8px}
.toggle-btn{flex:1;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:transparent;color:var(--muted);cursor:pointer;font-size:13.5px;font-family:inherit;font-weight:500;transition:all .15s;text-align:center}
.toggle-btn.sel-debt{background:var(--red-lt);border-color:var(--red);color:var(--red)}
.toggle-btn.sel-loan{background:var(--green-lt);border-color:var(--green);color:var(--green)}

/* ── ACTIONS ── */
.action-btns{display:flex;gap:6px}

/* ── AMOUNT ── */
.mono{font-family:'DM Mono',monospace}

/* ── TOAST ── */
#toast{position:fixed;bottom:24px;right:24px;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px 18px;font-size:13.5px;z-index:999;transition:opacity .3s;pointer-events:none;opacity:0;display:flex;align-items:center;gap:8px}
#toast.show{opacity:1}
#toast.success{border-left:3px solid var(--green);color:var(--green)}
#toast.error{border-left:3px solid var(--red);color:var(--red)}

@media(max-width:900px){.stats-row{grid-template-columns:1fr 1fr}}
@media(max-width:768px){.sidebar{display:none}.main{margin-left:0}.form-row{grid-template-columns:1fr}}
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
        <div>
          <div class="brand-name">Fintrack</div>
          <div class="brand-tagline">Personal Finance</div>
        </div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Overview</div>
      <a href="dashboard.php" class="nav-item">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
        Dashboard
      </a>

      <div class="nav-section-label" style="margin-top:16px">Money</div>
      <a href="transactions.php" class="nav-item">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
        Transactions
      </a>
      <a href="recurring_transaction.php" class="nav-item">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Recurring
      </a>
      <a href="budget.php" class="nav-item">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        Budget
      </a>
      <a href="savings_goals.php" class="nav-item">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        Savings Goals
      </a>
      <a href="debts_loans.php" class="nav-item active">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
        Debts &amp; Loans
        <?php if ($overdue_cnt > 0): ?>
        <span class="badge" style="background:var(--red-lt);color:var(--red)"><?= $overdue_cnt ?></span>
        <?php endif; ?>
      </a>

      <div class="nav-section-label" style="margin-top:16px">Account</div>
      <a href="settings.php" class="nav-item">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Settings
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="user-row">
        <div class="user-avatar">U</div>
        <div>
          <div class="user-name">My Account</div>
          <div class="user-role">Personal plan</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div class="topbar-left">
        <div class="breadcrumb">
          <span>Home</span>
          <span class="breadcrumb-sep">›</span>
          <span class="breadcrumb-current">Debts &amp; Loans</span>
        </div>
      </div>
      <div class="topbar-right">
        <span style="font-size:12.5px;color:var(--muted)"><?= date('D, d M Y') ?></span>
        <button class="btn btn-primary" onclick="openAddModal()">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Add Record
        </button>
      </div>
    </div>

    <div class="content">

      <!-- STATS -->
      <div class="stats-row">
        <div class="stat-card danger">
          <div class="stat-label">I Owe</div>
          <div class="stat-value">KSh <?= number_format($total_owe, 0) ?></div>
          <div class="stat-sub">Total outstanding debts</div>
        </div>
        <div class="stat-card success">
          <div class="stat-label">Owed to Me</div>
          <div class="stat-value">KSh <?= number_format($total_owed, 0) ?></div>
          <div class="stat-sub">Total outstanding loans</div>
        </div>
        <div class="stat-card <?= $net_position >= 0 ? 'success' : 'danger' ?>">
          <div class="stat-label">Net Position</div>
          <div class="stat-value">KSh <?= number_format(abs($net_position), 0) ?></div>
          <div class="stat-sub"><?= $net_position >= 0 ? 'Net creditor ↑' : 'Net debtor ↓' ?></div>
        </div>
        <div class="stat-card <?= $overdue_cnt > 0 ? 'warning' : 'neutral' ?>">
          <div class="stat-label">Overdue</div>
          <div class="stat-value"><?= $overdue_cnt ?></div>
          <div class="stat-sub"><?= $overdue_cnt > 0 ? 'Need attention' : 'All on track' ?></div>
        </div>
      </div>

      <!-- FILTERS -->
      <div class="filter-bar">
        <div class="filter-group">
          <button class="filter-btn <?= $filter_type==='all'?'active':'' ?>" onclick="applyFilter('type','all')">All</button>
          <button class="filter-btn <?= $filter_type==='debt'?'active':'' ?>" onclick="applyFilter('type','debt')">I Owe</button>
          <button class="filter-btn <?= $filter_type==='loan'?'active':'' ?>" onclick="applyFilter('type','loan')">Owed to Me</button>
        </div>
        <div class="filter-group">
          <button class="filter-btn <?= $filter_status==='all'?'active':'' ?>"         onclick="applyFilter('status','all')">All Status</button>
          <button class="filter-btn <?= $filter_status==='active'?'active':'' ?>"       onclick="applyFilter('status','active')">Active</button>
          <button class="filter-btn <?= $filter_status==='overdue'?'active':'' ?>"      onclick="applyFilter('status','overdue')">Overdue</button>
          <button class="filter-btn <?= $filter_status==='paid'?'active':'' ?>"         onclick="applyFilter('status','paid')">Paid</button>
          <button class="filter-btn <?= $filter_status==='written_off'?'active':'' ?>"  onclick="applyFilter('status','written_off')">Written Off</button>
        </div>
        <div class="filter-spacer"></div>
        <button class="btn btn-ghost btn-sm" onclick="openAddModal()">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          New
        </button>
      </div>

      <!-- TABLE -->
      <div class="table-card">
        <div class="table-header">
          <span class="table-title">Records</span>
          <span style="font-size:12px;color:var(--muted)"><?= $records ? $records->num_rows : 0 ?> records</span>
        </div>
        <div class="table-wrap">
          <?php if (!$records || $records->num_rows === 0): ?>
          <div class="empty-state">
            <div class="empty-icon">📋</div>
            <div class="empty-title">No records yet</div>
            <p style="font-size:13px;margin-top:6px">Add your first debt or loan to start tracking.</p>
            <br>
            <button class="btn btn-primary" onclick="openAddModal()">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Add Record
            </button>
          </div>
          <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Type</th>
                <th>Title</th>
                <th>Person / Entity</th>
                <th>Principal</th>
                <th>Remaining</th>
                <th>Progress</th>
                <th>Due Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php while ($r = $records->fetch_assoc()):
              $remaining = max(0, $r['principal'] - $r['amount_paid']);
              $pct       = $r['principal'] > 0 ? min(100, round($r['amount_paid'] / $r['principal'] * 100)) : 0;
              $bar_class = $pct >= 100 ? 'green' : ($pct >= 60 ? 'amber' : 'red');
              $overdue   = $r['due_date'] && $r['due_date'] < date('Y-m-d') && $r['status'] === 'overdue';
            ?>
            <tr>
              <td>
                <span class="badge-pill badge-<?= $r['record_type'] ?>">
                  <?= $r['record_type'] === 'debt' ? '↑ I Owe' : '↓ Owed Me' ?>
                </span>
              </td>
              <td>
                <span style="font-weight:500"><?= htmlspecialchars($r['title']) ?></span>
                <?php if ($r['interest_rate']): ?>
                <span style="font-size:11px;color:var(--muted);margin-left:4px"><?= $r['interest_rate'] ?>% p.a.</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted)"><?= htmlspecialchars($r['counterparty']) ?></td>
              <td class="mono">KSh <?= number_format($r['principal'], 0) ?></td>
              <td class="mono" style="color:<?= $remaining > 0 ? 'var(--red)' : 'var(--green)' ?>">
                KSh <?= number_format($remaining, 0) ?>
              </td>
              <td>
                <div class="progress-wrap">
                  <div class="progress-bar"><div class="progress-fill <?= $bar_class ?>" style="width:<?= $pct ?>%"></div></div>
                  <span class="progress-pct"><?= $pct ?>%</span>
                </div>
              </td>
              <td style="color:<?= $overdue ? 'var(--red)' : 'var(--muted)' ?>">
                <?= $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '—' ?>
                <?php if ($overdue): ?><br><span style="font-size:11px;font-weight:600">OVERDUE</span><?php endif; ?>
              </td>
              <td><span class="badge-pill badge-<?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
              <td>
                <div class="action-btns">
                  <?php if ($r['status'] !== 'paid' && $r['status'] !== 'written_off'): ?>
                  <button class="btn btn-sm btn-green" onclick="openPayModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['title'])) ?>', <?= $remaining ?>)">Pay</button>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-ghost" onclick="openEditModal(<?= htmlspecialchars(json_encode($r)) ?>)">Edit</button>
                  <button class="btn btn-sm btn-danger" onclick="deleteRecord(<?= $r['id'] ?>, this)">Del</button>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- ═══════════════════════════════════════════
     ADD MODAL
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title">Add Debt / Loan</span>
      <button class="modal-close" onclick="closeModal('addModal')">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <!-- Type selector -->
      <div class="form-group">
        <label>Record Type</label>
        <div class="toggle-row">
          <button type="button" class="toggle-btn sel-debt" id="btn-debt" onclick="selectType('debt')">📤 I Owe (Debt)</button>
          <button type="button" class="toggle-btn" id="btn-loan" onclick="selectType('loan')">📥 Owed to Me (Loan)</button>
        </div>
        <input type="hidden" id="add_record_type" value="debt">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Title</label>
          <input type="text" id="add_title" placeholder="e.g. Bank Loan, School Fees" maxlength="255">
        </div>
        <div class="form-group">
          <label id="counterparty-label">Lender Name</label>
          <input type="text" id="add_counterparty" placeholder="Who lent you money?" maxlength="255">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Principal Amount (KSh)</label>
          <input type="number" id="add_principal" placeholder="0.00" min="0" step="0.01">
        </div>
        <div class="form-group">
          <label>Already Paid (KSh)</label>
          <input type="number" id="add_amount_paid" placeholder="0.00" min="0" step="0.01" value="0">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Interest Rate (% p.a.) <span style="font-weight:400;text-transform:none">optional</span></label>
          <input type="number" id="add_interest_rate" placeholder="e.g. 12" min="0" max="999" step="0.01">
        </div>
        <div class="form-group">
          <label>Due Date <span style="font-weight:400;text-transform:none">optional</span></label>
          <input type="date" id="add_due_date">
        </div>
      </div>
      <div class="form-group">
        <label>Notes <span style="font-weight:400;text-transform:none">optional</span></label>
        <textarea id="add_notes" placeholder="Any additional details…"></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px">
        <button class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        <button class="btn btn-primary" id="addSaveBtn" onclick="saveRecord()">Save Record</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     PAY MODAL
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="payModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-head">
      <span class="modal-title">Log Payment</span>
      <button class="modal-close" onclick="closeModal('payModal')">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pay_debt_id">
      <div class="form-group">
        <label>Record</label>
        <input type="text" id="pay_title" readonly style="opacity:.7">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Amount (KSh)</label>
          <input type="number" id="pay_amount" placeholder="0.00" min="0.01" step="0.01">
        </div>
        <div class="form-group">
          <label>Date</label>
          <input type="date" id="pay_date">
        </div>
      </div>
      <div class="form-group">
        <label>Note <span style="font-weight:400;text-transform:none">optional</span></label>
        <input type="text" id="pay_note" placeholder="e.g. Bank transfer" maxlength="255">
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px">
        <button class="btn btn-ghost" onclick="closeModal('payModal')">Cancel</button>
        <button class="btn btn-green" id="paySaveBtn" onclick="logPayment()">Record Payment</button>
      </div>
    </div>
  </div>
</div>

<!-- TOAST -->
<div id="toast"></div>

<script>
const CSRF = '<?= $csrf ?>';

/* ── Helpers ── */
function toast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show ' + type;
  setTimeout(() => t.className = '', 3000);
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function openModal(id)  { document.getElementById(id).classList.add('open'); }

/* ── Filter ── */
function applyFilter(key, val) {
  const url = new URL(location.href);
  url.searchParams.set(key, val);
  location.href = url.toString();
}

/* ── ADD ── */
let _selectedType = 'debt';
function selectType(t) {
  _selectedType = t;
  document.getElementById('add_record_type').value = t;
  document.getElementById('btn-debt').className = 'toggle-btn' + (t==='debt' ? ' sel-debt' : '');
  document.getElementById('btn-loan').className = 'toggle-btn' + (t==='loan' ? ' sel-loan' : '');
  document.getElementById('counterparty-label').textContent = t === 'debt' ? 'Lender Name' : 'Borrower Name';
  document.getElementById('add_counterparty').placeholder = t === 'debt' ? 'Who lent you money?' : 'Who borrowed from you?';
}

function openAddModal() {
  // reset form
  ['add_title','add_counterparty','add_principal','add_amount_paid','add_interest_rate','add_due_date','add_notes']
    .forEach(id => { const el = document.getElementById(id); el.value = el.defaultValue || ''; });
  document.getElementById('add_amount_paid').value = '0';
  selectType('debt');
  openModal('addModal');
}

function openEditModal(rec) {
  selectType(rec.record_type);
  document.getElementById('add_title').value         = rec.title;
  document.getElementById('add_counterparty').value  = rec.counterparty;
  document.getElementById('add_principal').value     = rec.principal;
  document.getElementById('add_amount_paid').value   = rec.amount_paid;
  document.getElementById('add_interest_rate').value = rec.interest_rate ?? '';
  document.getElementById('add_due_date').value      = rec.due_date ?? '';
  document.getElementById('add_notes').value         = rec.notes ?? '';
  // repurpose save btn to update
  const btn = document.getElementById('addSaveBtn');
  btn.textContent = 'Update Record';
  btn.onclick = () => saveRecord(rec.id);
  openModal('addModal');
}

async function saveRecord(existingId = null) {
  const title        = document.getElementById('add_title').value.trim();
  const counterparty = document.getElementById('add_counterparty').value.trim();
  const principal    = parseFloat(document.getElementById('add_principal').value) || 0;
  if (!title || !counterparty || principal <= 0) { toast('Please fill all required fields.', 'error'); return; }

  const fd = new FormData();
  fd.append('csrf_token',    CSRF);
  fd.append('action',        existingId ? 'update' : 'add');
  fd.append('record_type',   _selectedType);
  fd.append('title',         title);
  fd.append('counterparty',  counterparty);
  fd.append('principal',     principal);
  fd.append('amount_paid',   document.getElementById('add_amount_paid').value  || 0);
  fd.append('interest_rate', document.getElementById('add_interest_rate').value);
  fd.append('due_date',      document.getElementById('add_due_date').value);
  fd.append('notes',         document.getElementById('add_notes').value);
  if (existingId) fd.append('id', existingId);

  const btn = document.getElementById('addSaveBtn');
  btn.disabled = true; btn.textContent = 'Saving…';

  const res = await fetch('debts_loans.php', { method: 'POST', body: fd });
  const data = await res.json();
  btn.disabled = false; btn.textContent = existingId ? 'Update Record' : 'Save Record';

  if (data.success) { toast('Record saved!'); closeModal('addModal'); location.reload(); }
  else { toast(data.message || 'Something went wrong.', 'error'); }
}

/* ── PAY ── */
function openPayModal(id, title, remaining) {
  document.getElementById('pay_debt_id').value = id;
  document.getElementById('pay_title').value   = title;
  document.getElementById('pay_amount').value  = '';
  document.getElementById('pay_date').value    = new Date().toISOString().split('T')[0];
  document.getElementById('pay_note').value    = '';
  openModal('payModal');
}

async function logPayment() {
  const debt_id = document.getElementById('pay_debt_id').value;
  const amount  = parseFloat(document.getElementById('pay_amount').value) || 0;
  if (amount <= 0) { toast('Enter a valid amount.', 'error'); return; }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action',     'pay');
  fd.append('debt_id',    debt_id);
  fd.append('amount',     amount);
  fd.append('paid_on',    document.getElementById('pay_date').value);
  fd.append('note',       document.getElementById('pay_note').value);

  const btn = document.getElementById('paySaveBtn');
  btn.disabled = true; btn.textContent = 'Saving…';

  const res  = await fetch('debts_loans.php', { method: 'POST', body: fd });
  const data = await res.json();
  btn.disabled = false; btn.textContent = 'Record Payment';

  if (data.success) { toast('Payment recorded!'); closeModal('payModal'); location.reload(); }
  else { toast(data.message || 'Error saving.', 'error'); }
}

/* ── DELETE ── */
async function deleteRecord(id, el) {
  if (!confirm('Delete this record? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'delete');
  fd.append('id', id);
  const res  = await fetch('debts_loans.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.success) { toast('Record deleted.'); el.closest('tr').remove(); }
  else toast('Could not delete.', 'error');
}

/* ── Close on backdrop click ── */
document.querySelectorAll('.modal-backdrop').forEach(bd => {
  bd.addEventListener('click', e => { if (e.target === bd) bd.classList.remove('open'); });
});

/* ── Reset add modal btn when opened fresh ── */
function openAddModal() {
  ['add_title','add_counterparty','add_interest_rate','add_due_date','add_notes']
    .forEach(id => document.getElementById(id).value = '');
  document.getElementById('add_principal').value    = '';
  document.getElementById('add_amount_paid').value  = '0';
  selectType('debt');
  const btn = document.getElementById('addSaveBtn');
  btn.textContent = 'Save Record';
  btn.onclick = () => saveRecord();
  openModal('addModal');
}
</script>
</body>
</html>