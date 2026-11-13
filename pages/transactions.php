<?php
session_start();
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

if (($_SESSION['role'] ?? '') === 'admin') {
    header("Location: admin_dashboard.php");
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
    $like = '%' . $search . '%';
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

/* ── DATE RANGE FOR FILTERS ── */
$min_date = '2020-01-01';
$max_date = date('Y-12-31');

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
    return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🧾Transactions</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root {
    /* ── Light Palette ── */
    --page:       #F7F6F3;
    --surface:    #FFFFFF;
    --surface-2:  #F2F0EC;
    --surface-3:  #EAE8E2;
    --ink:        #1E1B4B;
    --line:       rgba(30,27,75,.1);
    --line-2:     rgba(30,27,75,.05);

    --accent:      #FF6B35;
    --accent-2:    #E8356D;
    --accent-grad: linear-gradient(135deg,#FF6B35,#E8356D);
    --accent-lt:   rgba(255,107,53,.12);

    --cyan:        #4F46E5;
    --cyan-dim:    rgba(79,70,229,.1);
    --cyan-glow:   0 4px 20px rgba(79,70,229,.18);
    --coral:       #E8356D;
    --coral-dim:   rgba(232,53,109,.1);
    --violet:      #7C3AED;
    --violet-dim:  rgba(124,58,237,.1);

    --green:       #059669;
    --green-dim:   rgba(5,150,105,.1);
    --green-lt:    rgba(5,150,105,.1);
    --red:         #E11D48;
    --red-dim:     rgba(225,29,72,.1);
    --red-lt:      rgba(225,29,72,.1);
    --amber:       #D97706;
    --amber-dim:   rgba(217,119,6,.1);
    --amber-lt:    rgba(217,119,6,.1);

    --font:        'Plus Jakarta Sans', sans-serif;
    --font-head:   'Space Grotesk', sans-serif;
    --font-mono:   'Space Mono', monospace;
    --text-1:      #0F0F28;
    --text-2:      rgba(15,15,40,.55);
    --text-3:      rgba(15,15,40,.35);

    --ink-2:  rgba(15,15,40,.65);
    --ink-3:  rgba(15,15,40,.4);

    --sidebar-w:         240px;
    --sidebar-collapsed: 64px;
    --r:                 10px;
    --r-lg:              16px;
    --r-xl:              22px;
    --shadow:     0 1px 8px rgba(15,15,40,.08);
    --shadow-md:  0 4px 24px rgba(15,15,40,.12);
    --shadow-lg:  0 12px 48px rgba(15,15,40,.15);
}

body{
    font-family:var(--font);
    background:var(--page);
    color:var(--text-1);
    font-size:14px;
    line-height:1.6;
    -webkit-font-smoothing:antialiased;
}

/* Animated mesh background */
body::before{
    content:'';position:fixed;inset:0;
    background:
        radial-gradient(ellipse 70% 50% at 10% 0%, rgba(255,107,53,.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(79,70,229,.05) 0%, transparent 55%),
        radial-gradient(ellipse 50% 60% at 50% 50%, rgba(232,53,109,.03) 0%, transparent 70%);
    pointer-events:none;z-index:0;
}
body > *{position:relative;z-index:1;}

.layout{display:flex;min-height:100vh;}

/* ── SIDEBAR ── */
.sidebar{
    width:var(--sidebar-w);flex-shrink:0;
    background:linear-gradient(180deg,#1E1B4B 0%,#16134A 100%);
    border-right:none;
    box-shadow: 4px 0 20px rgba(30,27,75,.12);
    position:fixed;top:0;left:0;height:100vh;
    display:flex;flex-direction:column;
    z-index:50;overflow:hidden;
    transition:width .25s cubic-bezier(.4,0,.2,1);
}
.sidebar.collapsed{width:var(--sidebar-collapsed);}
.sidebar-brand{
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 14px;border-bottom:1px solid rgba(255,255,255,.05);flex-shrink:0;
}
.brand-mark{display:flex;align-items:center;gap:10px;min-width:0;}
.brand-icon{
    width:34px;height:34px;border-radius:10px;
    background:linear-gradient(135deg,#FF6B35,#E8356D);
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 4px 14px rgba(255,107,53,.4);flex-shrink:0;
}
.brand-icon svg{width:18px;height:18px;color:#fff;}
.brand-text{overflow:hidden;}
.brand-name{font-size:15px;font-weight:700;font-family:var(--font-head);color:#fff;letter-spacing:-.2px;white-space:nowrap;}
.brand-tagline{font-size:10.5px;color:var(--text-3);margin-top:1px;white-space:nowrap;}
.sidebar-toggle{
    width:26px;height:26px;border-radius:6px;
    background:rgba(255,255,255,.06);border:none;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    color:var(--text-3);transition:background .15s,color .15s,transform .25s;flex-shrink:0;
}
.sidebar-toggle:hover{background:rgba(255,255,255,.16);color:#fff;}
.sidebar.collapsed .sidebar-toggle{transform:rotate(180deg);margin:0 auto;}
.sidebar.collapsed .brand-text{opacity:0;width:0;pointer-events:none;}

.sidebar-nav{
    padding:12px 8px;flex:1;overflow-y:auto;overflow-x:hidden;
    scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.08) transparent;
}
.nav-section-label{
    font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;
    color:rgba(255,255,255,.25);padding:0 8px;margin:8px 0 4px;
    white-space:nowrap;overflow:hidden;transition:opacity .2s;
}
.sidebar.collapsed .nav-section-label{opacity:0;height:0;margin:0;padding:0;}
.nav-item{
    display:flex;align-items:center;gap:10px;padding:9px 10px;
    border-radius:7px;color:rgba(255,255,255,.5);text-decoration:none;
    font-size:13.5px;font-weight:400;margin-bottom:2px;
    white-space:nowrap;overflow:hidden;transition:background .15s,color .15s;
}
.nav-item svg{width:16px;height:16px;flex-shrink:0;}
.nav-item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.85);}
.nav-item.active{background:linear-gradient(90deg,rgba(255,107,53,.25),rgba(232,53,109,.15));color:#FFB39A;font-weight:600;border-left:2px solid #FF6B35;}
.nav-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;transition:opacity .2s,width .2s;}
.nav-badge{margin-left:auto;background:rgba(255,107,53,.2);color:#FFB39A;font-size:10px;font-weight:600;padding:1px 6px;border-radius:10px;font-family:var(--font-mono);transition:opacity .2s;}
.sidebar.collapsed .nav-label,.sidebar.collapsed .nav-badge{opacity:0;width:0;pointer-events:none;}
.sidebar.collapsed .nav-item{justify-content:center;padding:10px;}

.sidebar-footer{padding:10px 8px 14px;border-top:1px solid rgba(255,255,255,.06);flex-shrink:0;}
.user-row{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;transition:background .15s;cursor:pointer;}
.user-row:hover{background:rgba(255,255,255,.06);}
.user-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#FF6B35,#E8356D);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#fff;flex-shrink:0;}
.user-name{font-size:12.5px;color:rgba(255,255,255,.7);font-weight:500;white-space:nowrap;}

/* ── HAMBURGER ── */
.hamburger{display:none;position:fixed;top:16px;left:16px;z-index:100;background:var(--surface);border:1px solid var(--line);color:var(--text-1);width:40px;height:40px;border-radius:8px;cursor:pointer;align-items:center;justify-content:center;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:40;}

/* ── MAIN ── */
.main{flex:1;margin-left:var(--sidebar-w);padding:32px 36px;display:flex;flex-direction:column;gap:24px;min-width:0;}

/* ── TOPBAR ── */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;}
.page-title{font-size:22px;font-weight:700;font-family:var(--font-head);letter-spacing:-.4px;color:var(--text-1);}
.page-sub{font-size:13px;color:var(--text-2);margin-top:2px;}
.topbar-actions{display:flex;gap:10px;align-items:center;flex-shrink:0;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--r);font-size:13px;font-weight:500;cursor:pointer;border:none;transition:all .15s;text-decoration:none;white-space:nowrap;font-family:var(--font);}
.btn svg{width:14px;height:14px;flex-shrink:0;}
.btn-primary{background:linear-gradient(135deg,#FF6B35,#E8356D);color:#fff;font-weight:700;box-shadow:0 4px 16px rgba(255,107,53,.3);}
.btn-primary:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-ghost{background:var(--surface-2);color:var(--text-2);border:1px solid var(--line);}
.btn-ghost:hover{background:var(--surface-3);color:var(--text-1);}
.btn-danger{background:var(--red-dim);color:var(--red);border:1px solid rgba(255,77,109,.3);}
.btn-danger:hover{background:rgba(255,77,109,.2);}
.btn-sm{padding:5px 10px;font-size:12px;}

/* ── CARD GLOW ── */
.stat-card,
.filter-bar,
.table-card {
    position: relative;
}
.stat-card::before,
.filter-bar::before,
.table-card::before {
    content: '';
    position: absolute;
    inset: -1px;
    border-radius: inherit;
    padding: 1px;
    background: linear-gradient(135deg, rgba(255,107,53,.08) 0%, rgba(232,53,109,.06) 50%, rgba(79,70,229,.05) 100%);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
    opacity: 0;
    transition: opacity .3s;
    z-index: 0;
}
.stat-card:hover::before,
.filter-bar:hover::before,
.table-card:hover::before {
    opacity: 1;
}
.stat-card::after {
    content: '';
    position: absolute;
    inset: -18px;
    border-radius: inherit;
    opacity: 0;
    transition: opacity .4s;
    pointer-events: none;
    z-index: -1;
    filter: blur(22px);
}
.stat-card:nth-child(1)::after { background: radial-gradient(ellipse at 50% 50%, rgba(255,107,53,.15) 0%, transparent 70%); }
.stat-card:nth-child(2)::after { background: radial-gradient(ellipse at 50% 50%, rgba(16,245,160,.15) 0%, transparent 70%); }
.stat-card:nth-child(3)::after { background: radial-gradient(ellipse at 50% 50%, rgba(255,77,109,.15) 0%, transparent 70%); }
.stat-card:nth-child(4)::after { background: radial-gradient(ellipse at 50% 50%, rgba(255,214,10,.13) 0%, transparent 70%); }
.stat-card:hover::after { opacity: 1; }

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.stat-card{background:var(--surface);border-radius:var(--r-lg);padding:18px 20px;border:1px solid var(--line);box-shadow:var(--shadow);display:flex;align-items:center;gap:14px;transition:transform .2s,box-shadow .2s,border-color .2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:rgba(30,27,75,.14);}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-icon svg{width:18px;height:18px;}
.stat-icon.accent{background:var(--cyan-dim);color:var(--cyan);}
.stat-icon.green{background:var(--green-dim);color:var(--green);}
.stat-icon.red{background:var(--red-dim);color:var(--red);}
.stat-icon.amber{background:var(--amber-lt);color:var(--amber);}
.stat-label{font-size:11.5px;color:var(--text-3);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.stat-value{font-size:19px;font-weight:600;letter-spacing:-.5px;font-family:var(--font-mono);margin-top:2px;color:var(--text-1);}
.stat-value.positive{color:var(--green);}
.stat-value.negative{color:var(--red);}

/* ── FILTER BAR ── */
.filter-bar{background:var(--surface);border-radius:var(--r-lg);padding:16px 20px;border:1px solid var(--line);box-shadow:var(--shadow);transition:border-color .2s,box-shadow .2s;}
.filter-bar:hover{border-color:rgba(79,70,229,.2);box-shadow:var(--shadow-md);}
.filter-form{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.filter-group{display:flex;flex-direction:column;gap:4px;min-width:0;}
.filter-group label{font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;}
.filter-input{height:36px;padding:0 10px;border-radius:8px;border:1px solid var(--line);background:var(--surface-2);color:var(--text-1);font-size:13px;font-family:inherit;outline:none;transition:border-color .15s;}
.filter-input:focus{border-color:#FF6B35;box-shadow:0 0 0 3px rgba(255,107,53,.1);}
.filter-input option{background:var(--surface-2);}
.search-wrap{position:relative;flex:1;min-width:180px;}
.search-wrap svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--text-3);pointer-events:none;}
.search-input{padding-left:32px;width:100%;}
.active-filters{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;}
.filter-tag{display:inline-flex;align-items:center;gap:5px;background:var(--cyan-dim);color:var(--cyan);font-size:11.5px;font-weight:500;padding:3px 8px;border-radius:20px;}
.filter-tag a{color:inherit;text-decoration:none;display:flex;align-items:center;font-size:12px;margin-left:2px;}

/* ── TABLE ── */
.table-card{background:var(--surface);border-radius:var(--r-lg);border:1px solid var(--line);box-shadow:var(--shadow);overflow:hidden;transition:border-color .2s,box-shadow .2s;}
.table-card:hover{border-color:rgba(124,58,237,.25);box-shadow:0 0 40px rgba(124,58,237,.08),var(--shadow);}
.table-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line);}
.table-title{font-size:14px;font-weight:600;color:var(--text-1);}
.table-meta{font-size:12px;color:var(--text-3);}
.export-btns{display:flex;gap:8px;}
table{width:100%;border-collapse:collapse;}
thead{background:var(--surface-2);}
th{padding:10px 16px;text-align:left;font-size:11.5px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
th a:hover{color:var(--text-1);}
td{padding:12px 16px;font-size:13.5px;border-bottom:1px solid var(--line-2);color:var(--text-1);}
tr:last-child td{border-bottom:none;}
tbody tr{transition:background .1s;}
tbody tr:hover{background:var(--surface-2);}

.tx-category{display:flex;align-items:center;gap:10px;}
.tx-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;background:var(--surface-3);}
.tx-name{font-weight:500;font-size:13.5px;color:var(--text-1);}
.tx-desc{font-size:11.5px;color:var(--text-3);margin-top:1px;}
.type-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600;text-transform:capitalize;}
.type-badge.income{background:var(--green-dim);color:var(--green);}
.type-badge.expense{background:var(--red-dim);color:var(--red);}
.type-badge.other{background:var(--amber-lt);color:var(--amber);}
.amount{font-family:var(--font-mono);font-size:13.5px;font-weight:500;white-space:nowrap;}
.amount.income{color:var(--green);}
.amount.expense{color:var(--red);}
.tx-date-str{font-size:13px;color:var(--text-2);}
.action-btns{display:flex;gap:6px;}
.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:none;cursor:pointer;transition:background .15s;}
.icon-btn svg{width:13px;height:13px;}
.icon-btn.edit{background:var(--cyan-dim);color:var(--cyan);}
.icon-btn.edit:hover{background:rgba(79,70,229,.15);}
.icon-btn.del{background:var(--red-dim);color:var(--red);}
.icon-btn.del:hover{background:rgba(255,77,109,.22);}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:56px 20px;}
.empty-icon{font-size:40px;margin-bottom:12px;}
.empty-title{font-size:16px;font-weight:600;margin-bottom:6px;color:var(--text-1);}
.empty-sub{font-size:13px;color:var(--text-3);}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--line);}
.pagination-info{font-size:12.5px;color:var(--text-3);}
.pagination-btns{display:flex;gap:4px;}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:500;text-decoration:none;color:var(--text-2);border:1px solid var(--line);background:var(--surface-2);transition:all .15s;}
.page-btn:hover{background:var(--surface-3);color:var(--text-1);}
.page-btn.active{background:#FF6B35;color:#fff;border-color:#FF6B35;font-weight:700;}
.page-btn.disabled{opacity:.3;pointer-events:none;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,15,40,.4);z-index:200;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
.modal-overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);width:100%;max-width:460px;box-shadow:var(--shadow-lg);overflow:hidden;animation:slideUp .2s ease;}
@keyframes slideUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--line);}
.modal-title{font-size:16px;font-weight:600;color:var(--text-1);}
.modal-close{background:none;border:none;cursor:pointer;color:var(--text-3);display:flex;padding:4px;}
.modal-close:hover{color:var(--text-1);}
.modal-close svg{width:18px;height:18px;}
.modal-body{padding:24px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.form-group:last-child{margin-bottom:0;}
.form-label{font-size:12px;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.4px;}
.form-control{height:38px;padding:0 12px;border-radius:8px;border:1.5px solid var(--line);font-size:13.5px;font-family:inherit;color:var(--text-1);background:var(--surface-2);outline:none;transition:border-color .15s,box-shadow .15s;}
.form-control:focus{border-color:#FF6B35;box-shadow:0 0 0 3px rgba(255,107,53,.1);}
.form-control option{background:var(--surface-2);}
textarea.form-control{height:auto;padding:10px 12px;resize:vertical;}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding:16px 24px;border-top:1px solid var(--line);}
.del-confirm{padding:24px;text-align:center;}
.del-confirm-icon{font-size:36px;margin-bottom:12px;}
.del-confirm-title{font-size:16px;font-weight:600;margin-bottom:6px;color:var(--text-1);}
.del-confirm-sub{font-size:13px;color:var(--text-3);margin-bottom:20px;}

/* ── TOAST ── */
.toast{position:fixed;bottom:24px;right:24px;background:var(--surface-3);color:var(--text-1);padding:12px 18px;border-radius:var(--r);font-size:13px;font-weight:500;box-shadow:var(--shadow-md);z-index:500;transform:translateY(8px);opacity:0;transition:all .25s;pointer-events:none;border:1px solid var(--line);}
.toast.show{opacity:1;transform:translateY(0);}
.toast.success{border-left:3px solid var(--green);}
.toast.error{border-left:3px solid var(--red);}

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
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2"
                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <div class="brand-text">
        <div class="brand-name">Fintrack</div>
        <div class="brand-tagline">Personal Finance</div>
      </div>
    </div>
    <button class="sidebar-toggle" id="sidebarToggle" title="Collapse sidebar">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
      </svg>
    </button>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Overview</div>
    <a href="../pages/dashboard.php" class="nav-item">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/>
      </svg>
      <span class="nav-label">Dashboard</span>
    </a>
    <div class="nav-section-label">Money</div>
    <a href="../pages/transactions.php" class="nav-item active">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
      </svg>
      <span class="nav-label">Transactions</span>
    </a>
    <a href="../pages/budget.php" class="nav-item">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
      </svg>
      <span class="nav-label">Budget</span>
    </a>
    <a href="../pages/reports.php" class="nav-item">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/>
      </svg>
      <span class="nav-label">Reports</span>
    </a>
    <div class="nav-section-label">Account</div>
    <a href="/finance_system/pages/settings.php" class="nav-item">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      <span class="nav-label">Settings</span>
    </a>
    <a href="../auth/logout.php" class="nav-item">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
      </svg>
      <span class="nav-label">Logout</span>
    </a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-row">
      <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)); ?></div>
      <div class="nav-label">
        <div class="user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></div>
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
        <input type="text" name="date_from" class="filter-input" min="<?php echo htmlspecialchars($min_date); ?>" max="<?php echo htmlspecialchars($max_date); ?>" value="<?php echo htmlspecialchars($date_from); ?>" autocomplete="off">
      </div>
      <div class="filter-group">
        <label>To</label>
        <input type="text" name="date_to" class="filter-input" min="<?php echo htmlspecialchars($min_date); ?>" max="<?php echo htmlspecialchars($max_date); ?>" value="<?php echo htmlspecialchars($date_to); ?>" autocomplete="off">
      </div>
      <div class="filter-group" style="flex-basis:100%;font-size:11px;color:var(--text-3);padding-left:8px;line-height:1.3;">
        Tip: click the calendar icon to select a complete date range.
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
        <a href="../pages/transactions.php" class="btn btn-ghost" style="height:36px;">Clear</a>
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
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
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
        <form id="delConfirmForm" method="POST" action="../actions/delete_transaction.php">
          <input type="hidden" name="id" id="delConfirmId" value="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <button type="submit" class="btn btn-danger">Yes, Delete</button>
        </form>
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

