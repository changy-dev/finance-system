<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id']; // cast to int — prevents SQL injection

/* ── MONTHLY TREND ── */
$months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthly = array_fill(0, 12, 0);

$res = $conn->query("
    SELECT MONTH(created_at) m, SUM(amount) total
    FROM transactions
    WHERE user_id = $user_id AND type = 'expense'
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
$colors    = ['#6366f1','#f43f5e','#10b981','#f59e0b','#0ea5e9','#a855f7','#14b8a6'];

$q = $conn->query("
    SELECT category, SUM(amount) total
    FROM transactions
    WHERE user_id = $user_id AND type = 'expense'
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

/* ── INCOME ── */
$incomeRes   = $conn->query("SELECT SUM(amount) total FROM transactions WHERE user_id = $user_id AND type = 'income'");
$totalIncome = 0;
if ($incomeRes && $row = $incomeRes->fetch_assoc()) {
    $totalIncome = (int) $row['total'];
}
$netBalance = $totalIncome - $totalExpense;

/* ── TRANSACTION COUNT ── */
$txCountRes = $conn->query("SELECT COUNT(*) cnt FROM transactions WHERE user_id = $user_id");
$txCount    = 0;
if ($txCountRes && $row = $txCountRes->fetch_assoc()) {
    $txCount = (int) $row['cnt'];
}

/* ── RECENT TRANSACTIONS (separate query — never share a result pointer) ── */
$recent = $conn->query("
    SELECT * FROM transactions
    WHERE user_id = $user_id
    ORDER BY created_at DESC, transaction_id DESC
    LIMIT 8
");
if (!$recent) $recent = null;

/* ── MONTHLY AVG ── */
$nonZeroMonths = count(array_filter($monthly));
$monthlyAvg    = $nonZeroMonths > 0 ? round($totalExpense / $nonZeroMonths) : 0;

/* ── SPENDING RATE (safe division) ── */
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
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --ink:        #0d0f12;
    --ink-2:      #3a3f4a;
    --ink-3:      #7a8394;
    --line:       #e8eaed;
    --line-2:     #f1f3f5;
    --surface:    #ffffff;
    --page:       #f6f7f9;
    --accent:     #6366f1;
    --accent-lt:  #eef2ff;
    --green:      #10b981;
    --green-lt:   #ecfdf5;
    --red:        #f43f5e;
    --red-lt:     #fff1f2;
    --amber:      #f59e0b;
    --amber-lt:   #fffbeb;
    --sidebar-w:  240px;
    --r:          10px;
    --r-lg:       14px;
    --shadow:     0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow-md:  0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
}

body { font-family: 'DM Sans', sans-serif; background: var(--page); color: var(--ink); font-size: 14px; line-height: 1.5; }

.layout { display: flex; min-height: 100vh; }

/* SIDEBAR */
.sidebar { width: var(--sidebar-w); flex-shrink: 0; background: var(--ink); position: fixed; top: 0; left: 0; height: 100vh; display: flex; flex-direction: column; z-index: 50; overflow: hidden; }
.sidebar-brand { padding: 28px 20px 24px; border-bottom: 1px solid rgba(255,255,255,.07); }
.brand-mark { display: flex; align-items: center; gap: 10px; }
.brand-icon { width: 34px; height: 34px; background: var(--accent); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.brand-icon svg { width: 18px; height: 18px; color: #fff; }
.brand-name { font-size: 15px; font-weight: 600; color: #fff; letter-spacing: -.2px; }
.brand-tagline { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 1px; }
.sidebar-nav { padding: 16px 12px; flex: 1; }
.nav-section-label { font-size: 10px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: rgba(255,255,255,.25); padding: 0 8px; margin: 8px 0 6px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 7px; color: rgba(255,255,255,.5); text-decoration: none; font-size: 13.5px; font-weight: 400; transition: background .15s, color .15s; margin-bottom: 2px; }
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item:hover { background: rgba(255,255,255,.06); color: rgba(255,255,255,.85); }
.nav-item.active { background: rgba(99,102,241,.2); color: #a5b4fc; font-weight: 500; }
.nav-item .badge { margin-left: auto; background: rgba(99,102,241,.35); color: #a5b4fc; font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 10px; font-family: 'DM Mono', monospace; }
.sidebar-footer { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,.06); }
.user-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 7px; cursor: pointer; transition: background .15s; }
.user-row:hover { background: rgba(255,255,255,.06); }
.user-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #fff; flex-shrink: 0; }
.user-name { font-size: 12.5px; color: rgba(255,255,255,.7); font-weight: 500; }
.user-role { font-size: 11px; color: rgba(255,255,255,.3); }

/* MAIN */
.main { flex: 1; margin-left: var(--sidebar-w); display: flex; flex-direction: column; min-height: 100vh; }

/* TOPBAR */
.topbar { background: var(--surface); border-bottom: 1px solid var(--line); padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; }
.topbar-left { display: flex; align-items: center; gap: 6px; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-3); }
.breadcrumb-sep { color: var(--line); }
.breadcrumb-current { color: var(--ink); font-weight: 500; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.topbar-date { font-size: 12px; color: var(--ink-3); font-family: 'DM Mono', monospace; }

/* BUTTONS */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 7px; border: none; font-size: 13px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all .15s; text-decoration: none; white-space: nowrap; }
.btn svg { width: 14px; height: 14px; }
.btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--line); }
.btn-ghost:hover { background: var(--line-2); }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #4f46e5; box-shadow: 0 4px 12px rgba(99,102,241,.3); }

/* CONTENT */
.content { padding: 28px 32px 48px; }
.page-header { margin-bottom: 24px; }
.page-title { font-size: 22px; font-weight: 600; letter-spacing: -.4px; }
.page-sub { font-size: 13px; color: var(--ink-3); margin-top: 2px; }

/* STAT CARDS */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
.stat { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: 20px; box-shadow: var(--shadow); position: relative; overflow: hidden; transition: box-shadow .2s, transform .2s; }
.stat:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.stat-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
.stat-lbl { font-size: 11.5px; font-weight: 500; color: var(--ink-3); text-transform: uppercase; letter-spacing: .5px; }
.stat-ico { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-ico svg { width: 16px; height: 16px; }
.ico-blue  { background: #eef2ff; color: var(--accent); }
.ico-red   { background: var(--red-lt); color: var(--red); }
.ico-green { background: var(--green-lt); color: var(--green); }
.ico-amber { background: var(--amber-lt); color: var(--amber); }
.stat-val { font-size: 24px; font-weight: 600; letter-spacing: -.6px; font-family: 'DM Mono', monospace; line-height: 1; margin-bottom: 8px; }
.stat-meta { font-size: 11.5px; color: var(--ink-3); display: flex; align-items: center; gap: 4px; }
.stat-meta .pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px; border-radius: 10px; font-size: 10.5px; font-weight: 600; }
.pill-up      { background: var(--red-lt); color: var(--red); }
.pill-down    { background: var(--green-lt); color: var(--green); }
.pill-neutral { background: var(--line-2); color: var(--ink-3); }

/* CHART GRID */
.charts-row { display: grid; grid-template-columns: 1fr 340px; gap: 14px; margin-bottom: 14px; }

/* CARDS */
.card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: 20px 22px; box-shadow: var(--shadow); }
.card-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.card-title { font-size: 14px; font-weight: 600; letter-spacing: -.2px; }
.card-sub { font-size: 12px; color: var(--ink-3); margin-top: 1px; }
.chart-wrap { position: relative; height: 260px; }

/* DONUT */
.donut-wrap { position: relative; display: flex; align-items: center; justify-content: center; height: 200px; }
.donut-center { position: absolute; text-align: center; pointer-events: none; }
.donut-lbl { font-size: 11px; font-weight: 500; color: var(--ink-3); text-transform: uppercase; letter-spacing: .5px; }
.donut-val { font-size: 18px; font-weight: 600; font-family: 'DM Mono', monospace; letter-spacing: -.4px; margin-top: 2px; }
.legend { margin-top: 16px; display: flex; flex-direction: column; gap: 7px; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--ink-2); }
.legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.legend-lbl { flex: 1; }
.legend-val { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--ink-3); }
.legend-pct { font-size: 11px; color: var(--ink-3); width: 36px; text-align: right; }

