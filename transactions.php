<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* ── FILTERS ── */
$search   = trim($_GET['search']   ?? '');
$type     = trim($_GET['type']     ?? '');
$category = trim($_GET['category'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$sort      = in_array($_GET['sort'] ?? '', ['asc','desc']) ? $_GET['sort'] : 'desc';
$page_num  = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 15;
$offset    = ($page_num - 1) * $per_page;

/* ── BUILD WHERE CLAUSE ── */
$where = "WHERE user_id=? AND is_deleted_by_user=0";
$params = [$user_id];
$types  = 'i';

if ($search !== '') {
    $where .= " AND (category LIKE ? OR description LIKE ?)";
    $like = '%' . $conn->real_escape_string($search) . '%';
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}
if ($type !== '') {
    $where .= " AND type=?";
    $params[] = $type; $types .= 's';
}
if ($category !== '') {
    $where .= " AND category=?";
    $params[] = $category; $types .= 's';
}
if ($date_from !== '') {
    $where .= " AND COALESCE(NULLIF(date,'0000-00-00'), DATE(created_at)) >= ?";
    $params[] = $date_from; $types .= 's';
}
if ($date_to !== '') {
    $where .= " AND COALESCE(NULLIF(date,'0000-00-00'), DATE(created_at)) <= ?";
    $params[] = $date_to; $types .= 's';
}

/* ── TOTAL COUNT ── */
$count_stmt = $conn->prepare("SELECT COUNT(*) FROM transactions $where");
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_row()[0];
$total_pages = max(1, ceil($total_rows / $per_page));
$count_stmt->close();

/* ── FETCH TRANSACTIONS ── */
$order = "ORDER BY created_at $sort, transaction_id $sort";
$stmt = $conn->prepare("SELECT * FROM transactions $where $order LIMIT ? OFFSET ?");
$params[] = $per_page; $params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();
$stmt->close();

/* ── STATS ── */
$stats = $conn->query("
    SELECT
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
        COUNT(*) AS total_count
    FROM transactions
    WHERE user_id=$user_id AND is_deleted_by_user=0
")->fetch_assoc();

$net = ($stats['total_income'] ?? 0) - ($stats['total_expense'] ?? 0);

/* ── CATEGORIES (for filter dropdown) ── */
$cats = $conn->query("SELECT DISTINCT category FROM transactions WHERE user_id=$user_id AND is_deleted_by_user=0 ORDER BY category");

/* ── EXPORT CSV ── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Type','Category','Amount (KES)','Date','Description']);
    $exp_stmt = $conn->prepare("SELECT * FROM transactions $where ORDER BY created_at DESC");
    // Remove pagination params
    $exp_types = substr($types, 0, -2);
    $exp_params = array_slice($params, 0, -2);
    $exp_stmt->bind_param($exp_types, ...$exp_params);
    $exp_stmt->execute();
    $exp_res = $exp_stmt->get_result();
    while ($row = $exp_res->fetch_assoc()) {
        $display_date = ($row['date'] === '0000-00-00' || empty($row['date']))
            ? date('Y-m-d', strtotime($row['created_at']))
            : $row['date'];
        fputcsv($out, [
            $row['transaction_id'],
            $row['type'],
            $row['category'],
            $row['amount'],
            $display_date,
            $row['description']
        ]);
    }
    fclose($out);
    exit;
}

/* ── HELPERS ── */
function display_date($row) {
    $d = $row['date'] ?? '';
    if (empty($d) || $d === '0000-00-00') {
        return date('d M Y', strtotime($row['created_at']));
    }
    return date('d M Y', strtotime($d));
}
function qs($extra=[]) {
    $p = array_merge($_GET, $extra);
    unset($p['export']);
    return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transactions — Finance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
    --ink:#0d0f12;--ink-2:#3a3f4a;--ink-3:#7a8394;
    --line:#e8eaed;--line-2:#f1f3f5;
    --surface:#ffffff;--page:#f6f7f9;
    --accent:#6366f1;--accent-lt:#eef2ff;
    --green:#10b981;--green-lt:#ecfdf5;
    --red:#f43f5e;--red-lt:#fff1f2;
    --amber:#f59e0b;--amber-lt:#fffbeb;
    --sidebar-w:240px;--r:10px;--r-lg:14px;
    --shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
    --shadow-md:0 4px 12px rgba(0,0,0,.08),0 2px 4px rgba(0,0,0,.04);
}
body{font-family:'DM Sans',sans-serif;background:var(--page);color:var(--ink);font-size:14px;line-height:1.5;}
.layout{display:flex;min-height:100vh;}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--ink);position:fixed;top:0;left:0;height:100vh;display:flex;flex-direction:column;z-index:50;overflow:hidden;transition:transform .25s ease;}
.sidebar-brand{padding:28px 20px 24px;border-bottom:1px solid rgba(255,255,255,.07);}
.brand-mark{display:flex;align-items:center;gap:10px;}
.brand-icon{width:34px;height:34px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.brand-icon svg{width:18px;height:18px;color:#fff;}
.brand-name{font-size:15px;font-weight:600;color:#fff;letter-spacing:-.2px;}
.brand-tagline{font-size:11px;color:rgba(255,255,255,.4);margin-top:1px;}
.sidebar-nav{padding:16px 12px;flex:1;overflow-y:auto;}
.nav-section-label{font-size:10px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.25);padding:0 8px;margin:8px 0 6px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:7px;color:rgba(255,255,255,.5);text-decoration:none;font-size:13.5px;font-weight:400;transition:background .15s,color .15s;margin-bottom:2px;}
.nav-item svg{width:16px;height:16px;flex-shrink:0;}
.nav-item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.85);}
.nav-item.active{background:rgba(99,102,241,.2);color:#a5b4fc;font-weight:500;}
.sidebar-footer{padding:16px 12px;border-top:1px solid rgba(255,255,255,.06);}
.user-row{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;cursor:pointer;}
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#fff;flex-shrink:0;}
.user-name{font-size:12.5px;color:rgba(255,255,255,.7);font-weight:500;}

/* ── HAMBURGER ── */
.hamburger{display:none;position:fixed;top:16px;left:16px;z-index:100;background:var(--ink);border:none;color:#fff;width:40px;height:40px;border-radius:8px;cursor:pointer;align-items:center;justify-content:center;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40;}

/* ── MAIN ── */
.main{flex:1;margin-left:var(--sidebar-w);padding:32px 36px;display:flex;flex-direction:column;gap:24px;min-width:0;}

/* ── TOPBAR ── */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;}
.page-title{font-size:22px;font-weight:600;letter-spacing:-.4px;}
.page-sub{font-size:13px;color:var(--ink-3);margin-top:2px;}
.topbar-actions{display:flex;gap:10px;align-items:center;flex-shrink:0;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--r);font-size:13px;font-weight:500;cursor:pointer;border:none;transition:all .15s;text-decoration:none;white-space:nowrap;}
.btn svg{width:14px;height:14px;flex-shrink:0;}
.btn-primary{background:var(--accent);color:#fff;}
.btn-primary:hover{background:#4f46e5;}
.btn-ghost{background:var(--surface);color:var(--ink-2);border:1px solid var(--line);}
.btn-ghost:hover{background:var(--line-2);}
.btn-danger{background:var(--red-lt);color:var(--red);border:1px solid #fecdd3;}
.btn-danger:hover{background:#fecdd3;}
.btn-sm{padding:5px 10px;font-size:12px;}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.stat-card{background:var(--surface);border-radius:var(--r-lg);padding:18px 20px;border:1px solid var(--line);box-shadow:var(--shadow);display:flex;align-items:center;gap:14px;}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-icon svg{width:18px;height:18px;}
.stat-icon.accent{background:var(--accent-lt);color:var(--accent);}
.stat-icon.green{background:var(--green-lt);color:var(--green);}
.stat-icon.red{background:var(--red-lt);color:var(--red);}
.stat-icon.amber{background:var(--amber-lt);color:var(--amber);}
.stat-label{font-size:11.5px;color:var(--ink-3);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.stat-value{font-size:19px;font-weight:600;letter-spacing:-.5px;font-family:'DM Mono',monospace;margin-top:2px;}
.stat-value.positive{color:var(--green);}
.stat-value.negative{color:var(--red);}

/* ── FILTER BAR ── */
.filter-bar{background:var(--surface);border-radius:var(--r-lg);padding:16px 20px;border:1px solid var(--line);box-shadow:var(--shadow);}
.filter-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.filter-group{display:flex;flex-direction:column;gap:4px;min-width:0;}
.filter-group label{font-size:11px;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.5px;}
.filter-input{height:36px;padding:0 10px;border-radius:8px;border:1px solid var(--line);background:var(--page);color:var(--ink);font-size:13px;font-family:inherit;outline:none;transition:border-color .15s;}
.filter-input:focus{border-color:var(--accent);background:#fff;}
.search-wrap{position:relative;flex:1;min-width:180px;}
.search-wrap svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--ink-3);pointer-events:none;}
.search-input{padding-left:32px;width:100%;}
.active-filters{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;}
.filter-tag{display:inline-flex;align-items:center;gap:5px;background:var(--accent-lt);color:var(--accent);font-size:11.5px;font-weight:500;padding:3px 8px;border-radius:20px;}
.filter-tag a{color:inherit;text-decoration:none;display:flex;align-items:center;font-size:12px;margin-left:2px;}

/* ── TABLE ── */
.table-card{background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line);box-shadow:var(--shadow);overflow:hidden;}
.table-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line);}
.table-title{font-size:14px;font-weight:600;}
.table-meta{font-size:12px;color:var(--ink-3);}
.export-btns{display:flex;gap:8px;}
table{width:100%;border-collapse:collapse;}
thead{background:var(--line-2);}
th{padding:10px 16px;text-align:left;font-size:11.5px;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
th a:hover{color:var(--ink);}
td{padding:12px 16px;font-size:13.5px;border-bottom:1px solid var(--line-2);}
tr:last-child td{border-bottom:none;}
tbody tr{transition:background .1s;}
tbody tr:hover{background:var(--page);}

.tx-category{display:flex;align-items:center;gap:10px;}
.tx-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.tx-name{font-weight:500;font-size:13.5px;}
.tx-desc{font-size:11.5px;color:var(--ink-3);margin-top:1px;}
.type-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600;text-transform:capitalize;}
.type-badge.income{background:var(--green-lt);color:var(--green);}
.type-badge.expense{background:var(--red-lt);color:var(--red);}
.type-badge.other{background:var(--amber-lt);color:var(--amber);}
.amount{font-family:'DM Mono',monospace;font-size:13.5px;font-weight:500;white-space:nowrap;}
.amount.income{color:var(--green);}
.amount.expense{color:var(--red);}
.tx-date-str{font-size:13px;color:var(--ink-2);}
.action-btns{display:flex;gap:6px;}
.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:none;cursor:pointer;transition:background .15s;}
.icon-btn svg{width:13px;height:13px;}
.icon-btn.edit{background:var(--accent-lt);color:var(--accent);}
.icon-btn.edit:hover{background:#ddd6fe;color:#4f46e5;}
.icon-btn.del{background:var(--red-lt);color:var(--red);}
.icon-btn.del:hover{background:#fecdd3;}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:56px 20px;}
.empty-icon{font-size:40px;margin-bottom:12px;}
.empty-title{font-size:16px;font-weight:600;margin-bottom:6px;}
.empty-sub{font-size:13px;color:var(--ink-3);}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--line);}
.pagination-info{font-size:12.5px;color:var(--ink-3);}
.pagination-btns{display:flex;gap:4px;}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:500;text-decoration:none;color:var(--ink-2);border:1px solid var(--line);background:var(--surface);transition:all .15s;}
.page-btn:hover{background:var(--page);color:var(--ink);}
.page-btn.active{background:var(--accent);color:#fff;border-color:var(--accent);}
.page-btn.disabled{opacity:.4;pointer-events:none;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--surface);border-radius:var(--r-lg);width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;animation:slideUp .2s ease;}
@keyframes slideUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--line);}
.modal-title{font-size:16px;font-weight:600;}
.modal-close{background:none;border:none;cursor:pointer;color:var(--ink-3);display:flex;padding:4px;}
.modal-close:hover{color:var(--ink);}
.modal-close svg{width:18px;height:18px;}
.modal-body{padding:24px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.form-group:last-child{margin-bottom:0;}
.form-label{font-size:12px;font-weight:600;color:var(--ink-2);text-transform:uppercase;letter-spacing:.4px;}
.form-control{height:38px;padding:0 12px;border-radius:8px;border:1px solid var(--line);font-size:13.5px;font-family:inherit;color:var(--ink);background:var(--page);outline:none;transition:border-color .15s;}
.form-control:focus{border-color:var(--accent);background:#fff;}
textarea.form-control{height:auto;padding:10px 12px;resize:vertical;}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding:16px 24px;border-top:1px solid var(--line);}
.del-confirm{padding:24px;text-align:center;}
.del-confirm-icon{font-size:36px;margin-bottom:12px;}
.del-confirm-title{font-size:16px;font-weight:600;margin-bottom:6px;}
.del-confirm-sub{font-size:13px;color:var(--ink-3);margin-bottom:20px;}

/* ── TOAST ── */
.toast{position:fixed;bottom:24px;right:24px;background:var(--ink);color:#fff;padding:12px 18px;border-radius:var(--r);font-size:13px;font-weight:500;box-shadow:var(--shadow-md);z-index:500;transform:translateY(8px);opacity:0;transition:all .25s;pointer-events:none;}
.toast.show{opacity:1;transform:translateY(0);}
.toast.success{background:#065f46;border-left:3px solid var(--green);}
.toast.error{background:#9f1239;border-left:3px solid var(--red);}

/* ── RESPONSIVE ── */
@media(max-width:900px){
    .hamburger{display:flex;}
    .sidebar{transform:translateX(-100%);}
    .sidebar.open{transform:translateX(0);}
    .sidebar-overlay.open{display:block;}
    .main{margin-left:0;padding:20px 16px;padding-top:64px;}
    .stats-row{grid-template-columns:repeat(2,1fr);}
    .filter-form{flex-direction:column;}
    .filter-group{width:100%;}
    .search-wrap{width:100%;}
    th:nth-child(4),td:nth-child(4){display:none;}
}
@media(max-width:600px){
    .stats-row{grid-template-columns:1fr 1fr;}
    .stat-value{font-size:15px;}
    th:nth-child(2),td:nth-child(2){display:none;}
    .topbar-actions .btn span{display:none;}
    .form-row{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- HAMBURGER -->
<button class="hamburger" onclick="toggleSidebar()" aria-label="Toggle menu">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
<div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-mark">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M12 6v6l4 2"/></svg>
      </div>
      <div>
        <div class="brand-name">Finance</div>
        <div class="brand-tagline">Personal tracker</div>
      </div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Menu</div>
    <a href="dashboard.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <a href="transactions.php" class="nav-item active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
      Transactions
    </a>
    <a href="budget.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      Budget
    </a>
    <a href="savings_goals.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 9c0 4-4 4-4 4H7v5H3V9a6 6 0 0 1 12 0h2z"/><circle cx="21" cy="14" r="3"/></svg>
      Savings Goals
    </a>
    <a href="recurring_transaction.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
      Recurring
    </a>
    <a href="debts.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
      Debts & Loans
    </a>
    <div class="nav-section-label" style="margin-top:16px;">Account</div>
    <a href="settings.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Settings
    </a>
    <a href="logout.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Logout
    </a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-row">
      <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['name'] ?? $_SESSION['user_id'], 0, 1)); ?></div>
      <div>
        <div class="user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></div>
        <div style="font-size:11px;color:rgba(255,255,255,.3);"><?php echo htmlspecialchars($_SESSION['role'] ?? 'user'); ?></div>
      </div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div>
      <div class="page-title">Transactions</div>
      <div class="page-sub">
        <?php echo number_format($total_rows); ?> record<?php echo $total_rows != 1 ? 's' : ''; ?>
        <?php if ($search || $type || $category || $date_from || $date_to) echo ' matching filters'; ?>
      </div>
    </div>
    <div class="topbar-actions">
      <a href="<?php echo qs(['export'=>'csv']); ?>" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        <span>Export CSV</span>
      </a>
      <button class="btn btn-primary" onclick="openAddModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
        <span>Add Transaction</span>
      </button>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon accent"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 7H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3M13 3h8m0 0v8m0-8L11 13"/></svg></div>
      <div><div class="stat-label">Total Records</div><div class="stat-value"><?php echo number_format($stats['total_count']); ?></div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div>
      <div><div class="stat-label">Total Income</div><div class="stat-value positive">KES <?php echo number_format($stats['total_income'] ?? 0); ?></div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg></div>
      <div><div class="stat-label">Total Expenses</div><div class="stat-value negative">KES <?php echo number_format($stats['total_expense'] ?? 0); ?></div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon <?php echo $net >= 0 ? 'green' : 'red'; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
      <div><div class="stat-label">Net Balance</div><div class="stat-value <?php echo $net >= 0 ? 'positive' : 'negative'; ?>">KES <?php echo number_format(abs($net)); ?></div></div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar">
    <form method="GET" class="filter-form" id="filterForm">
      <div class="filter-group search-wrap">
        <label>Search</label>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" name="search" class="filter-input search-input" placeholder="Category or description…" value="<?php echo htmlspecialchars($search); ?>">
      </div>
      <div class="filter-group">
        <label>Type</label>
        <select name="type" class="filter-input" onchange="this.form.submit()">
          <option value="">All Types</option>
          <option value="income"  <?php if($type==='income')  echo 'selected'; ?>>Income</option>
          <option value="expense" <?php if($type==='expense') echo 'selected'; ?>>Expense</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Category</label>
        <select name="category" class="filter-input" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php while($cat = $cats->fetch_assoc()): if(empty($cat['category'])) continue; ?>
            <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php if($category===$cat['category']) echo 'selected'; ?>>
              <?php echo htmlspecialchars($cat['category']); ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>From</label>
        <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>" onchange="this.form.submit()">
      </div>
      <div class="filter-group">
        <label>To</label>
        <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>" onchange="this.form.submit()">
      </div>
      <div class="filter-group">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary" style="height:36px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          Search
        </button>
      </div>
      <?php if ($search || $type || $category || $date_from || $date_to): ?>
      <div class="filter-group">
        <label>&nbsp;</label>
        <a href="transactions.php" class="btn btn-ghost" style="height:36px;">Clear</a>
      </div>
      <?php endif; ?>
      <input type="hidden" name="sort" value="<?php echo $sort; ?>">
    </form>
    <?php
    $active_filters = [];
    if ($search)    $active_filters[] = 'Search: "'.htmlspecialchars($search).'"';
    if ($type)      $active_filters[] = 'Type: '.ucfirst($type);
    if ($category)  $active_filters[] = 'Category: '.htmlspecialchars($category);
    if ($date_from) $active_filters[] = 'From: '.$date_from;
    if ($date_to)   $active_filters[] = 'To: '.$date_to;
    ?>
    <?php if ($active_filters): ?>
    <div class="active-filters">
      <?php foreach ($active_filters as $f): ?>
        <span class="filter-tag"><?php echo $f; ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- TABLE -->
  <div class="table-card">
    <div class="table-header">
      <div>
        <div class="table-title">All Transactions</div>
        <div class="table-meta">Page <?php echo $page_num; ?> of <?php echo $total_pages; ?> · <?php echo $total_rows; ?> total</div>
      </div>
      <div class="export-btns">
        <a href="<?php echo qs(['sort'=>$sort==='asc'?'desc':'asc']); ?>" class="btn btn-ghost btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 16V4m0 0L3 8m4-4 4 4M17 8v12m0 0 4-4m-4 4-4-4"/></svg>
          <?php echo $sort === 'desc' ? 'Oldest first' : 'Newest first'; ?>
        </a>
      </div>
    </div>

    <?php if ($transactions->num_rows > 0): ?>
    <table>
      <thead>
        <tr>
          <th>Transaction</th>
          <th>Type</th>
          <th>Amount</th>
          <th>Date</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($tx = $transactions->fetch_assoc()):
          $display_date = display_date($tx);
          $type_cls = strtolower($tx['type']) === 'income' ? 'income' : (strtolower($tx['type']) === 'expense' ? 'expense' : 'other');
          $sign = $type_cls === 'income' ? '+' : ($type_cls === 'expense' ? '-' : '');
          $icons = ['Food'=>'🍔','Transport'=>'🚗','Utilities'=>'💡','Entertainment'=>'🎬','Shopping'=>'🛍️','Health'=>'💊','Salary'=>'💼','Business'=>'📊','Investments'=>'📈','Shares'=>'📉','Freelance'=>'💻','Family'=>'👨‍👩‍👧','Rental'=>'🏠','Gift'=>'🎁','Other'=>'📦','salary'=>'💼','rent'=>'🏠','car'=>'🚗','loan'=>'💳','stocks'=>'📈','laptop'=>'💻','Traveling'=>'✈️'];
          $icon = $icons[$tx['category']] ?? '💰';
          $bg = $type_cls === 'income' ? '#ecfdf5' : ($type_cls === 'expense' ? '#fff1f2' : '#fffbeb');
        ?>
        <tr>
          <td>
            <div class="tx-category">
              <div class="tx-icon" style="background:<?php echo $bg; ?>"><?php echo $icon; ?></div>
              <div>
                <div class="tx-name"><?php echo htmlspecialchars(ucfirst($tx['category'])); ?></div>
                <?php if (!empty($tx['description'])): ?>
                <div class="tx-desc"><?php echo htmlspecialchars($tx['description']); ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><span class="type-badge <?php echo $type_cls; ?>"><?php echo htmlspecialchars(ucfirst($tx['type'])); ?></span></td>
          <td><span class="amount <?php echo $type_cls; ?>"><?php echo $sign; ?>KES <?php echo number_format($tx['amount']); ?></span></td>
          <td><span class="tx-date-str"><?php echo $display_date; ?></span></td>
          <td>
            <div class="action-btns" style="justify-content:flex-end;">
              <button class="icon-btn edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($tx)); ?>)" title="Edit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </button>
              <button class="icon-btn del" onclick="openDeleteModal(<?php echo $tx['transaction_id']; ?>, '<?php echo htmlspecialchars(ucfirst($tx['category'])); ?>')" title="Delete">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty">
      <div class="empty-icon">🔍</div>
      <div class="empty-title">No transactions found</div>
      <div class="empty-sub">Try adjusting your filters or add a new transaction.</div>
    </div>
    <?php endif; ?>

    <!-- PAGINATION -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <div class="pagination-info">
        Showing <?php echo $offset+1; ?>–<?php echo min($offset+$per_page, $total_rows); ?> of <?php echo $total_rows; ?>
      </div>
      <div class="pagination-btns">
        <a href="<?php echo qs(['page'=>$page_num-1]); ?>" class="page-btn <?php echo $page_num<=1?'disabled':''; ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <?php
        $range_start = max(1, $page_num-2);
        $range_end   = min($total_pages, $page_num+2);
        if ($range_start > 1) echo '<span class="page-btn disabled">…</span>';
        for ($i=$range_start; $i<=$range_end; $i++):
        ?>
          <a href="<?php echo qs(['page'=>$i]); ?>" class="page-btn <?php echo $i===$page_num?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor;
        if ($range_end < $total_pages) echo '<span class="page-btn disabled">…</span>'; ?>
        <a href="<?php echo qs(['page'=>$page_num+1]); ?>" class="page-btn <?php echo $page_num>=$total_pages?'disabled':''; ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

</main>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal-overlay" id="txModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Add Transaction</div>
      <button class="modal-close" onclick="closeModal('txModal')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <form id="txForm">
        <input type="hidden" id="txId" name="id" value="">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="type" id="txType" class="form-control" required onchange="updateCategories()">
              <option value="expense">Expense</option>
              <option value="income">Income</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <select name="category" id="txCategory" class="form-control" required></select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Amount (KES)</label>
            <input type="number" name="amount" id="txAmount" class="form-control" min="1" step="any" placeholder="0.00" required>
          </div>
          <div class="form-group">
            <label class="form-label">Date</label>
            <input type="date" name="created_at" id="txDate" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description (optional)</label>
          <textarea name="description" id="txDesc" class="form-control" rows="2" placeholder="What was this for?"></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('txModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitTx()">Save Transaction</button>
    </div>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal-overlay" id="delModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Confirm Delete</div>
      <button class="modal-close" onclick="closeModal('delModal')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="del-confirm">
      <div class="del-confirm-icon">🗑️</div>
      <div class="del-confirm-title">Delete this transaction?</div>
      <div class="del-confirm-sub" id="delSub">This action cannot be undone.</div>
      <div style="display:flex;gap:10px;justify-content:center;">
        <button class="btn btn-ghost" onclick="closeModal('delModal')">Cancel</button>
        <a href="#" id="delConfirmBtn" class="btn btn-danger">Yes, Delete</a>
      </div>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
const EXPENSE_CATS = [
  'Groceries','Household Supplies','Eating Out',
  'Fuel & Charging','Vehicle Maintenance','Commuting',
  'Utilities','Home Decor','Maintenance & DIY','Kitchenware',
  'Health & Pharmacy','Personal Grooming',
  'Clothing & Accessories','Electronics & Tech','Entertainment',
  'Gifts & Celebrations','Pet Supplies','Impulse Buys','Other'
];
const INCOME_CATS  = ['Salary','Business','Investments','Shares','Freelance','Family','Rental','Gift','Other Income'];

function updateCategories() {
  const type = document.getElementById('txType').value;
  const sel  = document.getElementById('txCategory');
  const cur  = sel.value;
  const cats = type === 'income' ? INCOME_CATS : EXPENSE_CATS;
  sel.innerHTML = cats.map(c => `<option value="${c}"${c===cur?' selected':''}>${c}</option>`).join('');
}

function openAddModal() {
  document.getElementById('modalTitle').textContent = 'Add Transaction';
  document.getElementById('txId').value = '';
  document.getElementById('txType').value = 'expense';
  updateCategories();
  document.getElementById('txAmount').value = '';
  document.getElementById('txDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('txDesc').value = '';
  document.getElementById('txModal').classList.add('open');
}

function openEditModal(tx) {
  document.getElementById('modalTitle').textContent = 'Edit Transaction';
  document.getElementById('txId').value = tx.transaction_id;
  document.getElementById('txType').value = tx.type.toLowerCase();
  updateCategories();
  document.getElementById('txCategory').value = tx.category;
  document.getElementById('txAmount').value = tx.amount;
  // Fix 0000-00-00 dates
  let d = tx.date && tx.date !== '0000-00-00' ? tx.date : tx.created_at.split(' ')[0];
  document.getElementById('txDate').value = d;
  document.getElementById('txDesc').value = tx.description || '';
  document.getElementById('txModal').classList.add('open');
}

function openDeleteModal(id, name) {
  document.getElementById('delSub').textContent = `Deleting "${name}" — this cannot be undone.`;
  document.getElementById('delConfirmBtn').href = `delete_transaction.php?id=${id}`;
  document.getElementById('delModal').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

async function submitTx() {
  const form  = document.getElementById('txForm');
  const id    = document.getElementById('txId').value;
  const data  = new FormData(form);
  const url   = id ? `edit_transaction.php` : `add_transaction.php`;
  try {
    const res  = await fetch(url, {method:'POST', body: data});
    const json = await res.json();
    if (json.success) {
      closeModal('txModal');
      showToast(id ? 'Transaction updated!' : 'Transaction added!', 'success');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast(json.message || 'Something went wrong.', 'error');
    }
  } catch(e) {
    showToast('Network error. Please try again.', 'error');
  }
}

function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + type;
  setTimeout(() => t.classList.remove('show'), 3000);
}

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}

// Close modals on backdrop click
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});

// Search on Enter
document.querySelector('.search-input').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') this.closest('form').submit();
});

// Show toast from URL params
const urlMsg = new URLSearchParams(location.search).get('msg');
if (urlMsg === 'success') showToast('Transaction deleted.', 'success');

updateCategories();
</script>
</body>
</html>