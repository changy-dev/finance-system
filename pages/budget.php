<?php
session_start();
include '../core/db.php';
require_once '../core/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ── CURRENT MONTH BOUNDS ── */
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$monthLabel = date('F Y');

/* ── ALL CATEGORIES (expense + income sources) ── */
$expenseCategories = ['Food','Transport','Utilities','Entertainment','Shopping','Health','Other'];
$categoryEmojis = [
    'Food'          => '🍽️',
    'Transport'     => '🚌',
    'Utilities'     => '💡',
    'Entertainment' => '🎬',
    'Shopping'      => '🛍️',
    'Health'        => '🏥',
    'Other'         => '📦',
];

/* ── FETCH EXISTING BUDGETS ── */
// Expects a `budgets` table: (budget_id INT PK AI, user_id INT, category VARCHAR, amount DECIMAL, created_at TIMESTAMP)
$budgets = [];
$bRes = $conn->query("SELECT category, amount_limit AS amount, daily_limit, monthly_limit, yearly_limit FROM budgets WHERE user_id = $user_id");
$dailyLimits   = [];
$monthlyLimits = [];
$yearlyLimits  = [];
if ($bRes && $bRes->num_rows > 0) {
    while ($row = $bRes->fetch_assoc()) {
        $budgets[$row['category']] = (float) $row['amount'];
        if ($row['daily_limit']   !== null) $dailyLimits[$row['category']]   = (float)$row['daily_limit'];
        if ($row['monthly_limit'] !== null) $monthlyLimits[$row['category']] = (float)$row['monthly_limit'];
        if ($row['yearly_limit']  !== null) $yearlyLimits[$row['category']]  = (float)$row['yearly_limit'];
    }
}