/* SEGMENT */
.seg { display: flex; gap: 2px; background: var(--line-2); border-radius: 7px; padding: 3px; }
.seg-btn { padding: 4px 10px; border-radius: 5px; border: none; font-size: 12px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; background: transparent; color: var(--ink-3); transition: all .15s; }
.seg-btn.active { background: var(--surface); color: var(--ink); box-shadow: 0 1px 2px rgba(0,0,0,.1); }

/* BOTTOM ROW */
.bottom-row { display: grid; grid-template-columns: 1fr 300px; gap: 14px; }

/* TABLE */
.tx-table { width: 100%; border-collapse: collapse; }
.tx-table th { text-align: left; font-size: 11px; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: .5px; padding: 0 0 12px; border-bottom: 1px solid var(--line); }
.tx-table td { padding: 12px 0; border-bottom: 1px solid var(--line-2); }
.tx-table tr:last-child td { border-bottom: none; }
.tx-cat-wrap { display: flex; align-items: center; gap: 10px; }
.tx-cat-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.tx-name { font-size: 13px; font-weight: 500; }
.tx-desc { font-size: 11.5px; color: var(--ink-3); }
.tx-amount { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; }
.tx-amount.expense { color: var(--red); }
.tx-amount.income  { color: var(--green); }
.tx-date { font-size: 11.5px; color: var(--ink-3); font-family: 'DM Mono', monospace; display: block; }
.tx-date-today     { color: var(--accent); font-weight: 600; }
.tx-date-yesterday { color: var(--amber);  font-weight: 500; }
.tx-date-sub { font-size: 10px; color: var(--ink-3); font-family: 'DM Mono', monospace; margin-top: 1px; }
.tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; background: var(--line-2); color: var(--ink-3); }

