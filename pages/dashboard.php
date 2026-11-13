<?php
session_start();
require_once __DIR__ . '/../core/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

if (($_SESSION['role'] ?? '') === 'admin') {
    header("Location: admin_dashboard.php");
    exit();
}

$user_id = (int) $_SESSION['user_id']; 
$user_name = $_SESSION['name'] ?? 'User'; 

/* ── MONTHLY TREND ── */
$months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthly = array_fill(0, 12, 0);

$res = $conn->query("
    SELECT MONTH(created_at) m, SUM(amount) total
    FROM transactions
    WHERE user_id = $user_id AND type = 'expense' AND is_deleted_by_user = 0
    GROUP BY m
");
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $monthly[$row['m'] - 1] = (int) $row['total'];
    }
}

/* ── CATEGORY BREAKDOWN ── */
$catLabels = [];
$catData   = [];
$catColors = [];
$colors    = ['#4F46E5','#E11D48','#059669','#D97706','#0891B2','#7C3AED','#EA580C'];

$q = $conn->query("
    SELECT category, SUM(amount) total
    FROM transactions
    WHERE user_id = $user_id AND type = 'expense' AND is_deleted_by_user = 0
    GROUP BY category
    ORDER BY total DESC
");
$totalExpense = 0;
$i = 0;
if ($q && $q->num_rows > 0) {
    while ($r = $q->fetch_assoc()) {
        $catLabels[] = $r['category'];
        $catData[]   = (int) $r['total'];
        $catColors[] = $colors[$i % count($colors)];
        $totalExpense += (int) $r['total'];
        $i++;
    }
}

$stmt = $conn->prepare("SELECT SUM(amount) total FROM transactions WHERE user_id = ? AND type = 'income' AND is_deleted_by_user = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$totalIncome = (int) $stmt->get_result()->fetch_assoc()['total'];

$netBalance = $totalIncome - $totalExpense;

$stmt = $conn->prepare("SELECT COUNT(*) cnt FROM transactions WHERE user_id = ? AND is_deleted_by_user = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$txCount = (int) $stmt->get_result()->fetch_assoc()['cnt'];

$recent = $conn->query("
    SELECT * FROM transactions
    WHERE user_id = $user_id AND is_deleted_by_user = 0
    ORDER BY created_at DESC, transaction_id DESC
    LIMIT 8
");
if (!$recent) $recent = null;


$nonZeroMonths = count(array_filter($monthly));
$monthlyAvg    = $nonZeroMonths > 0 ? round($totalExpense / $nonZeroMonths) : 0;


$spendingRate = $totalIncome > 0 ? round($totalExpense / $totalIncome * 100) : 0;
$progressColor = ($totalIncome > 0 && ($totalExpense / max(1, $totalIncome)) > .8)
    ? 'var(--red)'
    : 'var(--accent)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Finance Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    /* ── Light Palette ── */
    --page:       #F7F6F3;
    --surface:    #FFFFFF;
    --surface-2:  #F2F0EC;
    --surface-3:  #EAE8E2;
    --ink:        #1E1B4B;
    --line:       rgba(30,27,75,.1);
    --line-2:     rgba(30,27,75,.05);

    /* ── Primary: Tangerine → Rose ── */
    --accent:      #FF6B35;
    --accent-2:    #E8356D;
    --accent-grad: linear-gradient(135deg,#FF6B35,#E8356D);

    /* ── Cyan alias → indigo for light mode ── */
    --cyan:        #4F46E5;
    --cyan-dim:    rgba(79,70,229,.1);
    --cyan-glow:   0 4px 20px rgba(79,70,229,.18);
    --coral:       #E8356D;
    --coral-dim:   rgba(232,53,109,.1);
    --coral-glow:  0 4px 20px rgba(232,53,109,.2);
    --violet:      #7C3AED;
    --violet-dim:  rgba(124,58,237,.1);

    /* ── Semantic ── */
    --green:       #059669;
    --green-dim:   rgba(5,150,105,.1);
    --red:         #E11D48;
    --red-dim:     rgba(225,29,72,.1);
    --amber:       #D97706;
    --amber-dim:   rgba(217,119,6,.1);

    /* ── Typography ── */
    --font:        'Plus Jakarta Sans', sans-serif;
    --font-head:   'Space Grotesk', sans-serif;
    --font-mono:   'Space Mono', monospace;
    --text-1:      #0F0F28;
    --text-2:      rgba(15,15,40,.55);
    --text-3:      rgba(15,15,40,.35);

    /* ── Layout ── */
    --sidebar-w:         240px;
    --sidebar-collapsed: 64px;
    --r:                 10px;
    --r-lg:              16px;
    --r-xl:              22px;
    --shadow:     0 1px 8px rgba(15,15,40,.08);
    --shadow-md:  0 4px 24px rgba(15,15,40,.12);
    --shadow-lg:  0 12px 48px rgba(15,15,40,.15);
}

/* ── BASE ── */
body {
    font-family: var(--font);
    background: var(--page);
    color: var(--text-1);
    font-size: 14px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* Subtle light mesh background */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 70% 50% at 10% 0%, rgba(255,107,53,.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(79,70,229,.05) 0%, transparent 55%),
        radial-gradient(ellipse 50% 60% at 50% 50%, rgba(232,53,109,.03) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
}
body > * { position: relative; z-index: 1; }

/* ── LAYOUT ── */
.layout { display: flex; min-height: 100vh; }

/* ── SIDEBAR ── */
.sidebar {
    width: var(--sidebar-w);
    flex-shrink: 0;
    background: linear-gradient(180deg, #1E1B4B 0%, #16134A 100%);
    border-right: none;
    position: fixed; top: 0; left: 0; height: 100vh;
    display: flex; flex-direction: column;
    z-index: 50; overflow: hidden;
    transition: width .25s cubic-bezier(.4,0,.2,1);
    box-shadow: 4px 0 20px rgba(30,27,75,.12);
}
.sidebar.collapsed { width: var(--sidebar-collapsed); }

.sidebar-brand {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 14px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    flex-shrink: 0;
}
.brand-mark { display: flex; align-items: center; gap: 10px; min-width: 0; }
.brand-icon {
    width: 34px; height: 34px; border-radius: 10px;
    background: linear-gradient(135deg, #FF6B35, #E8356D);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(255,107,53,.4);
    flex-shrink: 0;
}
.brand-icon svg { width: 18px; height: 18px; color: #fff; }
.brand-text { overflow: hidden; }
.brand-name { font-size: 15px; font-weight: 700; font-family: var(--font-head); color: #fff; letter-spacing: -.2px; }
.brand-tagline { font-size: 10.5px; color: rgba(255,255,255,.4); margin-top: 1px; }
.sidebar-toggle {
    width: 26px; height: 26px; border-radius: 6px;
    background: rgba(255,255,255,.08); border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,.4); transition: background .15s, color .15s, transform .25s;
    flex-shrink: 0;
}
.sidebar-toggle:hover { background: rgba(255,255,255,.16); color: #fff; }
.sidebar.collapsed .sidebar-toggle { transform: rotate(180deg); }
.sidebar.collapsed .brand-text { opacity: 0; width: 0; pointer-events: none; }
.sidebar.collapsed .sidebar-toggle { margin: 0 auto; }

.sidebar-nav {
    padding: 12px 8px; flex: 1;
    overflow-y: auto; overflow-x: hidden;
    scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.1) transparent;
}
.sidebar-nav::-webkit-scrollbar { width: 3px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 99px; }

.nav-section-label {
    font-size: 9.5px; font-weight: 700; letter-spacing: 1.2px;
    text-transform: uppercase; color: rgba(255,255,255,.3);
    padding: 0 8px; margin: 10px 0 4px;
    white-space: nowrap; overflow: hidden; transition: opacity .2s;
}
.sidebar.collapsed .nav-section-label { opacity: 0; height: 0; margin: 0; padding: 0; }

.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px; border-radius: 8px;
    color: rgba(255,255,255,.5); text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: background .15s, color .15s;
    margin-bottom: 1px; white-space: nowrap; overflow: hidden;
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item:hover { background: rgba(255,255,255,.08); color: rgba(255,255,255,.9); }
.nav-item.active {
    background: linear-gradient(90deg, rgba(255,107,53,.25), rgba(232,53,109,.15));
    color: #FFB39A; font-weight: 600;
    border-left: 2px solid #FF6B35;
}
.nav-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.nav-badge {
    margin-left: auto;
    background: rgba(255,107,53,.2); color: #FFB39A;
    font-size: 9.5px; font-weight: 700; padding: 2px 7px;
    border-radius: 10px; font-family: var(--font-mono); flex-shrink: 0;
}
.sidebar.collapsed .nav-label { opacity: 0; width: 0; pointer-events: none; }
.sidebar.collapsed .nav-badge { display: none; }
.sidebar.collapsed .nav-item { justify-content: center; padding: 10px; border-left: none; }

.sidebar-footer {
    padding: 10px 8px 14px;
    border-top: 1px solid rgba(255,255,255,.08);
    flex-shrink: 0;
}
.user-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px; border-radius: 8px; cursor: pointer;
    transition: background .15s; overflow: hidden;
}
.user-row:hover { background: rgba(255,255,255,.06); }
.user-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    background: linear-gradient(135deg, #FF6B35, #E8356D);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.user-name { font-size: 12.5px; color: rgba(255,255,255,.8); font-weight: 600; }
.user-role { font-size: 10.5px; color: rgba(255,255,255,.3); }
.sidebar.collapsed .user-row { justify-content: center; padding: 8px; }

/* ── MAIN ── */
.main {
    flex: 1;
    margin-left: var(--sidebar-w);
    display: flex; flex-direction: column; min-height: 100vh;
    transition: margin-left .25s cubic-bezier(.4,0,.2,1);
}
.layout.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }

/* ── TOPBAR ── */
.topbar {
    background: rgba(255,255,255,.9);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(30,27,75,.08);
    padding: 0 28px; height: 58px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 40;
}
.topbar-left, .topbar-right { display: flex; align-items: center; gap: 10px; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-2); }
.breadcrumb-sep { opacity: .3; }
.breadcrumb-current { color: var(--text-1); font-weight: 600; }
.topbar-date {
    font-size: 11px; color: var(--text-2); font-family: var(--font-mono);
    background: var(--surface-2); border: 1px solid var(--line);
    padding: 4px 10px; border-radius: 6px;
}

/* ── BUTTONS ── */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: var(--r); border: none;
    font-size: 13px; font-weight: 600; font-family: var(--font);
    cursor: pointer; transition: all .18s cubic-bezier(.4,0,.2,1);
    text-decoration: none; white-space: nowrap;
}
.btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.btn-primary {
    background: linear-gradient(135deg, #ff35cd, #da174e);
    color: #fff;
    box-shadow: 0 4px 16px rgba(255,107,53,.3);
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(255,107,53,.4); filter: brightness(1.05); }
.btn-primary:active { transform: translateY(0); }
.btn-ghost { background: transparent; color: var(--text-2); border: 1px solid var(--line); }
.btn-ghost:hover { background: var(--surface-2); border-color: rgba(30,27,75,.18); color: var(--text-1); }
.btn-danger { background: var(--red-dim); color: var(--red); border: 1px solid transparent; }
.btn-danger:hover { background: rgba(225,29,72,.18); }
.btn-sm { padding: 5px 11px; font-size: 12px; }
.topbar-menu-btn { padding: 6px 10px; font-size: 12.5px; }

/* ── CONTENT ── */
.content { padding: 28px 30px 56px; }
.page-header { margin-bottom: 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.page-title {
    font-size: 24px; font-weight: 700;
    font-family: var(--font-head);
    letter-spacing: -.4px; line-height: 1.2;
    background: linear-gradient(90deg, #0F0F28 40%, #FF6B35);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.page-sub { font-size: 12.5px; color: var(--text-2); margin-top: 4px; }

/* ── STATS ── */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
.stat {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-lg); padding: 20px;
    box-shadow: var(--shadow);
    position: relative; overflow: hidden;
    transition: transform .2s, box-shadow .2s, border-color .2s;
}
.stat::after {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: var(--r-lg) var(--r-lg) 0 0;
    opacity: 0; transition: opacity .25s;
}
.stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: rgba(30,27,75,.14); }
.stat:hover::after { opacity: 1; }
/* Colored top borders */
.stat:nth-child(1)::after { background: linear-gradient(90deg, #E11D48, #FF6B6B); }
.stat:nth-child(2)::after { background: linear-gradient(90deg, #059669, #34D399); }
.stat:nth-child(3)::after { background: linear-gradient(90deg, #4F46E5, #7C3AED); }
.stat:nth-child(4)::after { background: linear-gradient(90deg, #D97706, #F59E0B); }

/* Decorative glow blobs */
.stat::before {
    content: '';
    position: absolute; width: 80px; height: 80px;
    border-radius: 50%; right: -20px; top: -20px;
    opacity: .05; transition: opacity .2s;
}
.stat:nth-child(1)::before { background: #E11D48; }
.stat:nth-child(2)::before { background: #059669; }
.stat:nth-child(3)::before { background: #4F46E5; }
.stat:nth-child(4)::before { background: #D97706; }
.stat:hover::before { opacity: .1; }

.stat-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
.stat-lbl {
    font-size: 10.5px; font-weight: 700; color: var(--text-3);
    text-transform: uppercase; letter-spacing: .8px;
}
.stat-ico {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.stat-ico svg { width: 17px; height: 17px; }
.ico-red   { background: var(--red-dim);   color: var(--red);   }
.ico-green { background: var(--green-dim); color: var(--green); }
.ico-blue  { background: var(--cyan-dim);  color: var(--cyan);  }
.ico-amber { background: var(--amber-dim); color: var(--amber); }

.stat-val {
    font-size: 26px; font-weight: 700;
    font-family: var(--font-mono);
    letter-spacing: -.6px; line-height: 1; margin-bottom: 8px;
    color: var(--text-1);
}
.stat-meta { font-size: 11px; color: var(--text-2); display: flex; align-items: center; gap: 5px; }
.pill {
    display: inline-flex; align-items: center; gap: 2px;
    padding: 2px 7px; border-radius: 20px;
    font-size: 10.5px; font-weight: 700;
}
.pill-up      { background: var(--red-dim);   color: var(--red);   }
.pill-down    { background: var(--green-dim); color: var(--green); }
.pill-neutral { background: rgba(255,255,255,.07); color: var(--text-2); }

/* ── CHART GRID ── */
.charts-row { display: grid; grid-template-columns: 1fr 340px; gap: 14px; margin-bottom: 14px; }
.bottom-row { display: grid; grid-template-columns: 1fr 300px; gap: 14px; }
.right-col { display: flex; flex-direction: column; gap: 14px; min-width: 0; }

/* ── CARDS ── */
.card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-lg); padding: 20px 22px;
    box-shadow: var(--shadow);
    transition: border-color .2s;
}
.card:hover { border-color: rgba(79,70,229,.2); }
.card-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.card-title {
    font-size: 14px; font-weight: 700;
    font-family: var(--font-head);
    letter-spacing: -.15px; color: var(--text-1);
}
.card-sub { font-size: 11.5px; color: var(--text-2); margin-top: 2px; }
.chart-wrap { position: relative; height: 260px; }

/* ── SEGMENT CONTROL ── */
.seg {
    display: flex; gap: 2px;
    background: var(--surface-2);
    border-radius: 8px; padding: 3px;
    border: 1px solid var(--line);
}
.seg-btn {
    padding: 4px 10px; border-radius: 6px; border: none;
    font-size: 12px; font-weight: 600; font-family: var(--font);
    cursor: pointer; background: transparent; color: var(--text-2);
    transition: all .15s;
}
.seg-btn.active {
    background: linear-gradient(135deg, rgba(255,107,53,.15), rgba(232,53,109,.1));
    color: #FF6B35; box-shadow: var(--shadow);
}

/* ── DONUT ── */
.donut-wrap { position: relative; display: flex; align-items: center; justify-content: center; height: 200px; }
.donut-center { position: absolute; text-align: center; pointer-events: none; }
.donut-lbl { font-size: 10.5px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .5px; }
.donut-val { font-size: 18px; font-weight: 700; font-family: var(--font-mono); letter-spacing: -.3px; margin-top: 2px; color: var(--text-1); }
.legend { margin-top: 16px; display: flex; flex-direction: column; gap: 7px; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-2); }
.legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.legend-lbl { flex: 1; }
.legend-val { font-family: var(--font-mono); font-size: 12px; color: var(--text-2); }
.legend-pct { font-size: 11px; color: var(--text-3); width: 36px; text-align: right; }

/* ── TRANSACTION TABLE ── */
.tx-table { width: 100%; border-collapse: collapse; }
.tx-table th {
    text-align: left; font-size: 10px; font-weight: 700;
    color: var(--text-3); text-transform: uppercase; letter-spacing: .8px;
    padding: 0 0 12px; border-bottom: 1px solid var(--line);
}
.tx-table td { padding: 12px 0; border-bottom: 1px solid var(--line-2); transition: background .12s; }
.tx-table tr:last-child td { border-bottom: none; }
.tx-table tbody tr:hover td { background: var(--surface-2); }
.tx-cat-icon {
    width: 34px; height: 34px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.tx-name { font-size: 13px; font-weight: 600; color: var(--text-1); }
.tx-desc { font-size: 11px; color: var(--text-2); margin-top: 1px; }
.tx-amount { font-family: var(--font-mono); font-size: 13px; font-weight: 700; }
.tx-amount.expense { color: var(--red); }
.tx-amount.income  { color: var(--green); }
.tx-date { font-size: 11px; color: var(--text-2); font-family: var(--font-mono); }
.tx-date-today     { color: var(--cyan);  font-weight: 600; }
.tx-date-yesterday { color: var(--amber); font-weight: 500; }
.tag {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    font-size: 10.5px; font-weight: 600;
    background: var(--surface-2); color: var(--text-2);
    border: 1px solid var(--line);
}
.view-all {
    font-size: 12px; font-weight: 600; color: var(--cyan);
    text-decoration: none; transition: opacity .15s; display: inline-flex; align-items: center; gap: 3px;
}
.view-all:hover { opacity: .7; }

/* ── INSIGHTS ── */
.insights { display: flex; flex-direction: column; gap: 10px; }
.insight-item {
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: var(--r); padding: 13px 15px;
    transition: border-color .15s;
}
.insight-item:hover { border-color: rgba(79,70,229,.25); }
.insight-label { font-size: 10.5px; color: var(--text-2); font-weight: 600; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 5px; }
.insight-val { font-size: 19px; font-weight: 700; font-family: var(--font-mono); letter-spacing: -.3px; color: var(--text-1); }
.insight-sub { font-size: 11px; color: var(--text-2); margin-top: 2px; }
.progress-bar { height: 4px; background: var(--line); border-radius: 99px; margin-top: 10px; overflow: hidden; }
.progress-fill {
    height: 100%; border-radius: 99px;
    transition: width .7s ease;
    background: linear-gradient(90deg, #FF6B35, #E8356D);
}

/* ── CATEGORY ICON COLORS ── */
.cat-food          { background: rgba(217,119,6,.1);  color: #B45309; }
.cat-transport     { background: rgba(79,70,229,.1);  color: #4F46E5; }
.cat-utilities     { background: rgba(5,150,105,.1);  color: #059669; }
.cat-entertainment { background: rgba(124,58,237,.1); color: #7C3AED; }
.cat-shopping      { background: rgba(225,29,72,.1);  color: #E11D48; }
.cat-health        { background: rgba(5,150,105,.1);  color: #059669; }
.cat-other         { background: var(--surface-3);    color: var(--text-2); }
.cat-income        { background: rgba(5,150,105,.1);  color: #059669; }

/* ── MODAL ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,15,40,.4); backdrop-filter: blur(8px);
    z-index: 200; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-xl); padding: 30px;
    width: 480px; max-width: 95vw;
    box-shadow: var(--shadow-lg);
    animation: slideUp .2s cubic-bezier(.4,0,.2,1);
}
.modal-hd { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
.modal-title { font-size: 17px; font-weight: 700; font-family: var(--font-head); color: var(--text-1); }
.modal-sub { font-size: 12px; color: var(--text-2); margin-top: 3px; }
.modal-close {
    width: 30px; height: 30px; border-radius: 8px;
    background: var(--surface-2); border: 1px solid var(--line);
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--text-2); transition: all .15s;
}
.modal-close:hover { background: var(--surface-3); color: var(--text-1); }
.modal-actions { display: flex; gap: 10px; margin-top: 22px; }
.modal-actions .btn { flex: 1; justify-content: center; padding: 10px; }

/* ── FORMS ── */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.field { margin-bottom: 14px; }
.field label {
    display: block; font-size: 11px; font-weight: 700;
    color: var(--text-2); text-transform: uppercase;
    letter-spacing: .6px; margin-bottom: 6px;
}
.field input, .field select, .field textarea {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid var(--line);
    border-radius: var(--r);
    background: var(--surface);
    color: var(--text-1); font-size: 13.5px; font-family: var(--font);
    outline: none; transition: border-color .15s, box-shadow .15s;
}
.field input:hover, .field select:hover { border-color: rgba(30,27,75,.2); }
.field input:focus, .field select:focus, .field textarea:focus {
    border-color: #FF6B35;
    box-shadow: 0 0 0 3px rgba(255,107,53,.1);
}
.field input::placeholder { color: var(--text-3); opacity: 1; }
.field select option { background: var(--surface); color: var(--text-1); }
.type-toggle { display: flex; gap: 8px; }
.type-btn {
    flex: 1; padding: 9px; border-radius: var(--r);
    border: 1.5px solid var(--line);
    background: transparent; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .15s;
    color: var(--text-2); font-family: var(--font);
}
.type-btn.selected-expense { border-color: var(--red);   background: var(--red-dim);   color: var(--red);   }
.type-btn.selected-income  { border-color: var(--green); background: var(--green-dim); color: var(--green); }

/* ── BADGE / PILL ── */
.badge { display: inline-flex; align-items: center; gap: 2px; padding: 2px 8px; border-radius: 20px; font-size: 10.5px; font-weight: 700; }
.pill {
    display: inline-flex; align-items: center; gap: 2px;
    padding: 2px 7px; border-radius: 20px;
    font-size: 10.5px; font-weight: 700;
}
.pill-up      { background: var(--red-dim);   color: var(--red);   }
.pill-down    { background: var(--green-dim); color: var(--green); }
.pill-neutral { background: var(--surface-2); color: var(--text-2); }

/* ── TOAST ── */
.toast {
    position: fixed; bottom: 24px; right: 24px;
    background: var(--surface);
    border: 1px solid var(--line);
    color: var(--text-1); padding: 12px 18px;
    border-radius: 12px; font-size: 13px; font-weight: 500;
    box-shadow: var(--shadow-lg); z-index: 999;
    transform: translateY(12px); opacity: 0;
    transition: all .25s cubic-bezier(.4,0,.2,1); pointer-events: none;
}
.toast.show { opacity: 1; transform: translateY(0); }
.toast.success { border-left: 3px solid var(--green); }
.toast.error   { border-left: 3px solid var(--red);   }

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(30,27,75,.12); border-radius: 20px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,107,53,.3); }

/* ── RESPONSIVE ── */
@media (max-width: 1100px) {
    .charts-row { grid-template-columns: 1fr; }
    .bottom-row { grid-template-columns: 1fr; }
    .stats-row { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .sidebar { display: none; }
    .main { margin-left: 0; }
    .content { padding: 20px 16px 40px; }
    .topbar { padding: 0 16px; }
    .stats-row { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) { .stats-row { grid-template-columns: 1fr; } }
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
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar">
                <svg id="toggleIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Overview</div>
            <a href="#" class="nav-item active" title="Dashboard">
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
            <div class="nav-section-label" style="margin-top:16px;">Account</div>
            <a href="/finance_system/pages/settings.php" class="nav-item" title="Settings">
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
                <div class="user-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></div>
                <div class="nav-label">
                    <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                    <div style="font-size:10px;color:#FF6B35;font-weight:700;letter-spacing:.5px;">ADMINISTRATOR</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="btn btn-ghost topbar-menu-btn" id="topbarMenuBtn" title="Toggle sidebar">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    Menu
                </button>
                <div class="breadcrumb">
                    <span>Home</span>
                    <span class="breadcrumb-sep">›</span>
                    <span class="breadcrumb-current">Dashboard</span>
                </div>
            </div>
            <div class="topbar-right">
                <span class="topbar-date"><?php echo date('D, d M Y'); ?></span>
                
                <button class="btn btn-primary" onclick="openModal()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Transaction
                </button>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <div class="page-header">
                <h1 class="page-title">Financial Overview</h1>
                <p class="page-sub">All-time summary · <?php echo $txCount; ?> transactions recorded</p>
            </div>

            <!-- STATS -->
            <div class="stats-row">
                <div class="stat anim anim-d1">
                    <div class="stat-top">
                        <div class="stat-lbl">Total Expenses</div>
                        <div class="stat-ico ico-red">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                        </div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($totalExpense); ?></div>
                    <div class="stat-meta"><span class="pill pill-up">↑ 12.5%</span> vs last month</div>
                </div>

                <div class="stat anim anim-d2">
                    <div class="stat-top">
                        <div class="stat-lbl">Total Income</div>
                        <div class="stat-ico ico-green">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1"/></svg>
                        </div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($totalIncome); ?></div>
                    <div class="stat-meta"><span class="pill pill-neutral">All time</span></div>
                </div>

                <div class="stat anim anim-d3">
                    <div class="stat-top">
                        <div class="stat-lbl">Net Balance</div>
                        <div class="stat-ico ico-blue">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
                        </div>
                    </div>
                    <div class="stat-val" style="color: <?php echo $netBalance >= 0 ? 'var(--green)' : 'var(--red)'; ?>">
                        <?php echo ($netBalance >= 0 ? '+' : ''); ?>KES <?php echo number_format($netBalance); ?>
                    </div>
                    <div class="stat-meta">
                        <?php echo $netBalance >= 0
                            ? '<span class="pill pill-down">Positive</span>'
                            : '<span class="pill pill-up">Deficit</span>'; ?>
                        net position
                    </div>
                </div>

                <div class="stat anim anim-d4">
                    <div class="stat-top">
                        <div class="stat-lbl">Monthly Avg</div>
                        <div class="stat-ico ico-amber">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        </div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($monthlyAvg); ?></div>
                    <div class="stat-meta"><span class="pill pill-down">↓ 5%</span> optimized</div>
                </div>
            </div>

            <!-- BOTTOM ROW -->
            <div class="bottom-row">
                <div class="card anim">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Recent Transactions</div>
                            <div class="card-sub">Last 8 records</div>
                        </div>
                        <a href="../pages/transactions.php" class="view-all">View all →</a>
                    </div>

                    <?php if ($recent && $recent->num_rows > 0): ?>
                    <table class="tx-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Category</th>
                                <th style="text-align:right">Amount</th>
                                <th style="text-align:right">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($tx = $recent->fetch_assoc()):
                            $catKey = strtolower($tx['category']);
                            $catEmojis = [
                                'food'          => '🍽️',
                                'transport'     => '🚌',
                                'utilities'     => '💡',
                                'entertainment' => '🎬',
                                'shopping'      => '🛍️',
                                'health'        => '🏥',
                                'salary'        => '💼',
                                'business'      => '🏢',
                                'investments'   => '📈',
                                'shares'        => '📊',
                                'freelance'     => '💻',
                                'family'        => '👨‍👩‍👧',
                                'rental'        => '🏠',
                                'gift'          => '🎁',
                                'other income'  => '💰',
                                'income'        => '💵',
                                'other'         => '📦',
                            ];
                            $emoji    = $catEmojis[$catKey] ?? '📦';
                            $isIncome = strtolower($tx['type'] ?? 'expense') === 'income';

                            // Human-friendly date
                            $txDate    = date('Y-m-d', strtotime($tx['created_at']));
                            $today     = date('Y-m-d');
                            $yesterday = date('Y-m-d', strtotime('-1 day'));
                            if ($txDate === $today) {
                                $dateLabel    = 'Today';
                                $dateSub      = date('H:i', strtotime($tx['created_at']));
                                $dateLabelCls = 'tx-date-today';
                            } elseif ($txDate === $yesterday) {
                                $dateLabel    = 'Yesterday';
                                $dateSub      = date('d M', strtotime($tx['created_at']));
                                $dateLabelCls = 'tx-date-yesterday';
                            } else {
                                $dateLabel    = date('d M Y', strtotime($tx['created_at']));
                                $dateSub      = date('D', strtotime($tx['created_at']));
                                $dateLabelCls = '';
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="tx-cat-wrap">
                                        <div class="tx-cat-icon cat-<?php echo htmlspecialchars($catKey); ?>"><?php echo $emoji; ?></div>
                                        <div>
                                            <div class="tx-name"><?php echo htmlspecialchars(ucfirst($tx['category'])); ?></div>
                                            <?php if (!empty($tx['description'])): ?>
                                            <div class="tx-desc"><?php echo htmlspecialchars(substr($tx['description'], 0, 30)); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="tag"><?php echo htmlspecialchars(ucfirst($tx['category'])); ?></span></td>
                                <td style="text-align:right">
                                    <span class="tx-amount <?php echo $isIncome ? 'income' : 'expense'; ?>">
                                        <?php echo $isIncome ? '+' : '−'; ?>KES <?php echo number_format($tx['amount']); ?>
                                    </span>
                                </td>
                                <td style="text-align:right">
                                    <span class="tx-date <?php echo $dateLabelCls; ?>"><?php echo $dateLabel; ?></span>
                                    <div class="tx-date-sub"><?php echo $dateSub; ?></div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-ico">💸</div>
                            <div class="empty-txt">No transactions yet.<br>Click <strong>Add Transaction</strong> to get started.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="right-col">
                <div class="card anim">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">By Category</div>
                            <div class="card-sub"><?php echo count($catLabels); ?> categories</div>
                        </div>
                    </div>

                    <?php if (count($catLabels) > 0): ?>
                        <div class="donut-wrap">
                            <canvas id="pieChart" style="max-width:160px; max-height:160px;"></canvas>
                            <div class="donut-center">
                                <div class="donut-lbl" id="donutLabel">Total</div>
                                <div class="donut-val" id="donutVal">KES <?php echo number_format($totalExpense); ?></div>
                            </div>
                        </div>
                        <div class="legend" id="legend"></div>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-ico">📊</div>
                            <div class="empty-txt">No expenses yet.<br>Add your first transaction.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- INSIGHTS -->
                <div class="card anim">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Insights</div>
                            <div class="card-sub">At a glance</div>
                        </div>
                    </div>
                    <div class="insights">
                        <div class="insight-item">
                            <div class="insight-label">Spending Rate</div>
                            <div class="insight-val"><?php echo $spendingRate; ?>%</div>
                            <div class="insight-sub">of total income spent</div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width:<?php echo min(100, $spendingRate); ?>%; background:<?php echo $progressColor; ?>"></div>
                            </div>
                        </div>

                        <div class="insight-item">
                            <div class="insight-label">Top Category</div>
                            <div class="insight-val"><?php echo !empty($catLabels) ? htmlspecialchars($catLabels[0]) : '—'; ?></div>
                            <div class="insight-sub"><?php echo !empty($catData) ? 'KES '.number_format($catData[0]).' spent' : 'No data yet'; ?></div>
                        </div>

                        <div class="insight-item">
                            <div class="insight-label">Active Categories</div>
                            <div class="insight-val"><?php echo count($catLabels); ?></div>
                            <div class="insight-sub">of 7 available</div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width:<?php echo round(count($catLabels) / 7 * 100); ?>%; background:var(--green)"></div>
                            </div>
                        </div>

                        <div class="insight-item">
                            <div class="insight-label">Transactions</div>
                            <div class="insight-val"><?php echo $txCount; ?></div>
                            <div class="insight-sub">total recorded</div>
                        </div>
                    </div>
                </div>
                </div><!-- /right-col -->
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->


<!-- ADD TRANSACTION MODAL -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-hd">
            <div>
                <div class="modal-title">Add Transaction</div>
                <div class="modal-sub">Record a new expense or income</div>
            </div>
            <button class="modal-close" onclick="closeModal()">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form id="txForm">
            <?php
            
            if (!function_exists('csrf_token')) { require_once __DIR__ . '/../core/csrf.php'; }
            echo csrf_field();
            ?>
            <div class="field">
                <label>Type</label>
                <div class="type-toggle">
                    <button type="button" class="type-btn selected-expense" id="btnExpense" onclick="setType('expense')">↓ Expenses</button>
                    <button type="button" class="type-btn" id="btnIncome" onclick="setType('income')">↑ Income</button>
                </div>
                <input type="hidden" name="type" id="typeInput" value="expense">
            </div>

            <div class="form-row">
                <div class="field">
                    <label id="categoryLabel">Category</label>
                    <select name="category" id="categorySelect" required>
                        <option value="">Choose…</option>
                        <optgroup label=" Food &amp; Dining">
                            <option value="Groceries"> Groceries</option>
                            <option value="Household Supplies"> Household Supplies</option>
                            <option value="Eating Out"> Eating Out</option>
                        </optgroup>
                        <optgroup label=" Transport &amp; Auto">
                            <option value="Fuel &amp; Charging"> Fuel &amp; Charging</option>
                            <option value="Vehicle Maintenance"> Vehicle Maintenance</option>
                            <option value="Commuting"> Commuting</option>
                        </optgroup>
                        <optgroup label=" Utilities &amp; Home">
                            <option value="Utilities"> Utilities</option>
                            <option value="Home Decor"> Home Decor</option>
                            <option value="Maintenance &amp; DIY"> Maintenance &amp; DIY</option>
                            <option value="Kitchenware"> Kitchenware</option>
                        </optgroup>
                        <optgroup label=" Health &amp; Wellness">
                            <option value="Health &amp; Pharmacy"> Health &amp; Pharmacy</option>
                            <option value="Personal Grooming"> Personal Grooming</option>
                        </optgroup>
                        <optgroup label=" Shopping &amp; Lifestyle">
                            <option value="Clothing &amp; Accessories"> Clothing &amp; Accessories</option>
                            <option value="Electronics &amp; Tech"> Electronics &amp; Tech</option>
                            <option value="Entertainment"> Entertainment</option>
                        </optgroup>
                        <optgroup label=" Miscellaneous">
                            <option value="Gifts &amp; Celebrations"> Gifts &amp; Celebrations</option>
                            <option value="Pet Supplies"> Pet Supplies</option>
                            <option value="Impulse Buys"> Impulse Buys</option>
                            <option value="Other"> Other</option>
                        </optgroup>
                    </select>
                </div>

                <div class="field">
                    <label>Amount (KES)</label>
                    <input type="number" name="amount" placeholder="0.00" step="0.01" min="1" required>
                </div>
            </div>

            <div class="field">
                <label>Note <span style="font-weight:400;text-transform:none;color:var(--ink-3)">(optional)</span></label>
                <textarea name="description" placeholder="What was this for?"></textarea>
            </div>

            <div class="field">
                <label>Date</label>
                <input type="date" name="created_at" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Save Transaction</button>
            </div>
        </form>
    </div>
</div>


<script>
const allMonths  = <?php echo json_encode($months); ?>;
const allMonthly = <?php echo json_encode($monthly); ?>;
const catLabels  = <?php echo json_encode($catLabels); ?>;
const catData    = <?php echo json_encode($catData); ?>;
const catColors  = <?php echo json_encode($catColors); ?>;
const totalExp   = <?php echo (int) $totalExpense; ?>;

/* MODAL */
function openModal()  { document.getElementById('modal').classList.add('open'); }
function closeModal() { document.getElementById('modal').classList.remove('open'); }
document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

/* CATEGORY OPTIONS */
const expenseCategories = [
    // Food & Dining
    { group: '🍽️ Food & Dining',        value: 'Groceries',               label: ' Groceries'               },
    { group: '🍽️ Food & Dining',        value: 'Household Supplies',       label: ' Household Supplies'       },
    { group: '🍽️ Food & Dining',        value: 'Eating Out',               label: ' Eating Out'               },
    // Transport
    { group: '🚌 Transport & Auto',      value: 'Fuel & Charging',          label: ' Fuel & Charging'          },
    { group: '🚌 Transport & Auto',      value: 'Vehicle Maintenance',      label: ' Vehicle Maintenance'      },
    { group: '🚌 Transport & Auto',      value: 'Commuting',                label: ' Commuting'                },
    // Utilities & Home
    { group: '💡 Utilities & Home',      value: 'Utilities',                label: ' Utilities'                },
    { group: '💡 Utilities & Home',      value: 'Home Decor',               label: ' Home Decor'              },
    { group: '💡 Utilities & Home',      value: 'Maintenance & DIY',        label: ' Maintenance & DIY'        },
    { group: '💡 Utilities & Home',      value: 'Kitchenware',              label: ' Kitchenware'              },
    // Health
    { group: '🏥 Health & Wellness',     value: 'Health & Pharmacy',        label: ' Health & Pharmacy'        },
    { group: '🏥 Health & Wellness',     value: 'Personal Grooming',        label: ' Personal Grooming'        },
    // Shopping
    { group: '🛍️ Shopping & Lifestyle', value: 'Clothing & Accessories',   label: ' Clothing & Accessories'   },
    { group: '🛍️ Shopping & Lifestyle', value: 'Electronics & Tech',       label: ' Electronics & Tech'       },
    { group: '🛍️ Shopping & Lifestyle', value: 'Entertainment',            label: ' Entertainment'            },
    // Misc
    { group: '🎁 Miscellaneous',         value: 'Gifts & Celebrations',     label: ' Gifts & Celebrations'     },
    { group: '🎁 Miscellaneous',         value: 'Pet Supplies',             label: ' Pet Supplies'             },
    { group: '🎁 Miscellaneous',         value: 'Impulse Buys',             label: ' Impulse Buys'             },
    { group: '🎁 Miscellaneous',         value: 'Other',                    label: ' Other'                    },
];

const incomeCategories = [
    { value: 'Salary',        label: ' Salary'           },
    { value: 'Business',      label: ' Business'         },
    { value: 'Investments',   label: ' Investments'      },
    { value: 'Shares',        label: ' Shares/Dividends' },
    { value: 'Freelance',     label: ' Freelance'        },
    { value: 'Family',        label: ' Family & Friends' },
    { value: 'Rental',        label: ' Rental Income'    },
    { value: 'Gift',          label: ' Gift'             },
    { value: 'Other Income',  label: ' Other Income'     },
];

function populateCategories(type) {
    const sel  = document.getElementById('categorySelect');
    const lbl  = document.getElementById('categoryLabel');
    lbl.textContent = type === 'income' ? 'Income Source' : 'Category';
    sel.innerHTML = '<option value="">Choose…</option>';

    if (type === 'income') {
        incomeCategories.forEach(opt => {
            const o = document.createElement('option');
            o.value = opt.value; o.textContent = opt.label;
            sel.appendChild(o);
        });
    } else {
        // Build optgroups for expense categories
        const groups = {};
        expenseCategories.forEach(opt => {
            if (!groups[opt.group]) groups[opt.group] = [];
            groups[opt.group].push(opt);
        });
        Object.entries(groups).forEach(([groupName, opts]) => {
            const og = document.createElement('optgroup');
            og.label = groupName;
            opts.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value; o.textContent = opt.label;
                og.appendChild(o);
            });
            sel.appendChild(og);
        });
    }
}

/* TYPE TOGGLE */
function setType(type) {
    document.getElementById('typeInput').value = type;
    document.getElementById('btnExpense').className = 'type-btn' + (type === 'expense' ? ' selected-expense' : '');
    document.getElementById('btnIncome').className  = 'type-btn' + (type === 'income'  ? ' selected-income'  : '');
    populateCategories(type);
}

/* VALIDATION */
function validateDashboardTxForm() {
    const type     = document.getElementById('typeInput').value;
    const category = document.getElementById('categorySelect').value;
    const amountEl = document.querySelector('#txForm input[name="amount"]');
    const dateEl   = document.querySelector('#txForm input[name="created_at"]');
    const amount   = parseFloat(amountEl.value);
    const todayStr = new Date().toISOString().split('T')[0];

    if (!['expense', 'income'].includes(type)) return 'Please choose a transaction type.';
    if (!category) return 'Please choose a category.';
    if (isNaN(amount) || amount <= 0) return 'Amount must be a number greater than 0.';
    if (amount > 100000000) return 'That amount looks too large — please double-check it.';
    if (!dateEl.value || !/^\d{4}-\d{2}-\d{2}$/.test(dateEl.value)) return 'Please choose a valid date.';
    if (dateEl.value > todayStr) return 'Date cannot be in the future.';
    return null;
}

/* FORM SUBMIT */
document.getElementById('txForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const validationError = validateDashboardTxForm();
    if (validationError) {
        showToast(validationError, 'error');
        return;
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('../actions/add_transaction.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.text())
        .then(text => {
            let d;
            try { d = JSON.parse(text); } catch(e) {
                console.error('Non-JSON response:', text);
                showToast('Server error — check console (F12)', 'error');
                btn.disabled = false;
                btn.textContent = 'Save Transaction';
                return;
            }
            if (d.success) {
                showToast('Transaction saved!', 'success');
                closeModal();
                this.reset();
                setType('expense'); // reset type toggle visually
                // Refresh CSRF token so the form can be used again without reload
                if (d.csrf_token) {
                    const csrfEl = document.querySelector('#txForm input[name="csrf_token"]');
                    if (csrfEl) csrfEl.value = d.csrf_token;
                }
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(d.message || 'Error saving transaction', 'error');
                btn.disabled = false;
                btn.textContent = 'Save Transaction';
            }
        })
        .catch((err) => {
            showToast('Network error — please try again', 'error');
            console.error('Transaction save error:', err);
            btn.disabled = false;
            btn.textContent = 'Save Transaction';
        });
});

/* TOAST */
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3200);
}

/* CHARTS */
Chart.defaults.font.family = "'DM Sans', sans-serif";

let lineChart;
const lineCtx = document.getElementById('lineChart').getContext('2d');

function buildLineChart(range) {
    const labs = allMonths.slice(-range);
    const data = allMonthly.slice(-range);
    if (lineChart) lineChart.destroy();

    lineChart = new Chart(lineCtx, {
        type: 'bar',
        data: {
            labels: labs,
            datasets: [
                {
                    type: 'line',
                    data: data,
                    borderColor: '#FF6B35',
                    backgroundColor: 'rgba(255,107,53,.08)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.45,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#FF6B35',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    order: 0
                },
                {
                    type: 'bar',
                    data: data,
                    backgroundColor: 'rgba(255,107,53,.1)',
                    borderRadius: 4,
                    borderSkipped: false,
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#FFFFFF',
                    padding: 12,
                    titleColor: '#0F0F28',
                    bodyColor: 'rgba(15,15,40,.55)',
                    borderColor: 'rgba(30,27,75,.1)',
                    borderWidth: 1,
                    displayColors: false,
                    callbacks: { label: ctx => 'KES ' + ctx.parsed.y.toLocaleString() }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(30,27,75,.06)', drawBorder: false },
                    ticks: { color: 'rgba(15,15,40,.5)', font: { size: 11 }, callback: v => 'KES ' + v.toLocaleString() }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: 'rgba(15,15,40,.5)', font: { size: 11 } }
                }
            }
        }
    });
}

buildLineChart(12);

document.getElementById('rangeToggle').addEventListener('click', function(e) {
    const btn = e.target.closest('.seg-btn');
    if (!btn) return;
    document.querySelectorAll('.seg-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    buildLineChart(parseInt(btn.dataset.range));
});

/* DONUT */
if (catLabels.length > 0) {
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    new Chart(pieCtx, {
        type: 'doughnut',
        data: {
            labels: catLabels,
            datasets: [{
                data: catData,
                backgroundColor: catColors,
                borderColor: '#FFFFFF',
                borderWidth: 3,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#FFFFFF',
                    padding: 12,
                    titleColor: '#0F0F28',
                    bodyColor: 'rgba(15,15,40,.55)',
                    borderColor: 'rgba(30,27,75,.1)',
                    borderWidth: 1,
                    callbacks: { label: ctx => ' KES ' + ctx.parsed.toLocaleString() }
                }
            },
            onHover: (e, el) => {
                if (el.length) {
                    const i = el[0].index;
                    document.getElementById('donutLabel').textContent = catLabels[i];
                    document.getElementById('donutVal').textContent = 'KES ' + catData[i].toLocaleString();
                } else {
                    document.getElementById('donutLabel').textContent = 'Total';
                    document.getElementById('donutVal').textContent = 'KES ' + totalExp.toLocaleString();
                }
            }
        }
    });

    const legend = document.getElementById('legend');
    catLabels.forEach((lbl, i) => {
        const pct = totalExp > 0 ? ((catData[i] / totalExp) * 100).toFixed(1) : '0.0';
        legend.innerHTML += `
            <div class="legend-item">
                <div class="legend-dot" style="background:${catColors[i]}"></div>
                <span class="legend-lbl">${lbl}</span>
                <span class="legend-val">KES ${catData[i].toLocaleString()}</span>
                <span class="legend-pct">${pct}%</span>
            </div>`;
    });
}
/* SIDEBAR TOGGLE */
(function() {
    const layout = document.querySelector('.layout');
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const topbarBtn = document.getElementById('topbarMenuBtn');

    function toggleSidebar() {
        const collapsed = sidebar.classList.toggle('collapsed');
        layout.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    }

    if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
    if (topbarBtn) topbarBtn.addEventListener('click', toggleSidebar);

})();
</script>
</body>
</html>