/* ── FETCH ACTUAL SPENDING THIS MONTH ── */
$spent = [];
$sRes = $conn->query("
    SELECT category, SUM(amount) total
    FROM transactions
    WHERE user_id = $user_id
      AND type = 'expense'
      AND created_at BETWEEN '$monthStart 00:00:00' AND '$monthEnd 23:59:59'
    GROUP BY category
");
if ($sRes && $sRes->num_rows > 0) {
    while ($row = $sRes->fetch_assoc()) {
        $spent[$row['category']] = (float) $row['total'];
    }
}

/* ── SUMMARY STATS ── */
$totalBudgeted = array_sum($budgets);
$totalSpent    = array_sum($spent);
$totalLeft     = $totalBudgeted - $totalSpent;
$overBudgetCount = 0;
$onTrackCount    = 0;
foreach ($expenseCategories as $cat) {
    $b = $budgets[$cat] ?? 0;
    $s = $spent[$cat]   ?? 0;
    if ($b > 0) {
        if ($s > $b) $overBudgetCount++;
        else $onTrackCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Budget — Fintrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

/* ── BASE ── */
body {
    font-family: var(--font);
    background: var(--page);
    color: var(--text-1);
    font-size: 14px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* Animated mesh background */
body::before {
    content: '';
    position: fixed; inset: 0;
    background:
        radial-gradient(ellipse 70% 50% at 10% 0%, rgba(255,107,53,.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(79,70,229,.05) 0%, transparent 55%),
        radial-gradient(ellipse 50% 60% at 50% 50%, rgba(232,53,109,.03) 0%, transparent 70%);
    pointer-events: none; z-index: 0;
}
body > * { position: relative; z-index: 1; }

/* ── LAYOUT ── */
.layout { display: flex; min-height: 100vh; }

/* ── SIDEBAR ── */
.sidebar {
    width: var(--sidebar-w); flex-shrink: 0;
    background: linear-gradient(180deg, #1E1B4B 0%, #16134A 100%);
    border-right: none;
    box-shadow: 4px 0 20px rgba(30,27,75,.12);
    position: fixed; top: 0; left: 0; height: 100vh;
    display: flex; flex-direction: column;
    z-index: 50; overflow: hidden;
    transition: width .25s cubic-bezier(.4,0,.2,1);
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
    box-shadow: 0 4px 14px rgba(255,107,53,.4); flex-shrink: 0;
}
.brand-icon svg { width: 18px; height: 18px; color: #fff; }
.brand-text { overflow: hidden; }
.brand-name { font-size: 15px; font-weight: 700; font-family: var(--font-head); color: #fff; letter-spacing: -.2px; }
.brand-tagline { font-size: 10.5px; color: rgba(255,255,255,.4); margin-top: 1px; }
.sidebar-toggle {
    width: 26px; height: 26px; border-radius: 6px;
    background: rgba(255,255,255,.06); border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-3); transition: background .15s, color .15s, transform .25s; flex-shrink: 0;
}
.sidebar-toggle:hover { background: rgba(255,255,255,.16); color: #fff; }
.sidebar.collapsed .sidebar-toggle { transform: rotate(180deg); }
.sidebar.collapsed .brand-text { opacity: 0; width: 0; pointer-events: none; }
.sidebar.collapsed .sidebar-toggle { margin: 0 auto; }

.sidebar-nav {
    padding: 12px 8px; flex: 1;
    overflow-y: auto; overflow-x: hidden;
    scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.08) transparent;
}
.sidebar-nav::-webkit-scrollbar { width: 3px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 99px; }

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
.nav-label-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.nav-badge {
    margin-left: auto;
    background: rgba(255,107,53,.2); color: #FFB39A;
    font-size: 9.5px; font-weight: 700; padding: 2px 7px;
    border-radius: 10px; font-family: var(--font-mono); flex-shrink: 0;
}
.sidebar.collapsed .nav-label-text { opacity: 0; width: 0; pointer-events: none; }
.sidebar.collapsed .nav-badge { display: none; }
.sidebar.collapsed .nav-item { justify-content: center; padding: 10px; border-left: none; }

.sidebar-footer {
    padding: 10px 8px 14px;
    border-top: 1px solid rgba(255,255,255,.08); flex-shrink: 0;
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
.user-name { font-size: 12.5px; color: rgba(255,255,255,.75); font-weight: 600; }
.user-role { font-size: 10.5px; color: rgba(255,255,255,.3); }
.sidebar.collapsed .user-row { justify-content: center; padding: 8px; }

/* ── MAIN ── */
.main {
    flex: 1; margin-left: var(--sidebar-w);
    display: flex; flex-direction: column; min-height: 100vh;
    transition: margin-left .25s cubic-bezier(.4,0,.2,1);
}
.layout.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }

/* ── TOPBAR ── */
.topbar {
    background: rgba(255,255,255,.92);
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
    background: linear-gradient(135deg, #FF6B35, #E8356D);
    color: #fff; box-shadow: 0 4px 16px rgba(255,107,53,.3);
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(255,107,53,.4); filter: brightness(1.05); }
.btn-primary:active { transform: translateY(0); }
.btn-ghost { background: transparent; color: var(--text-2); border: 1px solid var(--line); }
.btn-ghost:hover { background: var(--surface-2); border-color: rgba(30,27,75,.18); color: var(--text-1); }
.btn-sm { padding: 5px 11px; font-size: 12px; }

/* ── CONTENT ── */
.content { padding: 28px 30px 56px; }
.page-header { margin-bottom: 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.page-title {
    font-size: 24px; font-weight: 700; font-family: var(--font-head);
    letter-spacing: -.4px; line-height: 1.2;
    background: linear-gradient(90deg, #0F0F28 40%, #FF6B35);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.page-sub { font-size: 12.5px; color: var(--text-2); margin-top: 4px; }

/* ── SUMMARY CARDS ── */
.summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
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
/* Glow bloom behind each stat */
.stat:nth-child(1) { --glow-color: rgba(0,212,255,.18); }
.stat:nth-child(2) { --glow-color: rgba(255,77,109,.15); }
.stat:nth-child(3) { --glow-color: rgba(16,245,160,.15); }
.stat:nth-child(4) { --glow-color: rgba(255,214,10,.13); }
.stat::before {
    content: '';
    position: absolute; inset: -20px;
    background: radial-gradient(ellipse at 60% 30%, var(--glow-color, rgba(0,212,255,.12)) 0%, transparent 65%);
    border-radius: 50%;
    opacity: 0; transition: opacity .4s;
    pointer-events: none; z-index: -1; filter: blur(18px);
}
.stat:hover::before { opacity: 1; }

/* Colored top borders per child */
.stat:nth-child(1)::after { background: linear-gradient(90deg, var(--cyan), #7c3aed); }
.stat:nth-child(2)::after { background: linear-gradient(90deg, #ff4d6d, #ff9a8b); }
.stat:nth-child(3)::after { background: linear-gradient(90deg, #4F46E5, #7C3AED); }
.stat:nth-child(4)::after { background: linear-gradient(90deg, #ffd60a, #ff9500); }

/* ── CARD GLOW ── */
.card {
    position: relative;
    transition: border-color .25s, box-shadow .25s;
}
.card::after {
    content: '';
    position: absolute;
    inset: -1px;
    border-radius: inherit;
    padding: 1px;
    background: linear-gradient(135deg, rgba(0,212,255,.2) 0%, rgba(124,58,237,.14) 50%, rgba(255,94,98,.08) 100%);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
    opacity: 0;
    transition: opacity .3s;
    z-index: 0;
}
.card:hover::after { opacity: 1; }
.card:hover {
    border-color: rgba(0,212,255,.15);
    box-shadow: 0 0 40px rgba(0,212,255,.07), var(--shadow-md);
}

.stat-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
.stat-lbl {
    font-size: 10.5px; font-weight: 700; color: var(--text-3);
    text-transform: uppercase; letter-spacing: .8px;
}
.stat-ico {
    width: 34px; height: 34px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
}
.stat-ico svg { width: 16px; height: 16px; }
.ico-blue   { background: rgba(0,212,255,.15);   color: var(--cyan); }
.ico-green  { background: rgba(16,245,160,.12);  color: var(--green); }
.ico-red    { background: rgba(255,77,109,.12);  color: var(--red); }
.ico-amber  { background: rgba(255,214,10,.12);  color: var(--amber); }
.stat-val { font-size: 24px; font-weight: 700; letter-spacing: -.6px; font-family: var(--font-mono); line-height: 1; margin-bottom: 8px; color: var(--text-1); }
.stat-meta { font-size: 11.5px; color: var(--text-3); }
.pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 700; }
.pill-green  { background: var(--green-dim);  color: var(--green); }
.pill-red    { background: var(--red-dim);    color: var(--red); }
.pill-amber  { background: var(--amber-dim);  color: var(--amber); }
.pill-neutral { background: var(--surface-2); color: var(--text-2); }

/* ── MAIN GRID ── */
.grid-2 { display: grid; grid-template-columns: 1fr 320px; gap: 16px; align-items: start; }

/* ── CARD ── */
.card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r-lg); padding: 22px 24px;
    box-shadow: var(--shadow);
}
.card-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.card-title { font-size: 14px; font-weight: 600; letter-spacing: -.2px; color: var(--text-1); font-family: var(--font-head); }
.card-sub { font-size: 12px; color: var(--text-3); margin-top: 1px; }

/* ── BUDGET ROWS ── */
.budget-list { display: flex; flex-direction: column; gap: 0; }

.budget-row {
    display: grid;
    grid-template-columns: 200px 1fr 130px 100px;
    align-items: center; gap: 16px;
    padding: 14px 0;
    border-bottom: 1px solid rgba(255,255,255,.08);
    transition: background .15s;
}
.budget-row:last-child { border-bottom: none; }
.budget-row:hover { background: var(--surface-2); margin: 0 -24px; padding: 14px 24px; border-radius: 8px; }

.bcat { display: flex; align-items: center; gap: 10px; }
.bcat-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.bcat-name { font-size: 13.5px; font-weight: 500; color: var(--text-1); }
.bcat-spent { font-size: 11.5px; color: var(--text-3); }

/* PROGRESS */
.bprogress { display: flex; flex-direction: column; gap: 5px; }
.bprogress-track { height: 6px; background: var(--surface-3); border-radius: 99px; overflow: hidden; }
.bprogress-fill { height: 100%; border-radius: 99px; transition: width .6s cubic-bezier(.4,0,.2,1); }
.bprogress-meta { display: flex; justify-content: space-between; font-size: 10.5px; color: var(--text-3); font-family: var(--font-mono); }

/* BUDGET INPUT */
.budget-input-wrap { position: relative; }
.budget-input-wrap .currency { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text-3); font-family: var(--font-mono); pointer-events: none; }
.budget-input {
    width: 100%; padding: 7px 10px 7px 34px;
    border: 1.5px solid rgba(255,255,255,.09); border-radius: var(--r);
    font-size: 13px; font-family: var(--font-mono);
    color: var(--text-1); background: rgba(255,255,255,.04);
    transition: border .15s, box-shadow .15s;
}
.budget-input:focus { outline: none; border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(0,212,255,.1); }
.budget-input:hover { border-color: rgba(30,27,75,.2); }
.budget-input.over { border-color: var(--red); }

/* STATUS BADGE */
.status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.status-ok      { background: var(--green-dim); color: var(--green); }
.status-warning { background: var(--amber-dim); color: var(--amber); }
.status-over    { background: var(--red-dim);   color: var(--red); }
.status-unset   { background: var(--surface-2); color: var(--text-3); }

/* ── SAVE BAR ── */
.save-bar { display: flex; align-items: center; justify-content: space-between; padding: 14px 0 0; margin-top: 8px; border-top: 1px solid var(--line); }
.save-hint { font-size: 12px; color: var(--text-3); }

/* ── DONUT PANEL ── */
.donut-wrap { position: relative; display: flex; align-items: center; justify-content: center; height: 190px; margin-bottom: 20px; }
.donut-center { position: absolute; text-align: center; pointer-events: none; }
.donut-lbl { font-size: 11px; color: var(--text-3); font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
.donut-val { font-size: 20px; font-weight: 700; font-family: var(--font-mono); letter-spacing: -.4px; margin-top: 2px; color: var(--text-1); }

/* ── LEGEND ── */
.legend { display: flex; flex-direction: column; gap: 8px; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; }
.legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.legend-lbl { flex: 1; color: var(--text-2); }
.legend-pct { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-3); }

/* ── TIPS PANEL ── */
.tips { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
.tip-item { display: flex; gap: 10px; padding: 12px; background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.06); border-radius: 9px; }
.tip-icon { font-size: 18px; flex-shrink: 0; line-height: 1.2; }
.tip-text { font-size: 12px; color: var(--text-2); line-height: 1.5; }
.tip-text strong { color: var(--text-1); }

/* ── EMPTY STATE ── */
.empty { text-align: center; padding: 32px 20px; color: var(--text-3); }
.empty-ico { font-size: 30px; margin-bottom: 8px; }
.empty-txt { font-size: 13px; }

/* ── TOAST ── */
.toast {
    position: fixed; bottom: 24px; right: 24px;
    background: var(--surface-2); border: 1px solid rgba(255,255,255,.1);
    color: var(--text-1); padding: 12px 18px;
    border-radius: 12px; font-size: 13px; font-weight: 500;
    box-shadow: var(--shadow-lg); z-index: 999;
    transform: translateY(12px); opacity: 0;
    transition: all .25s cubic-bezier(.4,0,.2,1); pointer-events: none;
}
.toast.show { opacity: 1; transform: translateY(0); }
.toast.success { border-left: 3px solid var(--green); }
.toast.error   { border-left: 3px solid var(--red); }

/* ── PERIOD LIMITS ── */
.period-tab {
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.09);
    color: var(--text-2); font-size: 12px; font-weight: 600;
    padding: 5px 14px; border-radius: 6px; cursor: pointer; transition: all .15s;
    font-family: var(--font);
}
.period-tab.active { background: var(--cyan-dim); color: var(--cyan); border-color: rgba(0,212,255,.3); }
.period-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.period-cat-lbl { font-size: 13px; color: var(--text-2); min-width: 110px; }
.period-input-group { display: flex; align-items: center; gap: 4px; }

/* ── OVER-BUDGET ALERT CARD ── */
.ob-alert { background: var(--surface-2); border: 1.5px solid var(--red); border-radius: 10px; padding: 12px 16px; box-shadow: 0 4px 18px rgba(255,77,109,.2); animation: slideUp .25s ease; }
.ob-alert-title { font-size: 13px; font-weight: 600; color: var(--red); margin-bottom: 4px; }
.ob-alert-body  { font-size: 12px; color: var(--text-2); line-height: 1.5; }
.ob-alert-close { float: right; background: none; border: none; cursor: pointer; font-size: 16px; color: var(--text-3); line-height: 1; margin-left: 8px; }

/* ── ANIMATIONS ── */
@keyframes slideUp { from { opacity: 0; transform: translateY(20px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes fadeIn  { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.anim    { animation: fadeIn .4s cubic-bezier(.4,0,.2,1) both; }
.anim-d1 { animation-delay: .07s; }
.anim-d2 { animation-delay: .14s; }
.anim-d3 { animation-delay: .21s; }
.anim-d4 { animation-delay: .28s; }

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 20px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,107,53,.3); }

/* ── RESPONSIVE ── */
@media (max-width: 1100px) { .grid-2 { grid-template-columns: 1fr; } .summary-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 900px)  { .budget-row { grid-template-columns: 1fr 1fr; row-gap: 10px; } .bprogress { grid-column: 1/-1; } }
@media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0; } .content { padding: 20px 16px 40px; } .topbar { padding: 0 16px; } .summary-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px)  { .summary-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<div class="layout">

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar" id="sidebar">
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
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Overview</div>
            <a href="../pages/dashboard.php" class="nav-item" title="Dashboard">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
                <span class="nav-label-text">Dashboard</span>
                <span class="nav-badge">Live</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Money</div>
            <a href="../pages/transactions.php" class="nav-item" title="Transactions">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span class="nav-label-text">Transactions</span>
            </a>
            <a href="../pages/budget.php" class="nav-item active" title="Budget">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                <span class="nav-label-text">Budget</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Account</div>
            <a href="../pages/settings.php" class="nav-item" title="Settings">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="nav-label-text">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">U</div>
                <div class="nav-label-text">
                    <div class="user-name">My Account</div>
                    <div class="user-role">Personal plan</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- ── MAIN ── -->
    <div class="main" id="mainContent">

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="btn btn-ghost btn-sm" id="topbarMenuBtn" style="padding:6px 10px;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="breadcrumb">
                    <span>Home</span>
                    <span class="breadcrumb-sep">›</span>
                    <a href="../pages/dashboard.php" style="color:var(--text-2);text-decoration:none;">Dashboard</a>
                    <span class="breadcrumb-sep">›</span>
                    <span class="breadcrumb-current">Budget</span>
                </div>
            </div>
            <div class="topbar-right">
                <span class="topbar-date"><?php echo $monthLabel; ?></span>
                <input type="hidden" id="csrf_token_budget" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button class="btn btn-primary" onclick="saveAll()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Save Budgets
                </button>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Budget Planner</h1>
                    <p class="page-sub">Set monthly limits per category and track your spending in real time</p>
                </div>
            </div>

            <!-- SUMMARY STATS -->
            <div class="summary-row">
                <div class="stat anim anim-d1">
                    <div class="stat-top">
                        <div class="stat-lbl">Total Budgeted</div>
                        <div class="stat-ico ico-blue">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                    </div>
                    <div class="stat-val" id="statBudgeted">KES <?php echo number_format($totalBudgeted); ?></div>
                    <div class="stat-meta">planned for <?php echo $monthLabel; ?></div>
                </div>

                <div class="stat anim anim-d2">
                    <div class="stat-top">
                        <div class="stat-lbl">Total Spent</div>
                        <div class="stat-ico ico-red">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                        </div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($totalSpent); ?></div>
                    <div class="stat-meta">this month so far</div>
                </div>

                <div class="stat anim anim-d3">
                    <div class="stat-top">
                        <div class="stat-lbl">Remaining</div>
                        <div class="stat-ico <?php echo $totalLeft >= 0 ? 'ico-green' : 'ico-red'; ?>">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1"/></svg>
                        </div>
                    </div>
                    <div class="stat-val" style="color:<?php echo $totalLeft >= 0 ? 'var(--green)' : 'var(--red)'; ?>">
                        <?php echo $totalLeft >= 0 ? '' : '−'; ?>KES <?php echo number_format(abs($totalLeft)); ?>
                    </div>
                    <div class="stat-meta">
                        <span class="pill <?php echo $totalLeft >= 0 ? 'pill-green' : 'pill-red'; ?>">
                            <?php echo $totalLeft >= 0 ? '✓ Under budget' : '⚠ Over budget'; ?>
                        </span>
                    </div>
                </div>

                <div class="stat anim anim-d4">
                    <div class="stat-top">
                        <div class="stat-lbl">Categories</div>
                        <div class="stat-ico ico-amber">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        </div>
                    </div>
                    <div class="stat-val"><?php echo $onTrackCount; ?> / <?php echo count($expenseCategories); ?></div>
                    <div class="stat-meta">
                        <?php if ($overBudgetCount > 0): ?>
                            <span class="pill pill-red"><?php echo $overBudgetCount; ?> over limit</span>
                        <?php else: ?>
                            <span class="pill pill-green">All on track</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- MAIN GRID -->
            <div class="grid-2">

                <!-- LEFT: BUDGET TABLE -->
                <div class="card anim">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Monthly Limits</div>
                            <div class="card-sub">Set a spending cap for each category</div>
                        </div>
                        <button class="btn btn-ghost btn-sm" onclick="resetAll()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Reset
                        </button>
                    </div>

                    <div class="budget-list">
                    <?php
                    $colors = [
                        'Food'          => '#f59e0b',
                        'Transport'     => '#4F46E5',
                        'Utilities'     => '#059669',
                        'Entertainment' => '#a855f7',
                        'Shopping'      => '#f43f5e',
                        'Health'        => '#059669',
                        'Other'         => '#6366f1',
                    ];
                    $bgColors = [
                        'Food'          => '#fff7ed',
                        'Transport'     => '#eff6ff',
                        'Utilities'     => '#f0fdf4',
                        'Entertainment' => '#fdf4ff',
                        'Shopping'      => '#fff1f2',
                        'Health'        => '#ecfdf5',
                        'Other'         => '#f8fafc',
                    ];

                    foreach ($expenseCategories as $cat):
                        $budget  = $budgets[$cat] ?? 0;
                        $s       = $spent[$cat]   ?? 0;
                        $pct     = $budget > 0 ? min(100, round($s / $budget * 100)) : 0;
                        $left    = $budget - $s;
                        $color   = $colors[$cat]   ?? '#6366f1';
                        $bg      = $bgColors[$cat]  ?? '#f8fafc';
                        $emoji   = $categoryEmojis[$cat] ?? '📦';

                        if ($budget == 0) {
                            $statusClass = 'status-unset';
                            $statusText  = 'No limit set';
                        } elseif ($s > $budget) {
                            $statusClass = 'status-over';
                            $statusText  = '⚠ Over by KES ' . number_format($s - $budget);
                        } elseif ($pct >= 80) {
                            $statusClass = 'status-warning';
                            $statusText  = '⚡ ' . $pct . '% used';
                        } else {
                            $statusClass = 'status-ok';
                            $statusText  = '✓ KES ' . number_format($left) . ' left';
                        }

                        $barColor = $s > $budget && $budget > 0 ? 'var(--red)'
                                  : ($pct >= 80 ? 'var(--amber)' : $color);
                    ?>
                        <div class="budget-row" data-category="<?php echo $cat; ?>">
                            <!-- Category info -->
                            <div class="bcat">
                                <div class="bcat-icon" style="background:<?php echo $bg; ?>"><?php echo $emoji; ?></div>
                                <div>
                                    <div class="bcat-name"><?php echo $cat; ?></div>
                                    <div class="bcat-spent">KES <?php echo number_format($s); ?> spent</div>
                                </div>
                            </div>

                            <!-- Progress bar -->
                            <div class="bprogress">
                                <div class="bprogress-track">
                                    <div class="bprogress-fill"
                                         id="bar-<?php echo $cat; ?>"
                                         style="width:<?php echo $pct; ?>%; background:<?php echo $barColor; ?>">
                                    </div>
                                </div>
                                <div class="bprogress-meta">
                                    <span id="meta-left-<?php echo $cat; ?>"><?php echo $pct; ?>% used</span>
                                    <span><?php echo $budget > 0 ? 'of KES '.number_format($budget) : 'Set a limit →'; ?></span>
                                </div>
                            </div>

                            <!-- Budget input -->
                            <div class="budget-input-wrap">
                                <span class="currency">KES</span>
                                <input
                                    type="number"
                                    class="budget-input <?php echo ($s > $budget && $budget > 0) ? 'over' : ''; ?>"
                                    id="input-<?php echo $cat; ?>"
                                    data-category="<?php echo $cat; ?>"
                                    data-spent="<?php echo $s; ?>"
                                    value="<?php echo $budget > 0 ? (int)$budget : ''; ?>"
                                    placeholder="0"
                                    min="0"
                                    step="100"
                                    oninput="updateRow('<?php echo $cat; ?>')"
                                >
                            </div>

                            <!-- Status -->
                            <span class="status-badge <?php echo $statusClass; ?>" id="status-<?php echo $cat; ?>">
                                <?php echo $statusText; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <div class="save-bar">
                        <span class="save-hint">Changes are saved when you click <strong>Save Budgets</strong></span>
                        <button class="btn btn-primary" onclick="saveAll()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Save Budgets
                        </button>
                    </div>
                </div>

                <!-- RIGHT PANEL -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Donut chart -->
                    <div class="card anim">
                        <div class="card-hd">
                            <div>
                                <div class="card-title">Budget Allocation</div>
                                <div class="card-sub">How your budget is split</div>
                            </div>
                        </div>

                        <?php if ($totalBudgeted > 0): ?>
                        <div class="donut-wrap">
                            <canvas id="donutChart" style="max-width:160px;max-height:160px;"></canvas>
                            <div class="donut-center">
                                <div class="donut-lbl">Budget</div>
                                <div class="donut-val">KES <?php echo number_format($totalBudgeted); ?></div>
                            </div>
                        </div>
                        <div class="legend" id="donutLegend"></div>
                        <?php else: ?>
                        <div class="empty">
                            <div class="empty-ico">🎯</div>
                            <div class="empty-txt">Set budgets on the left<br>to see your allocation chart.</div>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <!-- ══ PERIOD LIMITS — Full-width section below the grid ══ -->
            <div class="card anim" style="margin-top:20px;border:1px solid rgba(0,212,255,.2);box-shadow:0 0 0 4px rgba(0,212,255,.04);">
                <div class="card-hd" style="padding-bottom:14px;border-bottom:1px solid rgba(255,255,255,.06);margin-bottom:4px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;background:var(--cyan-dim);border:1px solid rgba(0,212,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">⏱</div>
                        <div>
                            <div class="card-title" style="font-size:15px;">Spending Period Limits</div>
                            <div class="card-sub">Cap how much you spend daily, monthly or yearly per category — saved with your budgets</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <button class="period-tab active" data-period="daily"   onclick="switchPeriodTab('daily')">Daily</button>
                        <button class="period-tab"        data-period="monthly" onclick="switchPeriodTab('monthly')">Monthly</button>
                        <button class="period-tab"        data-period="yearly"  onclick="switchPeriodTab('yearly')">Yearly</button>
                    </div>
                </div>
                <div id="period-inputs" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;padding-top:10px;">
                <?php foreach ($expenseCategories as $cat):
                    $emoji  = $categoryEmojis[$cat] ?? '📦';
                    $bg     = $bgColors[$cat] ?? '#f8fafc';
                    $color  = $colors[$cat]   ?? '#6366f1';
                ?>
                    <div class="period-row" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;background:<?php echo $bg; ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;"><?php echo $emoji; ?></div>
                        <span class="period-cat-lbl" style="font-size:13px;font-weight:500;min-width:90px;color:var(--text-1);"><?php echo $cat; ?></span>
                        <div class="period-input-group" style="flex:1;">
                            <span class="currency" style="font-size:11px;color:var(--ink-3);">KES</span>
                            <input type="number" class="budget-input period-limit-input"
                                id="daily-<?php echo $cat; ?>"
                                data-period="daily"
                                data-category="<?php echo $cat; ?>"
                                value="<?php echo isset($dailyLimits[$cat]) ? (int)$dailyLimits[$cat] : ''; ?>"
                                placeholder="No limit"
                                min="0" step="50"
                                style="font-size:13px;padding:6px 8px;width:100%;">
                            <input type="number" class="budget-input period-limit-input"
                                id="monthly-<?php echo $cat; ?>"
                                data-period="monthly"
                                data-category="<?php echo $cat; ?>"
                                value="<?php echo isset($monthlyLimits[$cat]) ? (int)$monthlyLimits[$cat] : ''; ?>"
                                placeholder="No limit"
                                min="0" step="100"
                                style="font-size:13px;padding:6px 8px;width:100%;display:none;">
                            <input type="number" class="budget-input period-limit-input"
                                id="yearly-<?php echo $cat; ?>"
                                data-period="yearly"
                                data-category="<?php echo $cat; ?>"
                                value="<?php echo isset($yearlyLimits[$cat]) ? (int)$yearlyLimits[$cat] : ''; ?>"
                                placeholder="No limit"
                                min="0" step="500"
                                style="font-size:13px;padding:6px 8px;width:100%;display:none;">
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.06);">
                    <p style="font-size:12px;color:var(--text-3);margin:0;">💾 Period limits are saved together with your budgets when you click <strong style="color:var(--text-2);">Save Budgets</strong>.</p>
                    <button class="btn btn-primary" onclick="saveAll()" style="flex-shrink:0;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Budgets
                    </button>
                </div>
            </div>

            <!-- ══ BUDGET TIPS — below period limits ══ -->
            <div class="card anim" style="margin-top:16px;">
                <div class="card-hd">
                    <div class="card-title">💡 Budget Tips</div>
                </div>
                <div class="tips">
                    <div class="tip-item">
                        <div class="tip-icon">📐</div>
                        <div class="tip-text"><strong>50/30/20 Rule</strong> — Put 50% of income to needs, 30% to wants, and 20% to savings.</div>
                    </div>
                    <div class="tip-item">
                        <div class="tip-icon">⚡</div>
                        <div class="tip-text"><strong>80% Warning</strong> — Categories shown in amber have used over 80% of their limit.</div>
                    </div>
                    <div class="tip-item">
                        <div class="tip-icon">🔄</div>
                        <div class="tip-text"><strong>Monthly Reset</strong> — Budgets reset each month but your limits carry forward automatically.</div>
                    </div>
                </div>
            </div>

    </div><!-- /main -->
</div><!-- /layout -->

<!-- ══ OVER-BUDGET NOTIFICATION BANNER ══ -->
<div id="overBudgetBanner" style="display:none;position:fixed;top:16px;right:16px;z-index:9999;max-width:400px;display:none;flex-direction:column;gap:8px;"></div>


<script>
/* ── PHP DATA ── */
const spentData  = <?php echo json_encode(array_map('floatval', $spent)); ?>;
const budgetData = <?php echo json_encode(array_map('floatval', $budgets)); ?>;
const categories = <?php echo json_encode($expenseCategories); ?>;
const catColors  = <?php echo json_encode(array_values($colors)); ?>;
const catBg      = <?php echo json_encode(array_values($bgColors)); ?>;

/* ── LIVE ROW UPDATE ── */
function updateRow(cat) {
    const input   = document.getElementById('input-' + cat);
    const bar     = document.getElementById('bar-' + cat);
    const metaL   = document.getElementById('meta-left-' + cat);
    const statusEl= document.getElementById('status-' + cat);
    const budget  = parseFloat(input.value) || 0;
    const s       = parseFloat(input.dataset.spent) || 0;
    const pct     = budget > 0 ? Math.min(100, Math.round(s / budget * 100)) : 0;
    const left    = budget - s;

    // Bar color
    let barColor = '#6366f1';
    const catIdx = categories.indexOf(cat);
    if (catIdx >= 0) barColor = catColors[catIdx];
    if (budget > 0 && s > budget) barColor = 'var(--red)';
    else if (pct >= 80)           barColor = 'var(--amber)';

    bar.style.width      = pct + '%';
    bar.style.background = barColor;
    metaL.textContent    = pct + '% used';

    input.className = 'budget-input' + (budget > 0 && s > budget ? ' over' : '');

    if (budget === 0) {
        statusEl.className   = 'status-badge status-unset';
        statusEl.textContent = 'No limit set';
    } else if (s > budget) {
        statusEl.className   = 'status-badge status-over';
        statusEl.textContent = '⚠ Over by KES ' + (s - budget).toLocaleString('en-KE', {maximumFractionDigits:0});
    } else if (pct >= 80) {
        statusEl.className   = 'status-badge status-warning';
        statusEl.textContent = '⚡ ' + pct + '% used';
    } else {
        statusEl.className   = 'status-badge status-ok';
        statusEl.textContent = '✓ KES ' + left.toLocaleString('en-KE', {maximumFractionDigits:0}) + ' left';
    }

    updateSummary();
}

function updateSummary() {
    let total = 0;
    categories.forEach(cat => {
        const v = parseFloat(document.getElementById('input-' + cat)?.value) || 0;
        total += v;
    });
    document.getElementById('statBudgeted').textContent =
        'KES ' + total.toLocaleString('en-KE', {maximumFractionDigits: 0});
}

/* ── RESET ALL ── */
function resetAll() {
    if (!confirm('Clear all budget limits?')) return;
    categories.forEach(cat => {
        const input = document.getElementById('input-' + cat);
        if (input) { input.value = ''; updateRow(cat); }
    });
}

/* ── SAVE ALL ── */
function saveAll() {
    const budgets = {};
    const daily   = {};
    const monthly = {};
    const yearly  = {};

    categories.forEach(cat => {
        const v = parseFloat(document.getElementById('input-' + cat)?.value) || 0;
        budgets[cat] = v;

        const dEl = document.getElementById('daily-'   + cat);
        const mEl = document.getElementById('monthly-' + cat);
        const yEl = document.getElementById('yearly-'  + cat);
        if (dEl && dEl.value !== '') daily[cat]   = parseFloat(dEl.value) || 0;
        if (mEl && mEl.value !== '') monthly[cat] = parseFloat(mEl.value) || 0;
        if (yEl && yEl.value !== '') yearly[cat]  = parseFloat(yEl.value) || 0;
    });

    const fd = new FormData();
    fd.append('budgets',        JSON.stringify(budgets));
    fd.append('daily_limits',   JSON.stringify(daily));
    fd.append('monthly_limits', JSON.stringify(monthly));
    fd.append('yearly_limits',  JSON.stringify(yearly));
    const csrfInput = document.getElementById('csrf_token_budget');
    if (csrfInput) fd.append('csrf_token', csrfInput.value);

    fetch('../actions/save_budget.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            // Refresh CSRF token from response so next save works without reload
            if (d.csrf_token) {
                const el = document.getElementById('csrf_token_budget');
                if (el) el.value = d.csrf_token;
            }
            if (d.success) {
                showToast('Budgets saved successfully!', 'success');
                // Re-check alerts after saving new limits
                setTimeout(checkPeriodAlerts, 600);
            } else {
                showToast(d.message || 'Save failed', 'error');
            }
        })
        .catch(() => showToast('Network error — try again', 'error'));
}

/* ── TOAST ── */
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => { requestAnimationFrame(() => { t.classList.add('show'); }); });
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 3200);
}

/* ── SIDEBAR TOGGLE ── */
(function() {
    const layout    = document.querySelector('.layout');
    const sidebar   = document.querySelector('.sidebar');
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

/* ── DONUT CHART ── */
<?php if ($totalBudgeted > 0): ?>
(function() {
    const labels = [];
    const data   = [];
    const colors = [];

    categories.forEach((cat, i) => {
        const v = parseFloat(document.getElementById('input-' + cat)?.value) || 0;
        if (v > 0) { labels.push(cat); data.push(v); colors.push(catColors[i]); }
    });

    const ctx = document.getElementById('donutChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data, backgroundColor: colors, borderColor: '#FFFFFF', borderWidth: 3, hoverOffset: 5 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#FFFFFF',
                    padding: 10,
                    titleColor: 'rgba(255,255,255,.95)',
                    bodyColor: 'rgba(255,255,255,.5)',
                    borderColor: 'rgba(255,255,255,.08)',
                    borderWidth: 1,
                    callbacks: { label: ctx => ' KES ' + ctx.parsed.toLocaleString() }
                }
            }
        }
    });

    // Legend
    const legend = document.getElementById('donutLegend');
    const total  = data.reduce((a,b) => a+b, 0);
    labels.forEach((lbl, i) => {
        const pct = total > 0 ? ((data[i]/total)*100).toFixed(1) : '0.0';
        legend.innerHTML += `
            <div class="legend-item">
                <div class="legend-dot" style="background:${colors[i]}"></div>
                <span class="legend-lbl">${lbl}</span>
                <span class="legend-pct">${pct}%</span>
            </div>`;
    });
})();
<?php endif; ?>

/* ── PERIOD TAB SWITCHER ── */
let activePeriod = 'daily';
function switchPeriodTab(period) {
    activePeriod = period;
    document.querySelectorAll('.period-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.period === period);
    });
    categories.forEach(cat => {
        ['daily','monthly','yearly'].forEach(p => {
            const el = document.getElementById(p + '-' + cat);
            if (el) el.style.display = (p === period) ? 'block' : 'none';
        });
    });
}

/* ── OVER-BUDGET PERIOD ALERTS ── */
const shownAlerts = new Set();

function checkPeriodAlerts() {
    fetch('check_budget_period_alerts.php')
        .then(r => r.json())
        .then(data => {
            if (!data.alerts || data.alerts.length === 0) return;
            const banner = document.getElementById('overBudgetBanner');
            banner.style.display = 'flex';

            data.alerts.forEach(a => {
                const key = a.category + ':' + a.period_type;
                if (shownAlerts.has(key)) return;
                shownAlerts.add(key);

                const fmt = n => 'KES ' + Math.round(n).toLocaleString('en-KE');
                const card = document.createElement('div');
                card.className = 'ob-alert';
                card.innerHTML = `
                    <button class="ob-alert-close" onclick="this.closest('.ob-alert').remove()">✕</button>
                    <div class="ob-alert-title">⚠ Over ${a.period_label} budget — ${a.category}</div>
                    <div class="ob-alert-body">
                        You spent <strong>${fmt(a.spent)}</strong> against a ${a.period_type} limit of
                        <strong>${fmt(a.limit)}</strong>.<br>
                        That's <strong>${fmt(a.over_by)} (${a.pct}%)</strong> over your limit.
                    </div>`;
                banner.appendChild(card);

                // Auto-dismiss after 12 s
                setTimeout(() => card.remove(), 12000);
            });
        })
        .catch(() => {}); // silent fail — non-critical
}

// Check on page load and every 5 minutes
checkPeriodAlerts();
setInterval(checkPeriodAlerts, 300000);
</script>
</body>
</html>