/* INSIGHTS */
.insights { display: flex; flex-direction: column; gap: 10px; }
.insight-item { background: var(--line-2); border-radius: 9px; padding: 12px 14px; }
.insight-label { font-size: 11.5px; color: var(--ink-3); font-weight: 500; margin-bottom: 6px; }
.insight-val { font-size: 18px; font-weight: 600; font-family: 'DM Mono', monospace; letter-spacing: -.3px; }
.insight-sub { font-size: 11px; color: var(--ink-3); margin-top: 2px; }
.progress-bar { height: 4px; background: var(--line); border-radius: 99px; margin-top: 10px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 99px; transition: width .6s ease; }

/* EMPTY */
.empty { text-align: center; padding: 40px 20px; color: var(--ink-3); }
.empty-ico { font-size: 32px; margin-bottom: 10px; }
.empty-txt { font-size: 13px; }

.view-all { font-size: 12px; font-weight: 500; color: var(--accent); text-decoration: none; display: inline-flex; align-items: center; gap: 3px; transition: gap .15s; }
.view-all:hover { gap: 6px; }

/* CATEGORY ICON BACKGROUNDS */
.cat-food         { background: #fff7ed; }
.cat-transport    { background: #eff6ff; }
.cat-utilities    { background: #f0fdf4; }
.cat-entertainment{ background: #fdf4ff; }
.cat-shopping     { background: #fff1f2; }
.cat-health       { background: #ecfdf5; }
.cat-other        { background: #f8fafc; }
.cat-income       { background: var(--green-lt); }

/* MODAL */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,12,18,.55); backdrop-filter: blur(4px); z-index: 200; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 28px; width: 460px; max-width: 95vw; box-shadow: 0 20px 60px rgba(0,0,0,.18); animation: slideUp .22s ease; }
.modal-hd { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
.modal-title { font-size: 17px; font-weight: 600; }
.modal-sub { font-size: 12px; color: var(--ink-3); margin-top: 2px; }
.modal-close { width: 28px; height: 28px; border-radius: 6px; background: var(--line-2); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--ink-3); transition: background .15s; }
.modal-close:hover { background: var(--line); color: var(--ink); }

/* FORM */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.field { margin-bottom: 14px; }
.field label { display: block; font-size: 12px; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px; }
.field input, .field select, .field textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 7px; background: var(--surface); color: var(--ink); font-size: 13.5px; font-family: 'DM Sans', sans-serif; transition: border .15s, box-shadow .15s; appearance: none; -webkit-appearance: none; }
.field input:focus, .field select:focus, .field textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.field textarea { resize: vertical; min-height: 72px; }
.type-toggle { display: flex; gap: 8px; }
.type-btn { flex: 1; padding: 9px; border-radius: 7px; border: 1.5px solid var(--line); background: transparent; font-size: 13px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all .15s; color: var(--ink-3); }
.type-btn.selected-expense { border-color: var(--red);   background: var(--red-lt);   color: var(--red); }
.type-btn.selected-income  { border-color: var(--green); background: var(--green-lt); color: var(--green); }
.modal-actions { display: flex; gap: 10px; margin-top: 22px; }
.modal-actions .btn { flex: 1; justify-content: center; padding: 10px; }

/* TOAST */
.toast { position: fixed; bottom: 24px; right: 24px; background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 9px; font-size: 13px; font-weight: 500; box-shadow: 0 8px 24px rgba(0,0,0,.25); animation: slideUp .25s ease; z-index: 999; display: flex; align-items: center; gap: 8px; }
.toast.success::before { content: '✓'; background: var(--green); width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
.toast.error::before   { content: '!'; background: var(--red);   width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }

/* ANIMATIONS */
@keyframes slideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
.anim    { animation: fadeIn .35s ease both; }
.anim-d1 { animation-delay: .05s; }
.anim-d2 { animation-delay: .1s; }
.anim-d3 { animation-delay: .15s; }
.anim-d4 { animation-delay: .2s; }

/* SCROLLBAR */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--line); border-radius: 99px; }

/* RESPONSIVE */
@media (max-width: 1100px) { .charts-row { grid-template-columns: 1fr; } .bottom-row { grid-template-columns: 1fr; } .stats-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0; } .content { padding: 20px 16px 40px; } .topbar { padding: 0 16px; } .stats-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px)  { .stats-row { grid-template-columns: 1fr; } }
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
            <a href="#" class="nav-item active">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
                Dashboard
                <span class="badge">Live</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Money</div>
            <a href="transactions.php" class="nav-item">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                Transactions
            </a>
            <a href="budget.php" class="nav-item">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                Budget
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Account</div>
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

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="breadcrumb">
                    <span>Home</span>
                    <span class="breadcrumb-sep">›</span>
                    <span class="breadcrumb-current">Dashboard</span>
                </div>
            </div>
            <div class="topbar-right">
                <span class="topbar-date"><?php echo date('D, d M Y'); ?></span>
                <button class="btn btn-ghost" onclick="window.print()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Export
                </button>
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

            <!-- CHARTS ROW -->
            <div class="charts-row">
                <div class="card anim">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Spending Trend</div>
                            <div class="card-sub">Monthly expense breakdown</div>
                        </div>
                        <div class="seg" id="rangeToggle">
                            <button class="seg-btn active" data-range="12">12M</button>
                            <button class="seg-btn" data-range="6">6M</button>
                            <button class="seg-btn" data-range="3">3M</button>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="lineChart"></canvas>
                    </div>
                </div>

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
            </div>

            <!-- BOTTOM ROW -->
            <div class="bottom-row">
                <div class="card anim">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Recent Transactions</div>
                            <div class="card-sub">Last 8 records</div>
                        </div>
                        <a href="transactions.php" class="view-all">View all →</a>
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
            <div class="field">
                <label>Type</label>
                <div class="type-toggle">
                    <button type="button" class="type-btn selected-expense" id="btnExpense" onclick="setType('expense')">↓ Expense</button>
                    <button type="button" class="type-btn" id="btnIncome" onclick="setType('income')">↑ Income</button>
                </div>
                <input type="hidden" name="type" id="typeInput" value="expense">
            </div>

            <div class="form-row">
                <div class="field">
                    <label id="categoryLabel">Category</label>
                    <select name="category" id="categorySelect" required>
                        <option value="">Choose…</option>
                        <optgroup label="🍽️ Food &amp; Dining">
                            <option value="Groceries">🛒 Groceries</option>
                            <option value="Household Supplies">🧴 Household Supplies</option>
                            <option value="Eating Out">🍔 Eating Out</option>
                        </optgroup>
                        <optgroup label="🚌 Transport &amp; Auto">
                            <option value="Fuel &amp; Charging">⛽ Fuel &amp; Charging</option>
                            <option value="Vehicle Maintenance">🔧 Vehicle Maintenance</option>
                            <option value="Commuting">🚇 Commuting</option>
                        </optgroup>
                        <optgroup label="💡 Utilities &amp; Home">
                            <option value="Utilities">💡 Utilities</option>
                            <option value="Home Decor">🛋️ Home Decor</option>
                            <option value="Maintenance &amp; DIY">🔨 Maintenance &amp; DIY</option>
                            <option value="Kitchenware">🍳 Kitchenware</option>
                        </optgroup>
                        <optgroup label="🏥 Health &amp; Wellness">
                            <option value="Health &amp; Pharmacy">💊 Health &amp; Pharmacy</option>
                            <option value="Personal Grooming">💆 Personal Grooming</option>
                        </optgroup>
                        <optgroup label="🛍️ Shopping &amp; Lifestyle">
                            <option value="Clothing &amp; Accessories">👗 Clothing &amp; Accessories</option>
                            <option value="Electronics &amp; Tech">📱 Electronics &amp; Tech</option>
                            <option value="Entertainment">🎬 Entertainment</option>
                        </optgroup>
                        <optgroup label="🎁 Miscellaneous">
                            <option value="Gifts &amp; Celebrations">🎉 Gifts &amp; Celebrations</option>
                            <option value="Pet Supplies">🐾 Pet Supplies</option>
                            <option value="Impulse Buys">🛒 Impulse Buys</option>
                            <option value="Other">📦 Other</option>
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
                <input type="date" name="created_at" value="<?php echo date('Y-m-d'); ?>" required>
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
    { group: '🍽️ Food & Dining',        value: 'Groceries',               label: '🛒 Groceries'               },
    { group: '🍽️ Food & Dining',        value: 'Household Supplies',       label: '🧴 Household Supplies'       },
    { group: '🍽️ Food & Dining',        value: 'Eating Out',               label: '🍔 Eating Out'               },
    // Transport
    { group: '🚌 Transport & Auto',      value: 'Fuel & Charging',          label: '⛽ Fuel & Charging'          },
    { group: '🚌 Transport & Auto',      value: 'Vehicle Maintenance',      label: '🔧 Vehicle Maintenance'      },
    { group: '🚌 Transport & Auto',      value: 'Commuting',                label: '🚇 Commuting'                },
    // Utilities & Home
    { group: '💡 Utilities & Home',      value: 'Utilities',                label: '💡 Utilities'                },
    { group: '💡 Utilities & Home',      value: 'Home Decor',               label: '🛋️ Home Decor'              },
    { group: '💡 Utilities & Home',      value: 'Maintenance & DIY',        label: '🔨 Maintenance & DIY'        },
    { group: '💡 Utilities & Home',      value: 'Kitchenware',              label: '🍳 Kitchenware'              },
    // Health
    { group: '🏥 Health & Wellness',     value: 'Health & Pharmacy',        label: '💊 Health & Pharmacy'        },
    { group: '🏥 Health & Wellness',     value: 'Personal Grooming',        label: '💆 Personal Grooming'        },
    // Shopping
    { group: '🛍️ Shopping & Lifestyle', value: 'Clothing & Accessories',   label: '👗 Clothing & Accessories'   },
    { group: '🛍️ Shopping & Lifestyle', value: 'Electronics & Tech',       label: '📱 Electronics & Tech'       },
    { group: '🛍️ Shopping & Lifestyle', value: 'Entertainment',            label: '🎬 Entertainment'            },
    // Misc
    { group: '🎁 Miscellaneous',         value: 'Gifts & Celebrations',     label: '🎉 Gifts & Celebrations'     },
    { group: '🎁 Miscellaneous',         value: 'Pet Supplies',             label: '🐾 Pet Supplies'             },
    { group: '🎁 Miscellaneous',         value: 'Impulse Buys',             label: '🛒 Impulse Buys'             },
    { group: '🎁 Miscellaneous',         value: 'Other',                    label: '📦 Other'                    },
];

const incomeCategories = [
    { value: 'Salary',        label: '💼 Salary'           },
    { value: 'Business',      label: '🏢 Business'         },
    { value: 'Investments',   label: '📈 Investments'      },
    { value: 'Shares',        label: '📊 Shares/Dividends' },
    { value: 'Freelance',     label: '💻 Freelance'        },
    { value: 'Family',        label: '👨‍👩‍👧 Family & Friends' },
    { value: 'Rental',        label: '🏠 Rental Income'    },
    { value: 'Gift',          label: '🎁 Gift'             },
    { value: 'Other Income',  label: '💰 Other Income'     },
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

/* FORM SUBMIT */
document.getElementById('txForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('add_transaction.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast('Transaction saved!', 'success');
                closeModal();
                this.reset();
                setType('expense'); // reset type toggle visually
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(d.message || 'Error saving transaction', 'error');
                btn.disabled = false;
                btn.textContent = 'Save Transaction';
            }
        })
        .catch(() => {
            showToast('Network error — please try again', 'error');
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
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99,102,241,.08)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.45,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#6366f1',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    order: 0
                },
                {
                    type: 'bar',
                    data: data,
                    backgroundColor: 'rgba(99,102,241,.12)',
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
                    backgroundColor: '#0d0f12',
                    padding: 12,
                    titleColor: '#f1f5f9',
                    bodyColor: '#94a3b8',
                    borderColor: '#2a2f3a',
                    borderWidth: 1,
                    displayColors: false,
                    callbacks: { label: ctx => 'KES ' + ctx.parsed.y.toLocaleString() }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f3f5', drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 11 }, callback: v => 'KES ' + v.toLocaleString() }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 11 } }
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
                borderColor: '#fff',
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
                    backgroundColor: '#0d0f12',
                    padding: 12,
                    titleColor: '#f1f5f9',
                    bodyColor: '#94a3b8',
                    borderColor: '#2a2f3a',
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
</script>
</body>
</html>