function getLocalToday() {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  return now.toISOString().split('T')[0];
}

function openAddModal() {
  document.getElementById('modalTitle').textContent = 'Add Transaction';
  document.getElementById('txId').value = '';
  document.getElementById('txType').value = 'expense';
  updateCategories();
  document.getElementById('txAmount').value = '';
  const today = getLocalToday();
  document.getElementById('txDate').max = today;
  document.getElementById('txDate').value = today;
  document.getElementById('txDesc').value = '';
  document.getElementById('txModal').classList.add('open');
}

function openEditModal(tx) {
  document.getElementById('modalTitle').textContent = 'Edit Transaction';
  document.getElementById('txId').value = tx.transaction_id;
  const today = getLocalToday();
  document.getElementById('txDate').max = today;
  document.getElementById('txType').value = tx.type.toLowerCase();
  updateCategories();
  document.getElementById('txCategory').value = tx.category;
  document.getElementById('txAmount').value = tx.amount;
  // Fix 0000-00-00 dates
  let d = tx.date && tx.date !== '0000-00-00' ? tx.date : tx.created_at.split(' ')[0];
  if (!/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(d)) d = today;
  document.getElementById('txDate').value = d;
  document.getElementById('txDesc').value = tx.description || '';
  document.getElementById('txModal').classList.add('open');
}

