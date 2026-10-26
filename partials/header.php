<?php
// ============================================================
// partials/header.php  — Fintrack Premium Design System v2
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/security.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }

$user_id   = (int) $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'Personal plan';

$theme = 'light';
$theme_stmt = $conn->prepare("SELECT theme FROM users WHERE user_id = ?");
$theme_stmt->bind_param("i", $user_id);
$theme_stmt->execute();
$theme_row = $theme_stmt->get_result()->fetch_assoc();
$theme     = $theme_row['theme'] ?? 'light';
$theme_stmt->close();

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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            /* ── Vibrant Dark Palette ── */
            --page:              #080b12;
            --surface:           #0e1422;
            --surface-2:         #151c2e;
            --surface-3:         #1c2540;
            --ink:               #0a0e19;
            --ink-2:             #151c2e;
            --ink-3:             #1c2540;
            --line:              rgba(255,255,255,.07);
            --line-2:            rgba(255,255,255,.04);

            /* ── Accent: Electric Cyan + Hot Coral ── */
            --cyan:              #00d4ff;
            --cyan-dim:          rgba(0,212,255,.12);
            --cyan-glow:         0 0 24px rgba(0,212,255,.3);
            --coral:             #ff5e62;
            --coral-dim:         rgba(255,94,98,.12);
            --violet:            #7c3aed;
            --violet-dim:        rgba(124,58,237,.15);

            /* ── Legacy aliases (keeps old pages working) ── */
            --gold:              #00d4ff;
            --gold-light:        #33ddff;
            --gold-dim:          rgba(0,212,255,.12);
            --gold-glow:         0 0 24px rgba(0,212,255,.3);
            --accent:            #00d4ff;
            --accent-lt:         rgba(0,212,255,.12);
            --accent-glow:       0 4px 18px rgba(0,212,255,.28);

            /* ── Semantic ── */
            --green:             #10f5a0;
            --green-lt:          rgba(16,245,160,.12);
            --green-dim:         rgba(16,245,160,.12);
            --red:               #ff4d6d;
            --red-lt:            rgba(255,77,109,.12);
            --red-dim:           rgba(255,77,109,.12);
            --amber:             #ffd60a;
            --amber-lt:          rgba(255,214,10,.12);
            --amber-dim:         rgba(255,214,10,.12);

            /* ── Typography ── */
            --font:              'Plus Jakarta Sans', sans-serif;
            --font-head:         'Space Grotesk', sans-serif;
            --font-mono:         'Space Mono', monospace;
            --text-1:            rgba(255,255,255,.95);
            --text-2:            rgba(255,255,255,.5);
            --text-3:            rgba(255,255,255,.25);

            /* ── Sizing ── */
            --sidebar-w:         248px;
            --sidebar-collapsed: 66px;
            --radius:            10px;
            --radius-lg:         16px;
            --radius-xl:         22px;
            --shadow-xs:         0 1px 3px rgba(0,0,0,.3);
            --shadow:            0 2px 14px rgba(0,0,0,.45);
            --shadow-md:         0 6px 32px rgba(0,0,0,.55);
            --shadow-lg:         0 20px 64px rgba(0,0,0,.7);
        }

        [data-theme="light"] {
            --page:              #f0f4fa;
            --surface:           #ffffff;
            --surface-2:         #f5f7fd;
            --surface-3:         #eaecf5;
            --line:              rgba(0,0,0,.08);
            --line-2:            rgba(0,0,0,.04);
            --cyan:              #0095b8;
            --cyan-dim:          rgba(0,149,184,.1);
            --cyan-glow:         0 4px 18px rgba(0,149,184,.25);
            --gold:              #0095b8;
            --gold-light:        #00afd8;
            --gold-dim:          rgba(0,149,184,.1);
            --accent:            #0095b8;
            --accent-lt:         rgba(0,149,184,.1);
            --accent-glow:       0 4px 18px rgba(0,149,184,.22);
            --green:             #059669;
            --green-lt:          rgba(5,150,105,.1);
            --green-dim:         rgba(5,150,105,.1);
            --red:               #e02045;
            --red-lt:            rgba(224,32,69,.08);
            --red-dim:           rgba(224,32,69,.08);
            --amber:             #d97706;
            --amber-lt:          rgba(217,119,6,.1);
            --amber-dim:         rgba(217,119,6,.1);
            --shadow:            0 2px 8px rgba(0,0,0,.07);
            --shadow-md:         0 6px 20px rgba(0,0,0,.10);
            --shadow-lg:         0 16px 48px rgba(0,0,0,.13);
            --text-1:            rgba(0,0,0,.88);
            --text-2:            rgba(0,0,0,.5);
            --text-3:            rgba(0,0,0,.3);
        }


        body::before { content: ''; position: fixed; inset: 0; background: radial-gradient(ellipse 80% 50% at 20% 10%, rgba(0,212,255,.05) 0%, transparent 60%), radial-gradient(ellipse 60% 40% at 80% 80%, rgba(124,58,237,.06) 0%, transparent 55%); pointer-events: none; z-index: 0; }
        body > * { position: relative; z-index: 1; }
        body { font-family: var(--font); background: var(--page); color: var(--text-1); font-size: 14px; line-height: 1.6; -webkit-font-smoothing: antialiased; }

        .layout { display: flex; min-height: 100vh; }
        .main { flex: 1; margin-left: var(--sidebar-w); display: flex; flex-direction: column; min-height: 100vh; transition: margin-left .25s cubic-bezier(.4,0,.2,1); }
        .layout.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }

        /* TOPBAR */
        .topbar { background: var(--surface); border-bottom: 1px solid var(--line); padding: 0 28px; height: 58px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; }
        .topbar-left, .topbar-right { display: flex; align-items: center; gap: 10px; }
        .breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-2, var(--ink-3)); }
        .breadcrumb a { color: var(--text-2, var(--ink-3)); text-decoration: none; transition: color .15s; }
        .breadcrumb a:hover { color: var(--text-1, var(--ink)); }
        .breadcrumb-sep { opacity: .3; }
        .breadcrumb-current { color: var(--text-1, var(--ink)); font-weight: 600; }
        .topbar-date { font-size: 11.5px; color: var(--text-2, var(--ink-3)); font-family: var(--font-mono); background: var(--surface-2); border: 1px solid var(--line); padding: 4px 10px; border-radius: 6px; }

        /* BUTTONS */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius); border: none; font-size: 13px; font-weight: 600; font-family: var(--font); cursor: pointer; transition: all .18s cubic-bezier(.4,0,.2,1); text-decoration: none; white-space: nowrap; letter-spacing: -.01em; }
        .btn svg { width: 14px; height: 14px; flex-shrink: 0; }
        .btn-primary { background: linear-gradient(135deg, var(--cyan), #0099cc); color: #080b12; box-shadow: var(--cyan-glow); }
        .btn-primary:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 8px 28px rgba(0,212,255,.4); }
        .btn-primary:active { transform: translateY(0); }
        .btn-gold { background: linear-gradient(135deg, var(--cyan), #0099cc); color: #080b12; box-shadow: var(--cyan-glow); }
        .btn-gold:hover { filter: brightness(1.1); transform: translateY(-1px); }
        .btn-ghost { background: transparent; color: var(--text-2, var(--ink-3)); border: 1px solid var(--line); }
        .btn-ghost:hover { background: var(--surface-2); border-color: rgba(255,255,255,.15); color: var(--text-1, var(--ink)); }
        .btn-danger { background: var(--red-lt); color: var(--red); border: 1px solid transparent; }
        .btn-danger:hover { background: rgba(248,113,113,.2); }
        .btn-sm { padding: 5px 11px; font-size: 12px; }
        .btn-lg { padding: 11px 22px; font-size: 14px; }

        /* CONTENT */
        .content { padding: 28px 30px 56px; }
        .page-header { margin-bottom: 26px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .page-header-left { flex: 1; }
        .page-title { font-size: 22px; font-weight: 700; font-family: var(--font-head, 'Space Grotesk', sans-serif); letter-spacing: -.4px; line-height: 1.2; background: linear-gradient(90deg, #fff 40%, var(--cyan)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .page-sub { font-size: 12.5px; color: var(--text-2, var(--ink-3)); margin-top: 3px; }

        /* STAT CARDS */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 22px; }
        .stat { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 20px; box-shadow: var(--shadow); position: relative; overflow: hidden; transition: box-shadow .2s, transform .2s, border-color .2s; }
        .stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), transparent); opacity: 0; transition: opacity .25s; }
        .stat::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: var(--radius-xl) var(--radius-xl) 0 0; opacity: 0; transition: opacity .25s; }
        .stat:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); border-color: rgba(255,255,255,.13); }
        .stat:hover::before { opacity: 1; }
        .stat-balance::after { background: linear-gradient(90deg, var(--gold), var(--gold-light)); }
        .stat-income::after  { background: linear-gradient(90deg, var(--green), #6EE7B7); }
        .stat-expense::after { background: linear-gradient(90deg, var(--red), #FCA5A5); }
        .stat-savings::after { background: linear-gradient(90deg, var(--gold), #F5C842); }

        .stat-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
        .stat-lbl { font-size: 11px; font-weight: 600; color: var(--text-3, var(--ink-3)); text-transform: uppercase; letter-spacing: .7px; }
        .stat-ico { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .stat-ico svg { width: 17px; height: 17px; }
        .ico-blue  { background: rgba(96,165,250,.12);  color: #60A5FA; }
        .ico-red   { background: var(--red-lt);          color: var(--red); }
        .ico-green { background: var(--green-lt);        color: var(--green); }
        .ico-amber { background: var(--amber-lt);        color: var(--amber); }
        .ico-gold  { background: var(--gold-dim);        color: var(--gold); }
        .stat-val { font-size: 26px; font-weight: 700; letter-spacing: -.8px; font-family: var(--font-mono); line-height: 1; margin-bottom: 8px; color: var(--text-1, var(--ink)); }
        .stat-meta { font-size: 11px; color: var(--text-2, var(--ink-3)); display: flex; align-items: center; gap: 5px; }

        /* PILLS / BADGES */
        .pill { display: inline-flex; align-items: center; gap: 2px; padding: 2px 7px; border-radius: 20px; font-size: 10.5px; font-weight: 700; letter-spacing: .02em; }
        .pill-up      { background: var(--red-dim);   color: var(--red);   }
        .pill-down    { background: var(--green-dim); color: var(--green); }
        .pill-neutral { background: var(--surface-2); color: var(--text-2, var(--ink-3)); }
        .badge-gold   { background: var(--gold-dim); color: var(--gold); font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 20px; letter-spacing: .04em; border: 1px solid rgba(201,168,76,.2); }

        /* CARDS */
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 22px 24px; box-shadow: var(--shadow); transition: border-color .2s; }
        .card:hover { border-color: rgba(255,255,255,.12); }
        .card-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .card-title { font-size: 14px; font-weight: 600; letter-spacing: -.2px; color: var(--text-1, var(--ink)); }
        .card-sub { font-size: 11.5px; color: var(--text-2, var(--ink-3)); margin-top: 2px; }
        .chart-wrap { position: relative; height: 260px; }

        /* TABLES */
        .tx-table { width: 100%; border-collapse: collapse; }
        .tx-table th { text-align: left; font-size: 10.5px; font-weight: 700; color: var(--text-3, var(--ink-3)); text-transform: uppercase; letter-spacing: .7px; padding: 0 0 12px; border-bottom: 1px solid var(--line); }
        .tx-table td { padding: 12px 0; border-bottom: 1px solid var(--line-2); transition: background .12s; }
        .tx-table tr:last-child td { border-bottom: none; }
        .tx-table tbody tr:hover td { background: var(--surface-2); }
        .tx-cat-wrap { display: flex; align-items: center; gap: 10px; }
        .tx-cat-icon { width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
        .tx-name { font-size: 13px; font-weight: 600; color: var(--text-1, var(--ink)); }
        .tx-desc { font-size: 11px; color: var(--text-2, var(--ink-3)); margin-top: 1px; }
        .tx-amount { font-family: var(--font-mono); font-size: 13px; font-weight: 600; }
        .tx-amount.expense { color: var(--red); }
        .tx-amount.income  { color: var(--green); }
        .tx-date { font-size: 11px; color: var(--text-2, var(--ink-3)); font-family: var(--font-mono); }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 10.5px; font-weight: 600; background: var(--surface-2); color: var(--text-2, var(--ink-3)); border: 1px solid var(--line); }

        /* MODAL */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.7); backdrop-filter: blur(10px); z-index: 200; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: var(--surface); border: 1px solid rgba(255,255,255,.12); border-radius: 22px; padding: 30px; width: 480px; max-width: 95vw; box-shadow: var(--shadow-lg); animation: slideUp .2s cubic-bezier(.4,0,.2,1); }
        .modal-hd { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; }
        .modal-title { font-size: 17px; font-weight: 700; letter-spacing: -.3px; color: var(--text-1, var(--ink)); }
        .modal-sub { font-size: 12px; color: var(--text-2, var(--ink-3)); margin-top: 3px; }
        .modal-close { width: 30px; height: 30px; border-radius: 8px; background: var(--surface-2); border: 1px solid var(--line); cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-2, var(--ink-3)); transition: all .15s; flex-shrink: 0; }
        .modal-close:hover { background: var(--surface-3); color: var(--text-1, var(--ink)); }
        .modal-actions { display: flex; gap: 10px; margin-top: 24px; }
        .modal-actions .btn { flex: 1; justify-content: center; padding: 10px; }

        /* FORMS */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { margin-bottom: 14px; }
        .field label { display: block; font-size: 11px; font-weight: 700; color: var(--text-2, var(--ink-3)); text-transform: uppercase; letter-spacing: .6px; margin-bottom: 6px; }
        .field input, .field select, .field textarea { width: 100%; padding: 9px 12px; border: 1.5px solid rgba(255,255,255,.1); border-radius: var(--radius); background: var(--surface-2); color: var(--text-1, var(--ink)); font-size: 13.5px; font-family: var(--font); outline: none; transition: border-color .15s, box-shadow .15s; }
        .field input:hover, .field select:hover { border-color: rgba(255,255,255,.18); }
        .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--gold); box-shadow: 0 0 0 3px var(--gold-dim); }
        .field input::placeholder, .field textarea::placeholder { color: var(--text-3, var(--ink-3)); opacity: 1; }
        .field select option { background: var(--surface); color: var(--text-1, var(--ink)); }

        .type-toggle { display: flex; gap: 8px; }
        .type-btn { flex: 1; padding: 9px; border-radius: var(--radius); border: 1.5px solid var(--line); background: transparent; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s; color: var(--text-2, var(--ink-3)); font-family: var(--font); }
        .type-btn.selected-expense { border-color: var(--red);   background: var(--red-lt);   color: var(--red);   }
        .type-btn.selected-income  { border-color: var(--green); background: var(--green-lt); color: var(--green); }

        /* PROGRESS */
        .progress-bar { height: 5px; background: var(--surface-3); border-radius: 99px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 99px; transition: width .7s cubic-bezier(.4,0,.2,1); background: linear-gradient(90deg, var(--cyan), var(--violet, #7c3aed)); }

        /* INSIGHTS */
        .insights { display: flex; flex-direction: column; gap: 10px; }
        .insight-item { background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 13px 16px; transition: border-color .15s; }
        .insight-item:hover { border-color: var(--gold); }
        .insight-label { font-size: 11px; color: var(--text-2, var(--ink-3)); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px; }
        .insight-val { font-size: 19px; font-weight: 700; font-family: var(--font-mono); letter-spacing: -.4px; color: var(--text-1, var(--ink)); }
        .insight-sub { font-size: 11px; color: var(--text-2, var(--ink-3)); margin-top: 2px; }
        .view-all { font-size: 12px; font-weight: 600; color: var(--gold); text-decoration: none; transition: opacity .15s; }
        .view-all:hover { opacity: .75; }

        /* MISC */
        .empty { text-align: center; padding: 48px 20px; color: var(--text-2, var(--ink-3)); }
        .empty-ico { font-size: 34px; margin-bottom: 10px; opacity: .5; }
        .empty-txt { font-size: 13px; }

        /* PAGINATION */
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid var(--line); }
        .pagination-info { font-size: 12px; color: var(--text-2, var(--ink-3)); }
        .pagination-btns { display: flex; gap: 4px; }
        .page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 8px; border-radius: var(--radius); font-size: 12.5px; font-weight: 500; text-decoration: none; color: var(--text-2, var(--ink-3)); border: 1px solid var(--line); background: var(--surface); transition: all .15s; }
        .page-btn:hover { background: var(--surface-2); border-color: var(--gold); color: var(--gold); }
        .page-btn.active { background: var(--gold); color: #1a0f00; border-color: var(--gold); box-shadow: var(--accent-glow); font-weight: 700; }
        .page-btn.disabled { opacity: .35; pointer-events: none; }

        /* TOAST */
        .toast { position: fixed; bottom: 24px; right: 24px; background: var(--surface); border: 1px solid var(--line); color: var(--text-1, var(--ink)); padding: 12px 18px; border-radius: 12px; font-size: 13px; font-weight: 500; box-shadow: var(--shadow-lg); z-index: 999; transform: translateY(12px); opacity: 0; transition: all .25s cubic-bezier(.4,0,.2,1); pointer-events: none; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.success { border-left: 3px solid var(--green); }
        .toast.error   { border-left: 3px solid var(--red);   }

        /* CATEGORY COLORS */
        .cat-food          { background: rgba(251,191,36,.12);  color: #FBBF24; }
        .cat-transport     { background: rgba(96,165,250,.12);  color: #60A5FA; }
        .cat-utilities     { background: rgba(52,211,153,.12);  color: #34D399; }
        .cat-entertainment { background: rgba(167,139,250,.12); color: #A78BFA; }
        .cat-shopping      { background: rgba(248,113,113,.12); color: #F87171; }
        .cat-health        { background: rgba(52,211,153,.12);  color: #34D399; }
        .cat-income        { background: var(--green-lt);       color: var(--green); }

        /* GLASS */
        .glass { background: rgba(255,255,255,.04); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,.09); border-radius: var(--radius-xl); }

        /* LEGACY GRID HELPERS */
        .grid-4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 18px; }
        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px; }
        .green { color: var(--green) !important; }
        .red   { color: var(--red)   !important; }

        /* FAB */
        .fab { position: fixed; bottom: 30px; right: 30px; background: var(--gold); padding: 18px; border-radius: 50%; color: #1a0f00; font-size: 22px; box-shadow: var(--gold-glow); transition: transform .2s, background .2s; cursor: pointer; border: none; display: flex; align-items: center; justify-content: center; }
        .fab:hover { transform: scale(1.08); background: var(--gold-light); }

        #centerValue { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); font-weight: 700; font-family: var(--font-mono); color: var(--text-1, var(--ink)); }

        /* DIVIDERS */
        .divider { height: 1px; background: var(--line); margin: 20px 0; }
        .section-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-2, var(--ink-3)); letter-spacing: .7px; }

        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 20px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.2); }

        /* HAMBURGER */
        .hamburger { display: none; position: fixed; top: 14px; left: 14px; z-index: 100; background: var(--surface); border: 1px solid var(--line); color: var(--text-1, var(--ink)); width: 40px; height: 40px; border-radius: 10px; cursor: pointer; align-items: center; justify-content: center; box-shadow: var(--shadow-md); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); backdrop-filter: blur(4px); z-index: 40; }

        /* ANIMATIONS */
        @keyframes slideUp { from { opacity: 0; transform: translateY(16px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes fadeIn  { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        .anim    { animation: fadeIn .35s cubic-bezier(.4,0,.2,1) both; }
        .anim-d1 { animation-delay: .06s; }
        .anim-d2 { animation-delay: .12s; }
        .anim-d3 { animation-delay: .18s; }
        .anim-d4 { animation-delay: .24s; }

        /* RESPONSIVE */
        @media (max-width: 900px) {
            .hamburger { display: flex; }
            .main { margin-left: 0 !important; padding-top: 64px; }
            .content { padding: 20px 16px 44px; }
            .topbar { padding: 0 16px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .form-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 600px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .stat-val { font-size: 20px; }
            .content { padding: 16px 12px 40px; }
        }
    </style>
</head>
<body>
<button class="hamburger" onclick="toggleSidebar()" aria-label="Toggle menu">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<div class="layout" id="layout">