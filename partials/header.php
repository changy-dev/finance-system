<?php
// ============================================================
// partials/header.php
// Include at the TOP of every authenticated page.
// Handles: session, auth check, DB, CSRF, theme, opens HTML.
// After this file: include sidebar.php, then your page content.
// ============================================================

// Prevent double session_start if a page already called it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../csrf.php';
require_once __DIR__ . '/../security.php';

// Auth guard — redirects to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id   = (int) $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'Personal plan';

// Load user theme (light / dark)
$theme = 'light'; // safe default
$theme_stmt = $conn->prepare("SELECT theme FROM users WHERE user_id = ?");
$theme_stmt->bind_param("i", $user_id);
$theme_stmt->execute();
$theme_row = $theme_stmt->get_result()->fetch_assoc();
$theme     = $theme_row['theme'] ?? 'light';
$theme_stmt->close();

// Page title — each page can set $page_title before including this file.
// e.g.  $page_title = 'Budget';  then  require_once 'partials/header.php';
$page_title = isset($page_title) ? htmlspecialchars($page_title) . ' — Fintrack' : 'Fintrack';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ═══════════════════════════════════════════════
           Fintrack — Shared CSS
           Included on every page via partials/header.php
           ═══════════════════════════════════════════════ */

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
            --sidebar-collapsed: 64px;
            --radius:     10px;
            --radius-lg:  14px;
            --shadow:     0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md:  0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
        }

        [data-theme="dark"] {
            --ink:     #f1f5f9;
            --ink-2:   #cbd5e1;
            --ink-3:   #94a3b8;
            --line:    #334155;
            --line-2:  #1e293b;
            --surface: #1e293b;
            --page:    #0f172a;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--page);
            color: var(--ink);
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Layout ── */
        .layout { display: flex; min-height: 100vh; }

        .main {
            flex: 1;
            margin-left: var(--sidebar-w);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left .25s ease;
        }
        .layout.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }

        /* ── Topbar ── */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            padding: 0 32px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
        }
        .topbar-left, .topbar-right { display: flex; align-items: center; gap: 10px; }
        .breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-3); }
        .breadcrumb a { color: var(--ink-3); text-decoration: none; }
        .breadcrumb a:hover { color: var(--ink); }
        .breadcrumb-sep { color: var(--line); }
        .breadcrumb-current { color: var(--ink); font-weight: 500; }
        .topbar-date { font-size: 12px; color: var(--ink-3); font-family: 'DM Mono', monospace; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 7px; border: none;
            font-size: 13px; font-weight: 500; font-family: inherit;
            cursor: pointer; transition: all .15s;
            text-decoration: none; white-space: nowrap;
        }
        .btn svg { width: 14px; height: 14px; flex-shrink: 0; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #4f46e5; box-shadow: 0 4px 12px rgba(99,102,241,.3); }
        .btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--line); }
        .btn-ghost:hover { background: var(--line-2); }
        .btn-danger { background: var(--red-lt); color: var(--red); }
        .btn-danger:hover { background: #fecdd3; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }

        /* ── Content wrapper ── */
        .content { padding: 28px 32px 48px; }
        .page-header { margin-bottom: 24px; }
        .page-title { font-size: 22px; font-weight: 600; letter-spacing: -.4px; }
        .page-sub { font-size: 13px; color: var(--ink-3); margin-top: 2px; }

        /* ── Stat cards ── */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
        .stat {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius-lg); padding: 20px; box-shadow: var(--shadow);
            transition: box-shadow .2s, transform .2s;
        }
        .stat:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .stat-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
        .stat-lbl { font-size: 11.5px; font-weight: 500; color: var(--ink-3); text-transform: uppercase; letter-spacing: .5px; }
        .stat-ico { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .stat-ico svg { width: 16px; height: 16px; }
        .ico-blue  { background: var(--accent-lt); color: var(--accent); }
        .ico-red   { background: var(--red-lt);    color: var(--red);    }
        .ico-green { background: var(--green-lt);  color: var(--green);  }
        .ico-amber { background: var(--amber-lt);  color: var(--amber);  }
        .stat-val { font-size: 24px; font-weight: 600; letter-spacing: -.6px; font-family: 'DM Mono', monospace; line-height: 1; margin-bottom: 8px; }
        .stat-meta { font-size: 11.5px; color: var(--ink-3); display: flex; align-items: center; gap: 4px; }
        .pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 6px; border-radius: 10px; font-size: 10.5px; font-weight: 600; }
        .pill-up      { background: var(--red-lt);   color: var(--red);   }
        .pill-down    { background: var(--green-lt); color: var(--green); }
        .pill-neutral { background: var(--line-2);   color: var(--ink-3); }

        /* ── Cards ── */
        .card {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius-lg); padding: 20px 22px; box-shadow: var(--shadow);
        }
        .card-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .card-title { font-size: 14px; font-weight: 600; letter-spacing: -.2px; }
        .card-sub { font-size: 12px; color: var(--ink-3); margin-top: 1px; }
        .chart-wrap { position: relative; height: 260px; }

        /* ── Table ── */
        .tx-table { width: 100%; border-collapse: collapse; }
        .tx-table th { text-align: left; font-size: 11px; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: .5px; padding: 0 0 12px; border-bottom: 1px solid var(--line); }
        .tx-table td { padding: 12px 0; border-bottom: 1px solid var(--line-2); }
        .tx-table tr:last-child td { border-bottom: none; }
        .tx-cat-wrap { display: flex; align-items: center; gap: 10px; }
        .tx-cat-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
        .tx-name   { font-size: 13px; font-weight: 500; }
        .tx-desc   { font-size: 11.5px; color: var(--ink-3); }
        .tx-amount { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; }
        .tx-amount.expense { color: var(--red);   }
        .tx-amount.income  { color: var(--green); }
        .tx-date   { font-size: 11.5px; color: var(--ink-3); font-family: 'DM Mono', monospace; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; background: var(--line-2); color: var(--ink-3); }

        /* ── Modal ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,12,18,.55); backdrop-filter: blur(4px); z-index: 200; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 28px; width: 460px; max-width: 95vw; box-shadow: 0 20px 60px rgba(0,0,0,.18); animation: slideUp .22s ease; }
        .modal-hd { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
        .modal-title { font-size: 17px; font-weight: 600; }
        .modal-sub { font-size: 12px; color: var(--ink-3); margin-top: 2px; }
        .modal-close { width: 28px; height: 28px; border-radius: 6px; background: var(--line-2); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--ink-3); }
        .modal-close:hover { background: var(--line); color: var(--ink); }
        .modal-actions { display: flex; gap: 10px; margin-top: 22px; }
        .modal-actions .btn { flex: 1; justify-content: center; padding: 10px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { margin-bottom: 14px; }
        .field label { display: block; font-size: 12px; font-weight: 600; color: var(--ink-3); text-transform: uppercase; margin-bottom: 6px; }
        .field input, .field select, .field textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 7px; background: var(--surface); color: var(--ink); font-size: 13.5px; font-family: inherit; outline: none; transition: border-color .15s; }
        .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); }

        /* ── Type toggle (add transaction modal) ── */
        .type-toggle { display: flex; gap: 8px; }
        .type-btn { flex: 1; padding: 9px; border-radius: 7px; border: 1.5px solid var(--line); background: transparent; font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; color: var(--ink-3); }
        .type-btn.selected-expense { border-color: var(--red);   background: var(--red-lt);   color: var(--red);   }
        .type-btn.selected-income  { border-color: var(--green); background: var(--green-lt); color: var(--green); }

        /* ── Progress & insights ── */
        .progress-bar { height: 4px; background: var(--line); border-radius: 99px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 99px; transition: width .6s ease; }
        .insights { display: flex; flex-direction: column; gap: 10px; }
        .insight-item { background: var(--line-2); border-radius: 9px; padding: 12px 14px; }
        .insight-label { font-size: 11.5px; color: var(--ink-3); font-weight: 500; margin-bottom: 6px; }
        .insight-val { font-size: 18px; font-weight: 600; font-family: 'DM Mono', monospace; letter-spacing: -.3px; }
        .insight-sub { font-size: 11px; color: var(--ink-3); margin-top: 2px; }
        .view-all { font-size: 12px; font-weight: 500; color: var(--accent); text-decoration: none; }

        /* ── Empty state ── */
        .empty { text-align: center; padding: 40px 20px; color: var(--ink-3); }
        .empty-ico { font-size: 32px; margin-bottom: 10px; }
        .empty-txt { font-size: 13px; }

        /* ── Pagination ── */
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid var(--line); }
        .pagination-info { font-size: 12.5px; color: var(--ink-3); }
        .pagination-btns { display: flex; gap: 4px; }
        .page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 8px; border-radius: 7px; font-size: 13px; font-weight: 500; text-decoration: none; color: var(--ink-2); border: 1px solid var(--line); background: var(--surface); transition: all .15s; }
        .page-btn:hover { background: var(--page); color: var(--ink); }
        .page-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
        .page-btn.disabled { opacity: .4; pointer-events: none; }

        /* ── Toast ── */
        .toast { position: fixed; bottom: 24px; right: 24px; background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 9px; font-size: 13px; font-weight: 500; box-shadow: 0 8px 24px rgba(0,0,0,.25); z-index: 999; transform: translateY(8px); opacity: 0; transition: all .25s; pointer-events: none; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.success { background: #065f46; border-left: 3px solid var(--green); }
        .toast.error   { background: #9f1239; border-left: 3px solid var(--red);   }

        /* ── Animations ── */
        @keyframes slideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
        .anim    { animation: fadeIn .35s ease both; }
        .anim-d1 { animation-delay: .05s; }
        .anim-d2 { animation-delay: .10s; }
        .anim-d3 { animation-delay: .15s; }
        .anim-d4 { animation-delay: .20s; }

        /* ── Category icon colours ── */
        .cat-food          { background: #fff7ed; }
        .cat-transport     { background: #eff6ff; }
        .cat-utilities     { background: #f0fdf4; }
        .cat-entertainment { background: #fdf4ff; }
        .cat-shopping      { background: #fff1f2; }
        .cat-health        { background: #ecfdf5; }
        .cat-income        { background: var(--green-lt); }

        /* ── Mobile ── */
        .hamburger { display: none; position: fixed; top: 16px; left: 16px; z-index: 100; background: var(--ink); border: none; color: #fff; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; align-items: center; justify-content: center; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 40; }
        @media (max-width: 900px) {
            .hamburger { display: flex; }
            .main { margin-left: 0 !important; padding-top: 64px; }
            .content { padding: 20px 16px 40px; }
            .topbar { padding: 0 16px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .form-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .stat-val { font-size: 18px; }
        }
    </style>
</head>
<body>

<!-- Mobile hamburger -->
<button class="hamburger" onclick="toggleSidebar()" aria-label="Toggle menu">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="layout" id="layout">