function openDeleteModal(id, name) {
  document.getElementById('delSub').textContent = `Deleting "${name}" — this cannot be undone.`;
  document.getElementById('delConfirmId').value = id;
  document.getElementById('delModal').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

function validateTxForm() {
  const type     = document.getElementById('txType').value;
  const category = document.getElementById('txCategory').value.trim();
  const amount   = parseFloat(document.getElementById('txAmount').value);
  const date     = document.getElementById('txDate').value;
  const todayStr = new Date().toISOString().split('T')[0];

  if (!['expense', 'income'].includes(type)) return 'Please choose a valid transaction type.';
  if (!category) return 'Please choose a category.';
  if (isNaN(amount) || amount <= 0) return 'Amount must be a number greater than 0.';
  if (amount > 100000000) return 'That amount looks too large — please double-check it.';
  if (!date || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return 'Please choose a valid date.';
  if (date > todayStr) return 'Date cannot be in the future.';
  return null;
}

async function submitTx() {
  const form  = document.getElementById('txForm');
  const id    = document.getElementById('txId').value;

  const validationError = validateTxForm();
  if (validationError) {
    showToast(validationError, 'error');
    return;
  }

  const data  = new FormData(form);
  const url   = id ? `../actions/edit_transaction.php` : `../actions/add_transaction.php`;
  try {
    const res  = await fetch(url, {method:'POST', body: data});
    const text = await res.text();
    console.log('Response status:', res.status, 'Body:', text);
    let json;
    try { json = JSON.parse(text); } catch(parseErr) {
      showToast('Server error (not JSON). Check console.', 'error');
      return;
    }
    if (json.success) {
      closeModal('txModal');
      showToast(id ? 'Transaction updated!' : 'Transaction added!', 'success');
      // Refresh CSRF token for next submission
      if (json.csrf_token) {
        const el = document.querySelector('input[name="csrf_token"]');
        if (el) el.value = json.csrf_token;
      }
      setTimeout(() => location.reload(), 800);
    } else {
      showToast(json.message || 'Something went wrong.', 'error');
    }
  } catch(e) {
    showToast('Error: ' + e.message, 'error');
    console.error('submitTx error:', e);
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

// Collapse/expand toggle (desktop)
(function() {
  const sidebar   = document.getElementById('sidebar');
  const toggleBtn = document.getElementById('sidebarToggle');
  const main      = document.querySelector('.main');

  function applyCollapsed(collapsed) {
    sidebar.classList.toggle('collapsed', collapsed);
    if (main) main.style.marginLeft = collapsed ? 'var(--sidebar-collapsed)' : 'var(--sidebar-w)';
  }

  if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      const collapsed = !sidebar.classList.contains('collapsed');
      applyCollapsed(collapsed);
      localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    });
  }

})();

// Close modals on backdrop click
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});

// Search on Enter
const searchInput = document.querySelector('.search-input');
if (searchInput) {
  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') this.closest('form').submit();
  });
}

// Show toast from URL params
const urlMsg = new URLSearchParams(location.search).get('msg');
if (urlMsg === 'success') showToast('Transaction deleted.', 'success');

updateCategories();

if (typeof flatpickr !== 'undefined') {
  flatpickr('input[name="date_from"], input[name="date_to"], #txDate', {
    dateFormat: 'Y-m-d',
    minDate: '<?php echo htmlspecialchars($min_date); ?>',
    maxDate: '<?php echo htmlspecialchars($max_date); ?>',
    allowInput: true,
    locale: {
      firstDayOfWeek: 1
    }
  });
}
</script>
</body>
</html>