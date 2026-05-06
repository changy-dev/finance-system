<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* ── HANDLE ASSET ACTIONS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_asset') {
        $name     = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $category = $conn->real_escape_string($_POST['category'] ?? 'other');
        $value    = (float) ($_POST['value'] ?? 0);
        $note     = $conn->real_escape_string(trim($_POST['note'] ?? ''));
        if ($name && $value > 0) {
            $conn->query("INSERT INTO assets (user_id, name, category, value, note, created_at)
                          VALUES ($user_id, '$name', '$category', $value, '$note', NOW())");
        }
    } elseif ($action === 'delete_asset') {
        $id = (int) ($_POST['asset_id'] ?? 0);
        $conn->query("DELETE FROM assets WHERE asset_id = $id AND user_id = $user_id");
    } elseif ($action === 'update_asset') {
        $id    = (int) ($_POST['asset_id'] ?? 0);
        $value = (float) ($_POST['value'] ?? 0);
        $note  = $conn->real_escape_string(trim($_POST['note'] ?? ''));
        if ($id && $value > 0) {
            $conn->query("UPDATE assets SET value = $value, note = '$note', updated_at = NOW()
                          WHERE asset_id = $id AND user_id = $user_id");
        }
    } elseif ($action === 'snapshot') {
        /* Save today's net worth snapshot */
        $today = date('Y-m-d');
        $conn->query("INSERT INTO net_worth_snapshots (user_id, snapshot_date, total_assets, total_liabilities, net_worth)
            SELECT $user_id, '$today',
                COALESCE((SELECT SUM(value) FROM assets WHERE user_id = $user_id), 0) +
                COALESCE((SELECT SUM(current_amount) FROM savings_goals WHERE user_id = $user_id), 0),
                COALESCE((SELECT SUM(remaining_balance) FROM debts_loans WHERE user_id = $user_id AND status != 'paid'), 0),
                (COALESCE((SELECT SUM(value) FROM assets WHERE user_id = $user_id), 0) +
                 COALESCE((SELECT SUM(current_amount) FROM savings_goals WHERE user_id = $user_id), 0)) -
                 COALESCE((SELECT SUM(remaining_balance) FROM debts_loans WHERE user_id = $user_id AND status != 'paid'), 0)
            ON DUPLICATE KEY UPDATE
                total_assets     = VALUES(total_assets),
                total_liabilities= VALUES(total_liabilities),
                net_worth        = VALUES(net_worth)");
    }

    header('Location: net_worth.php');
    exit;
}

/* ── FETCH ASSETS ── */
$assets     = [];
$totalAssets = 0;
$assetsByCategory = [];

$res = $conn->query("SELECT * FROM assets WHERE user_id = $user_id ORDER BY category, name");
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $assets[] = $row;
        $totalAssets += (float) $row['value'];
        $cat = $row['category'];
        $assetsByCategory[$cat] = ($assetsByCategory[$cat] ?? 0) + (float) $row['value'];
    }
}

/* ── FETCH SAVINGS GOALS (as assets) ── */
$savingsTotal = 0;
$savingsGoals = [];
$sg = $conn->query("SELECT * FROM savings_goals WHERE user_id = $user_id ORDER BY goal_name");
if ($sg && $sg->num_rows > 0) {
    while ($row = $sg->fetch_assoc()) {
        $savingsGoals[] = $row;
        $savingsTotal  += (float) ($row['current_amount'] ?? 0);
    }
}
$totalAssets += $savingsTotal;
if ($savingsTotal > 0) $assetsByCategory['savings'] = $savingsTotal;

/* ── FETCH DEBTS/LOANS ── */
$debts       = [];
$totalDebts  = 0;
$dl = $conn->query("SELECT * FROM debts_loans WHERE user_id = $user_id AND status != 'paid' ORDER BY lender_name");
if ($dl && $dl->num_rows > 0) {
    while ($row = $dl->fetch_assoc()) {
        $debts[]     = $row;
        $totalDebts += (float) ($row['remaining_balance'] ?? 0);
    }
}

/* ── NET WORTH ── */
$netWorth = $totalAssets - $totalDebts;

/* ── SNAPSHOT HISTORY (last 12 months) ── */
$snapDates  = [];
$snapAssets = [];
$snapDebt   = [];
$snapNet    = [];

$snaps = $conn->query("SELECT * FROM net_worth_snapshots
    WHERE user_id = $user_id
    ORDER BY snapshot_date ASC
    LIMIT 24");
if ($snaps && $snaps->num_rows > 0) {
    while ($row = $snaps->fetch_assoc()) {
        $snapDates[]  = date('M d', strtotime($row['snapshot_date']));
        $snapAssets[] = (float) $row['total_assets'];
        $snapDebt[]   = (float) $row['total_liabilities'];
        $snapNet[]    = (float) $row['net_worth'];
    }
}

/* ── CHANGE VS LAST SNAPSHOT ── */
$prevNet    = count($snapNet) >= 2 ? $snapNet[count($snapNet) - 2] : null;
$netChange  = $prevNet !== null ? $netWorth - $prevNet : null;
$netChangePct = ($prevNet && $prevNet != 0) ? round(($netChange / abs($prevNet)) * 100, 1) : null;

/* ── ASSET CATEGORY META ── */
$categoryMeta = [
    'cash'        => ['label' => 'Cash & Bank',      'emoji' => '🏦', 'color' => '#10b981'],
    'investment'  => ['label' => 'Investments',       'emoji' => '📈', 'color' => '#6366f1'],
    'property'    => ['label' => 'Property',          'emoji' => '🏠', 'color' => '#f59e0b'],
    'vehicle'     => ['label' => 'Vehicle',           'emoji' => '🚗', 'color' => '#0ea5e9'],
    'electronics' => ['label' => 'Electronics',       'emoji' => '💻', 'color' => '#8b5cf6'],
    'savings'     => ['label' => 'Savings Goals',     'emoji' => '🎯', 'color' => '#14b8a6'],
    'other'       => ['label' => 'Other Assets',      'emoji' => '📦', 'color' => '#94a3b8'],
];

/* JSON for charts */
$jDates  = json_encode($snapDates);
$jAssets = json_encode($snapAssets);
$jDebt   = json_encode($snapDebt);
$jNet    = json_encode($snapNet);

$jCatLabels = json_encode(array_map(fn($k) => $categoryMeta[$k]['label'] ?? ucfirst($k), array_keys($assetsByCategory)));
$jCatValues = json_encode(array_values($assetsByCategory));
$jCatColors = json_encode(array_map(fn($k) => $categoryMeta[$k]['color'] ?? '#94a3b8', array_keys($assetsByCategory)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Net Worth — Fintrack</title>
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
    --teal:       #14b8a6;
    --teal-lt:    #f0fdfa;
    --sidebar-w:  240px;
    --sidebar-collapsed: 64px;
    --r:          10px;
    --r-lg:       14px;
    --shadow:     0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow-md:  0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
}

body { font-family: 'DM Sans', sans-serif; background: var(--page); color: var(--ink); font-size: 14px; line-height: 1.5; }
.layout { display: flex; min-height: 100vh; }

/* ── SIDEBAR (same as dashboard) ── */
.sidebar { width: var(--sidebar-w); flex-shrink: 0; background: var(--ink); position: fixed; top: 0; left: 0; height: 100vh; display: flex; flex-direction: column; z-index: 50; overflow: hidden; transition: width .25s cubic-bezier(.4,0,.2,1); }
.sidebar.collapsed { width: var(--sidebar-collapsed); }
.sidebar-brand { padding: 20px 14px 18px; border-bottom: 1px solid rgba(255,255,255,.07); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; min-height: 72px; }
.brand-mark { display: flex; align-items: center; gap: 10px; overflow: hidden; }
.brand-icon { width: 34px; height: 34px; background: var(--accent); border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.brand-icon svg { width: 18px; height: 18px; color: #fff; }
.brand-text { overflow: hidden; white-space: nowrap; transition: opacity .2s, width .2s; }
.brand-name { font-size: 15px; font-weight: 600; color: #fff; letter-spacing: -.2px; }
.brand-tagline { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 1px; }
.sidebar-toggle { width: 26px; height: 26px; border-radius: 6px; background: rgba(255,255,255,.07); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.4); transition: background .15s, color .15s, transform .25s; flex-shrink: 0; }
.sidebar-toggle:hover { background: rgba(255,255,255,.14); color: rgba(255,255,255,.9); }
.sidebar.collapsed .sidebar-toggle { transform: rotate(180deg); margin: 0 auto; }
.sidebar.collapsed .brand-text { opacity: 0; width: 0; pointer-events: none; }
.sidebar-nav { padding: 12px 8px; flex: 1; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.1) transparent; }
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 99px; }
.nav-section-label { font-size: 10px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: rgba(255,255,255,.25); padding: 0 8px; margin: 8px 0 4px; white-space: nowrap; overflow: hidden; transition: opacity .2s; }
.sidebar.collapsed .nav-section-label { opacity: 0; pointer-events: none; height: 0; margin: 0; padding: 0; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 7px; color: rgba(255,255,255,.5); text-decoration: none; font-size: 13px; font-weight: 400; transition: background .15s, color .15s; margin-bottom: 1px; white-space: nowrap; overflow: hidden; }
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item:hover { background: rgba(255,255,255,.06); color: rgba(255,255,255,.85); }
.nav-item.active { background: rgba(99,102,241,.2); color: #a5b4fc; font-weight: 500; }
.nav-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; transition: opacity .2s; flex: 1; }
.nav-badge { margin-left: auto; background: rgba(99,102,241,.35); color: #a5b4fc; font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 10px; flex-shrink: 0; }
.sidebar.collapsed .nav-label, .sidebar.collapsed .nav-badge { opacity: 0; width: 0; pointer-events: none; }
.sidebar.collapsed .nav-item { justify-content: center; padding: 10px; }
.sidebar-footer { padding: 10px 8px 14px; border-top: 1px solid rgba(255,255,255,.06); flex-shrink: 0; }
.user-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 7px; cursor: pointer; transition: background .15s; overflow: hidden; white-space: nowrap; }
.user-row:hover { background: rgba(255,255,255,.06); }
.user-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #fff; flex-shrink: 0; }
.user-name { font-size: 12.5px; color: rgba(255,255,255,.7); font-weight: 500; }
.user-role { font-size: 11px; color: rgba(255,255,255,.3); }
.sidebar.collapsed .user-row { justify-content: center; padding: 8px; }

/* ── MAIN ── */
.main { flex: 1; margin-left: var(--sidebar-w); display: flex; flex-direction: column; min-height: 100vh; transition: margin-left .25s cubic-bezier(.4,0,.2,1); }
.layout.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }

/* ── TOPBAR ── */
.topbar { background: var(--surface); border-bottom: 1px solid var(--line); padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; }
.topbar-left { display: flex; align-items: center; gap: 10px; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-3); }
.breadcrumb-current { color: var(--ink); font-weight: 500; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.topbar-date { font-size: 12px; color: var(--ink-3); font-family: 'DM Mono', monospace; }

/* ── BUTTONS ── */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 7px; border: none; font-size: 13px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all .15s; text-decoration: none; white-space: nowrap; }
.btn svg { width: 14px; height: 14px; }
.btn-ghost { background: transparent; color: var(--ink-2); border: 1px solid var(--line); }
.btn-ghost:hover { background: var(--line-2); }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #4f46e5; box-shadow: 0 4px 12px rgba(99,102,241,.3); }
.btn-teal { background: var(--teal); color: #fff; }
.btn-teal:hover { background: #0d9488; box-shadow: 0 4px 12px rgba(20,184,166,.3); }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.btn-danger { background: var(--red-lt); color: var(--red); border: 1px solid #fecdd3; }
.btn-danger:hover { background: var(--red); color: #fff; }

/* ── CONTENT ── */
.content { padding: 28px 32px 48px; }
.page-header { margin-bottom: 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.page-header-text .page-title { font-size: 22px; font-weight: 600; letter-spacing: -.4px; }
.page-header-text .page-sub { font-size: 13px; color: var(--ink-3); margin-top: 2px; }
.page-header-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* ── NET WORTH HERO ── */
.nw-hero { background: var(--ink); border-radius: var(--r-lg); padding: 28px 32px; margin-bottom: 20px; position: relative; overflow: hidden; }
.nw-hero::before { content: ''; position: absolute; top: -60px; right: -60px; width: 220px; height: 220px; background: radial-gradient(circle, rgba(99,102,241,.25) 0%, transparent 70%); pointer-events: none; }
.nw-hero::after  { content: ''; position: absolute; bottom: -40px; left: 40%; width: 160px; height: 160px; background: radial-gradient(circle, rgba(20,184,166,.15) 0%, transparent 70%); pointer-events: none; }
.nw-hero-inner { position: relative; z-index: 1; display: grid; grid-template-columns: 1fr auto; gap: 24px; align-items: center; }
.nw-hero-label { font-size: 11px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: rgba(255,255,255,.4); margin-bottom: 8px; }
.nw-hero-value { font-size: 42px; font-weight: 600; font-family: 'DM Mono', monospace; letter-spacing: -1.5px; line-height: 1; color: #fff; }
.nw-hero-value.positive { color: #6ee7b7; }
.nw-hero-value.negative { color: #fca5a5; }
.nw-hero-change { margin-top: 10px; display: flex; align-items: center; gap: 8px; }
.nw-change-pill { display: inline-flex; align-items: center; gap: 3px; padding: 3px 9px; border-radius: 20px; font-size: 12px; font-weight: 600; font-family: 'DM Mono', monospace; }
.nw-change-pill.up   { background: rgba(16,185,129,.2); color: #6ee7b7; }
.nw-change-pill.down { background: rgba(244,63,94,.2);  color: #fca5a5; }
.nw-change-pill.flat { background: rgba(255,255,255,.1); color: rgba(255,255,255,.5); }
.nw-change-label { font-size: 12px; color: rgba(255,255,255,.4); }
.nw-hero-pillars { display: flex; gap: 2px; }
.nw-pillar { background: rgba(255,255,255,.06); border-radius: 10px; padding: 16px 22px; text-align: center; min-width: 140px; }
.nw-pillar-label { font-size: 10.5px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase; color: rgba(255,255,255,.35); margin-bottom: 6px; }
.nw-pillar-value { font-size: 18px; font-weight: 600; font-family: 'DM Mono', monospace; }
.nw-pillar.assets .nw-pillar-value  { color: #6ee7b7; }
.nw-pillar.debts  .nw-pillar-value  { color: #fca5a5; }

/* ── STATS ROW ── */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
.stat { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: 18px 20px; box-shadow: var(--shadow); transition: box-shadow .2s, transform .2s; }
.stat:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.stat-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; }
.stat-lbl { font-size: 11px; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: .5px; }
.stat-ico { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.stat-val { font-size: 20px; font-weight: 600; letter-spacing: -.5px; font-family: 'DM Mono', monospace; line-height: 1; margin-bottom: 6px; }
.stat-meta { font-size: 11.5px; color: var(--ink-3); }

/* ── CHART GRID ── */
.charts-row { display: grid; grid-template-columns: 1fr 320px; gap: 14px; margin-bottom: 14px; }

/* ── CARD ── */
.card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: 20px 22px; box-shadow: var(--shadow); }
.card-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.card-title { font-size: 14px; font-weight: 600; letter-spacing: -.2px; }
.card-sub { font-size: 12px; color: var(--ink-3); margin-top: 1px; }
.chart-wrap { position: relative; height: 260px; }

/* ── DONUT ── */
.donut-wrap { position: relative; display: flex; align-items: center; justify-content: center; height: 200px; }
.donut-center { position: absolute; text-align: center; pointer-events: none; }
.donut-lbl { font-size: 10px; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: .5px; }
.donut-val { font-size: 16px; font-weight: 600; font-family: 'DM Mono', monospace; margin-top: 2px; }
.legend { margin-top: 14px; display: flex; flex-direction: column; gap: 6px; }
.legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--ink-2); }
.legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.legend-lbl { flex: 1; }
.legend-val { font-family: 'DM Mono', monospace; font-size: 11.5px; color: var(--ink-3); }
.legend-pct { font-size: 10.5px; color: var(--ink-3); width: 34px; text-align: right; }

/* ── BOTTOM GRID ── */
.bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* ── ASSET LIST ── */
.asset-list { display: flex; flex-direction: column; gap: 2px; }
.asset-item { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: 8px; transition: background .12s; }
.asset-item:hover { background: var(--line-2); }
.asset-emoji { width: 34px; height: 34px; border-radius: 8px; background: var(--line-2); display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.asset-info { flex: 1; min-width: 0; }
.asset-name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.asset-note { font-size: 11.5px; color: var(--ink-3); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.asset-value { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; color: var(--green); flex-shrink: 0; }
.asset-actions { display: flex; gap: 4px; opacity: 0; transition: opacity .15s; }
.asset-item:hover .asset-actions { opacity: 1; }
.icon-btn { width: 26px; height: 26px; border-radius: 5px; border: none; background: transparent; color: var(--ink-3); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .12s, color .12s; }
.icon-btn:hover { background: var(--line); color: var(--ink); }
.icon-btn.danger:hover { background: var(--red-lt); color: var(--red); }
.icon-btn svg { width: 13px; height: 13px; }

/* ── DEBT LIST ── */
.debt-item { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: 8px; transition: background .12s; }
.debt-item:hover { background: var(--line-2); }
.debt-icon { width: 34px; height: 34px; border-radius: 8px; background: var(--red-lt); display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.debt-info { flex: 1; min-width: 0; }
.debt-name { font-size: 13px; font-weight: 500; }
.debt-meta { font-size: 11.5px; color: var(--ink-3); margin-top: 1px; }
.debt-balance { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; color: var(--red); flex-shrink: 0; }
.debt-bar-wrap { margin-top: 6px; }
.debt-bar-bg { height: 3px; background: var(--line); border-radius: 99px; overflow: hidden; }
.debt-bar-fill { height: 100%; background: var(--red); border-radius: 99px; transition: width .6s ease; }
.debt-bar-label { display: flex; justify-content: space-between; font-size: 10px; color: var(--ink-3); margin-top: 3px; font-family: 'DM Mono', monospace; }

/* ── SAVINGS GOALS ── */
.goal-item { display: flex; flex-direction: column; gap: 6px; padding: 12px 14px; background: var(--line-2); border-radius: 9px; }
.goal-header { display: flex; align-items: center; justify-content: space-between; }
.goal-name { font-size: 13px; font-weight: 500; }
.goal-amounts { font-size: 12px; font-family: 'DM Mono', monospace; color: var(--ink-3); }
.goal-amounts span { color: var(--ink); font-weight: 500; }
.goal-bar-bg { height: 5px; background: var(--line); border-radius: 99px; overflow: hidden; }
.goal-bar-fill { height: 100%; border-radius: 99px; background: var(--teal); transition: width .6s ease; }
.goal-pct { font-size: 10.5px; color: var(--ink-3); text-align: right; font-family: 'DM Mono', monospace; }
.goals-list { display: flex; flex-direction: column; gap: 8px; }

/* ── EMPTY ── */
.empty { text-align: center; padding: 32px 20px; color: var(--ink-3); }
.empty-ico { font-size: 28px; margin-bottom: 8px; }
.empty-txt { font-size: 13px; }

/* ── SEG CONTROL ── */
.seg { display: flex; gap: 2px; background: var(--line-2); border-radius: 7px; padding: 3px; }
.seg-btn { padding: 4px 10px; border-radius: 5px; border: none; font-size: 12px; font-weight: 500; font-family: 'DM Sans', sans-serif; cursor: pointer; background: transparent; color: var(--ink-3); transition: all .15s; }
.seg-btn.active { background: var(--surface); color: var(--ink); box-shadow: 0 1px 2px rgba(0,0,0,.1); }

/* ── MODAL ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(10,12,18,.55); backdrop-filter: blur(4px); z-index: 200; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 28px; width: 440px; max-width: 95vw; box-shadow: 0 20px 60px rgba(0,0,0,.18); animation: slideUp .22s ease; }
.modal-hd { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
.modal-title { font-size: 16px; font-weight: 600; }
.modal-sub { font-size: 12px; color: var(--ink-3); margin-top: 2px; }
.modal-close { width: 28px; height: 28px; border-radius: 6px; background: var(--line-2); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--ink-3); transition: background .15s; }
.modal-close:hover { background: var(--line); color: var(--ink); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { margin-bottom: 12px; }
.field label { display: block; font-size: 11.5px; font-weight: 600; color: var(--ink-3); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 5px; }
.field input, .field select, .field textarea { width: 100%; padding: 8px 11px; border: 1px solid var(--line); border-radius: 7px; background: var(--surface); color: var(--ink); font-size: 13.5px; font-family: 'DM Sans', sans-serif; transition: border .15s, box-shadow .15s; appearance: none; }
.field input:focus, .field select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.modal-actions .btn { flex: 1; justify-content: center; padding: 10px; }

/* ── TOAST ── */
.toast { position: fixed; bottom: 24px; right: 24px; background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 9px; font-size: 13px; font-weight: 500; box-shadow: 0 8px 24px rgba(0,0,0,.25); animation: slideUp .25s ease; z-index: 999; display: flex; align-items: center; gap: 8px; }
.toast.success::before { content: '✓'; background: var(--green); width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }

/* ── ANIMATIONS ── */
@keyframes slideUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
.anim    { animation: fadeIn .35s ease both; }
.anim-d1 { animation-delay: .05s; }
.anim-d2 { animation-delay: .1s; }
.anim-d3 { animation-delay: .15s; }
.anim-d4 { animation-delay: .2s; }

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--line); border-radius: 99px; }

/* ── RESPONSIVE ── */
@media (max-width: 1100px) { .charts-row { grid-template-columns: 1fr; } .bottom-row { grid-template-columns: 1fr; } .stats-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 768px)  { .sidebar { display: none; } .main { margin-left: 0 !important; } .content { padding: 20px 16px 40px; } .topbar { padding: 0 16px; } .nw-hero-inner { grid-template-columns: 1fr; } .nw-hero-pillars { flex-direction: column; } .stats-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px)  { .stats-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="layout" id="layout">

    <!-- SIDEBAR -->
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
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Overview</div>
            <a href="dashboard.php" class="nav-item" title="Dashboard">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
                <span class="nav-label">Dashboard</span>
            </a>
            <a href="net_worth.php" class="nav-item active" title="Net Worth">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                <span class="nav-label">Net Worth</span>
                <span class="nav-badge">New</span>
            </a>

            <div class="nav-section-label" style="margin-top:14px;">Money</div>
            <a href="transactions.php" class="nav-item" title="Transactions">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span class="nav-label">Transactions</span>
            </a>
            <a href="budget.php" class="nav-item" title="Budget">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                <span class="nav-label">Budget</span>
            </a>
            <a href="savings_goals.php" class="nav-item" title="Savings Goals">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span class="nav-label">Savings Goals</span>
            </a>
            <a href="debts_loans.php" class="nav-item" title="Debts &amp; Loans">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                <span class="nav-label">Debts &amp; Loans</span>
            </a>

            <div class="nav-section-label" style="margin-top:14px;">Tools</div>
            <a href="health_score.php" class="nav-item" title="Health Score">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                <span class="nav-label">Health Score</span>
            </a>
            <a href="export.php" class="nav-item" title="Export Data">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <span class="nav-label">Export Data</span>
            </a>

            <div class="nav-section-label" style="margin-top:14px;">Account</div>
            <a href="settings.php" class="nav-item" title="Settings">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="nav-label">Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">U</div>
                <div class="nav-label">
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
                <button class="btn btn-ghost" id="topbarMenuBtn" style="padding:6px 10px;font-size:12px;gap:5px;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    Menu
                </button>
                <div class="breadcrumb">
                    <a href="dashboard.php" style="color:var(--ink-3);text-decoration:none;">Home</a>
                    <span style="color:var(--line);margin:0 4px;">›</span>
                    <span class="breadcrumb-current">Net Worth</span>
                </div>
            </div>
            <div class="topbar-right">
                <span class="topbar-date"><?php echo date('D, d M Y'); ?></span>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="snapshot">
                    <button type="submit" class="btn btn-ghost" title="Save today's snapshot">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                        Save Snapshot
                    </button>
                </form>
                <button class="btn btn-primary" onclick="openAssetModal()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Asset
                </button>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <div class="page-header">
                <div class="page-header-text">
                    <h1 class="page-title">Net Worth Tracker</h1>
                    <p class="page-sub">Assets + Savings minus Debts · updated <?php echo date('d M Y'); ?></p>
                </div>
            </div>

            <!-- NET WORTH HERO -->
            <div class="nw-hero anim">
                <div class="nw-hero-inner">
                    <div>
                        <div class="nw-hero-label">Total Net Worth</div>
                        <div class="nw-hero-value <?php echo $netWorth >= 0 ? 'positive' : 'negative'; ?>">
                            KES <?php echo number_format($netWorth); ?>
                        </div>
                        <div class="nw-hero-change">
                            <?php if ($netChange !== null): ?>
                                <span class="nw-change-pill <?php echo $netChange >= 0 ? 'up' : 'down'; ?>">
                                    <?php echo $netChange >= 0 ? '↑' : '↓'; ?>
                                    KES <?php echo number_format(abs($netChange)); ?>
                                </span>
                                <span class="nw-change-label">
                                    <?php echo $netChangePct !== null ? abs($netChangePct).'% ' : ''; ?>vs last snapshot
                                </span>
                            <?php else: ?>
                                <span class="nw-change-pill flat">— No prior snapshot</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="nw-hero-pillars">
                        <div class="nw-pillar assets">
                            <div class="nw-pillar-label">Total Assets</div>
                            <div class="nw-pillar-value">KES <?php echo number_format($totalAssets); ?></div>
                        </div>
                        <div class="nw-pillar debts">
                            <div class="nw-pillar-label">Total Debts</div>
                            <div class="nw-pillar-value">KES <?php echo number_format($totalDebts); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STATS -->
            <div class="stats-row">
                <div class="stat anim anim-d1">
                    <div class="stat-top">
                        <div class="stat-lbl">Manual Assets</div>
                        <div class="stat-ico" style="background:#eef2ff;font-size:16px;">🏦</div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($totalAssets - $savingsTotal); ?></div>
                    <div class="stat-meta"><?php echo count($assets); ?> asset<?php echo count($assets) != 1 ? 's' : ''; ?> tracked</div>
                </div>
                <div class="stat anim anim-d2">
                    <div class="stat-top">
                        <div class="stat-lbl">Savings Goals</div>
                        <div class="stat-ico" style="background:#f0fdfa;font-size:16px;">🎯</div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($savingsTotal); ?></div>
                    <div class="stat-meta"><?php echo count($savingsGoals); ?> active goal<?php echo count($savingsGoals) != 1 ? 's' : ''; ?></div>
                </div>
                <div class="stat anim anim-d3">
                    <div class="stat-top">
                        <div class="stat-lbl">Total Liabilities</div>
                        <div class="stat-ico" style="background:#fff1f2;font-size:16px;">💳</div>
                    </div>
                    <div class="stat-val" style="color:var(--red);">KES <?php echo number_format($totalDebts); ?></div>
                    <div class="stat-meta"><?php echo count($debts); ?> active debt<?php echo count($debts) != 1 ? 's' : ''; ?></div>
                </div>
                <div class="stat anim anim-d4">
                    <div class="stat-top">
                        <div class="stat-lbl">Snapshots Saved</div>
                        <div class="stat-ico" style="background:#fffbeb;font-size:16px;">📷</div>
                    </div>
                    <div class="stat-val"><?php echo count($snapNet); ?></div>
                    <div class="stat-meta">Click "Save Snapshot" to log today</div>
                </div>
            </div>

            <!-- CHARTS ROW -->
            <div class="charts-row anim">
                <!-- NET WORTH OVER TIME -->
                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Net Worth Over Time</div>
                            <div class="card-sub">Assets vs Liabilities trend from snapshots</div>
                        </div>
                        <div class="seg" id="chartSeg">
                            <button class="seg-btn active" data-view="net">Net</button>
                            <button class="seg-btn" data-view="stacked">Stacked</button>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <?php if (count($snapNet) < 2): ?>
                        <div class="empty" style="padding-top:60px;">
                            <div class="empty-ico">📸</div>
                            <div class="empty-txt">Save at least 2 snapshots to see your trend.<br>Click <strong>Save Snapshot</strong> in the top bar.</div>
                        </div>
                        <?php else: ?>
                        <canvas id="nwChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ASSET BREAKDOWN DONUT -->
                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Asset Breakdown</div>
                            <div class="card-sub">By category</div>
                        </div>
                    </div>
                    <?php if ($totalAssets > 0): ?>
                    <div class="donut-wrap">
                        <canvas id="assetDonut"></canvas>
                        <div class="donut-center">
                            <div class="donut-lbl">Assets</div>
                            <div class="donut-val" id="donutVal">KES <?php echo number_format($totalAssets); ?></div>
                        </div>
                    </div>
                    <div class="legend" id="assetLegend"></div>
                    <?php else: ?>
                    <div class="empty"><div class="empty-ico">📊</div><div class="empty-txt">No assets yet</div></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BOTTOM ROW: Assets + Debts + Goals -->
            <div class="bottom-row">

                <!-- ASSETS -->
                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Assets</div>
                            <div class="card-sub">Cash, investments, property & more</div>
                        </div>
                        <button class="btn btn-ghost btn-sm" onclick="openAssetModal()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add
                        </button>
                    </div>
                    <?php if (count($assets) > 0): ?>
                    <div class="asset-list">
                        <?php foreach ($assets as $a):
                            $cat  = $a['category'];
                            $meta = $categoryMeta[$cat] ?? $categoryMeta['other'];
                        ?>
                        <div class="asset-item" id="asset-row-<?php echo $a['asset_id']; ?>">
                            <div class="asset-emoji"><?php echo $meta['emoji']; ?></div>
                            <div class="asset-info">
                                <div class="asset-name"><?php echo htmlspecialchars($a['name']); ?></div>
                                <div class="asset-note"><?php echo $a['note'] ? htmlspecialchars($a['note']) : $meta['label']; ?></div>
                            </div>
                            <div class="asset-value">+KES <?php echo number_format($a['value']); ?></div>
                            <div class="asset-actions">
                                <button class="icon-btn" title="Edit" onclick='openEditModal(<?php echo json_encode($a); ?>)'>
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                </button>
                                <button class="icon-btn danger" title="Delete" onclick="deleteAsset(<?php echo $a['asset_id']; ?>)">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-ico">🏦</div>
                        <div class="empty-txt">No assets added yet.<br>Track cash, investments, property & more.</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- DEBTS + GOALS -->
                <div style="display:flex;flex-direction:column;gap:14px;">

                    <!-- DEBTS -->
                    <div class="card">
                        <div class="card-hd">
                            <div>
                                <div class="card-title">Active Debts &amp; Loans</div>
                                <div class="card-sub">Pulled from Debts &amp; Loans page</div>
                            </div>
                            <a href="debts_loans.php" class="btn btn-ghost btn-sm">Manage</a>
                        </div>
                        <?php if (count($debts) > 0): ?>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <?php foreach ($debts as $d):
                                $orig = (float)($d['original_amount'] ?? $d['remaining_balance']);
                                $rem  = (float)($d['remaining_balance'] ?? 0);
                                $paid = $orig > 0 ? max(0, min(100, round(($orig - $rem) / $orig * 100))) : 0;
                                $rate = isset($d['interest_rate']) ? number_format((float)$d['interest_rate'],1).'%' : '';
                            ?>
                            <div class="debt-item">
                                <div class="debt-icon">💳</div>
                                <div class="debt-info">
                                    <div class="debt-name"><?php echo htmlspecialchars($d['lender_name'] ?? 'Loan'); ?></div>
                                    <div class="debt-meta"><?php echo htmlspecialchars($d['loan_type'] ?? 'Loan'); ?><?php echo $rate ? ' · '.$rate.' interest' : ''; ?></div>
                                    <div class="debt-bar-wrap">
                                        <div class="debt-bar-bg"><div class="debt-bar-fill" style="width:<?php echo $paid; ?>%"></div></div>
                                        <div class="debt-bar-label"><span><?php echo $paid; ?>% paid</span><span>KES <?php echo number_format($rem); ?> left</span></div>
                                    </div>
                                </div>
                                <div class="debt-balance">-KES <?php echo number_format($rem); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty"><div class="empty-ico">🎉</div><div class="empty-txt">No active debts — debt-free!</div></div>
                        <?php endif; ?>
                    </div>

                    <!-- SAVINGS GOALS -->
                    <div class="card">
                        <div class="card-hd">
                            <div>
                                <div class="card-title">Savings Goals</div>
                                <div class="card-sub">Counted as assets toward net worth</div>
                            </div>
                            <a href="savings_goals.php" class="btn btn-ghost btn-sm">Manage</a>
                        </div>
                        <?php if (count($savingsGoals) > 0): ?>
                        <div class="goals-list">
                            <?php foreach ($savingsGoals as $g):
                                $curr   = (float)($g['current_amount'] ?? 0);
                                $target = (float)($g['target_amount'] ?? 1);
                                $pct    = $target > 0 ? min(100, round($curr / $target * 100)) : 0;
                            ?>
                            <div class="goal-item">
                                <div class="goal-header">
                                    <div class="goal-name"><?php echo htmlspecialchars($g['goal_name'] ?? 'Goal'); ?></div>
                                    <div class="goal-amounts">
                                        <span>KES <?php echo number_format($curr); ?></span> / KES <?php echo number_format($target); ?>
                                    </div>
                                </div>
                                <div class="goal-bar-bg"><div class="goal-bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
                                <div class="goal-pct"><?php echo $pct; ?>% complete</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty"><div class="empty-ico">🎯</div><div class="empty-txt">No savings goals yet</div></div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->

<!-- ADD ASSET MODAL -->
<div class="modal-overlay" id="assetModal">
    <div class="modal">
        <div class="modal-hd">
            <div>
                <div class="modal-title">Add Asset</div>
                <div class="modal-sub">Track a new asset toward your net worth</div>
            </div>
            <button class="modal-close" onclick="closeAssetModal()">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="assetForm">
            <input type="hidden" name="action" value="add_asset">
            <div class="form-row">
                <div class="field">
                    <label>Asset Name</label>
                    <input type="text" name="name" placeholder="e.g. Equity Bank Savings" required>
                </div>
                <div class="field">
                    <label>Category</label>
                    <select name="category">
                        <option value="cash">🏦 Cash &amp; Bank</option>
                        <option value="investment">📈 Investment</option>
                        <option value="property">🏠 Property</option>
                        <option value="vehicle">🚗 Vehicle</option>
                        <option value="electronics">💻 Electronics</option>
                        <option value="other">📦 Other</option>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Current Value (KES)</label>
                <input type="number" name="value" placeholder="0.00" min="0" step="0.01" required>
            </div>
            <div class="field">
                <label>Note (optional)</label>
                <input type="text" name="note" placeholder="e.g. Account #123, last updated May 2025">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeAssetModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ASSET MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-hd">
            <div>
                <div class="modal-title">Update Asset</div>
                <div class="modal-sub" id="editModalSub"></div>
            </div>
            <button class="modal-close" onclick="closeEditModal()">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update_asset">
            <input type="hidden" name="asset_id" id="editAssetId">
            <div class="field">
                <label>Current Value (KES)</label>
                <input type="number" name="value" id="editValue" placeholder="0.00" min="0" step="0.01" required>
            </div>
            <div class="field">
                <label>Note (optional)</label>
                <input type="text" name="note" id="editNote" placeholder="Update notes...">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE FORM (hidden) -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete_asset">
    <input type="hidden" name="asset_id" id="deleteAssetId">
</form>

<script>
/* ── DATA FROM PHP ── */
const snapDates  = <?php echo $jDates; ?>;
const snapAssets = <?php echo $jAssets; ?>;
const snapDebt   = <?php echo $jDebt; ?>;
const snapNet    = <?php echo $jNet; ?>;
const catLabels  = <?php echo $jCatLabels; ?>;
const catValues  = <?php echo $jCatValues; ?>;
const catColors  = <?php echo $jCatColors; ?>;
const totalAssets = <?php echo (float) $totalAssets; ?>;

/* ── SIDEBAR TOGGLE ── */
(function() {
    const layout  = document.getElementById('layout');
    const sidebar = document.getElementById('sidebar');
    const btn1    = document.getElementById('sidebarToggle');
    const btn2    = document.getElementById('topbarMenuBtn');
    function toggle() {
        const c = sidebar.classList.toggle('collapsed');
        layout.classList.toggle('sidebar-collapsed', c);
        localStorage.setItem('sidebarCollapsed', c ? '1' : '0');
    }
    if (btn1) btn1.addEventListener('click', toggle);
    if (btn2) btn2.addEventListener('click', toggle);
    if (localStorage.getItem('sidebarCollapsed') === '1') {
        sidebar.classList.add('collapsed');
        layout.classList.add('sidebar-collapsed');
    }
})();

/* ── MODALS ── */
function openAssetModal()  { document.getElementById('assetModal').classList.add('open'); }
function closeAssetModal() { document.getElementById('assetModal').classList.remove('open'); }
function openEditModal(asset) {
    document.getElementById('editAssetId').value = asset.asset_id;
    document.getElementById('editValue').value   = asset.value;
    document.getElementById('editNote').value    = asset.note || '';
    document.getElementById('editModalSub').textContent = asset.name;
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
function deleteAsset(id) {
    if (!confirm('Delete this asset? This cannot be undone.')) return;
    document.getElementById('deleteAssetId').value = id;
    document.getElementById('deleteForm').submit();
}
document.querySelectorAll('.modal-overlay').forEach(o =>
    o.addEventListener('click', e => { if (e.target === o) { o.classList.remove('open'); } })
);

/* ── CHARTS ── */
Chart.defaults.font.family = "'DM Sans', sans-serif";

/* NET WORTH CHART */
let nwChart;
const nwCtx = document.getElementById('nwChart');
if (nwCtx && snapNet.length >= 2) {
    nwChart = new Chart(nwCtx, {
        type: 'line',
        data: {
            labels: snapDates,
            datasets: [{
                label: 'Net Worth',
                data: snapNet,
                borderColor: '#6366f1',
                backgroundColor: ctx => {
                    const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 260);
                    g.addColorStop(0, 'rgba(99,102,241,.18)');
                    g.addColorStop(1, 'rgba(99,102,241,.01)');
                    return g;
                },
                borderWidth: 2.5,
                fill: true,
                tension: 0.42,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#6366f1',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
            }]
        },
        options: buildLineOpts()
    });

    document.getElementById('chartSeg').addEventListener('click', e => {
        const btn = e.target.closest('.seg-btn');
        if (!btn) return;
        document.querySelectorAll('#chartSeg .seg-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const view = btn.dataset.view;
        if (view === 'net') {
            nwChart.data.datasets = [{
                label: 'Net Worth',
                data: snapNet,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99,102,241,.1)',
                borderWidth: 2.5, fill: true, tension: 0.42,
                pointRadius: 4, pointHoverRadius: 6,
                pointBackgroundColor: '#6366f1', pointBorderColor: '#fff', pointBorderWidth: 2
            }];
        } else {
            nwChart.data.datasets = [
                {
                    label: 'Assets',
                    data: snapAssets,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,.08)',
                    borderWidth: 2, fill: true, tension: 0.42,
                    pointRadius: 3, pointBackgroundColor: '#10b981', pointBorderColor: '#fff', pointBorderWidth: 1.5
                },
                {
                    label: 'Liabilities',
                    data: snapDebt,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244,63,94,.08)',
                    borderWidth: 2, fill: true, tension: 0.42,
                    pointRadius: 3, pointBackgroundColor: '#f43f5e', pointBorderColor: '#fff', pointBorderWidth: 1.5
                }
            ];
        }
        nwChart.update();
    });
}

function buildLineOpts() {
    return {
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
                callbacks: { label: ctx => ctx.dataset.label + ': KES ' + ctx.parsed.y.toLocaleString() }
            }
        },
        scales: {
            y: {
                grid: { color: '#f1f3f5', drawBorder: false },
                ticks: { color: '#94a3b8', font: { size: 11 }, callback: v => 'KES ' + v.toLocaleString() }
            },
            x: {
                grid: { display: false, drawBorder: false },
                ticks: { color: '#94a3b8', font: { size: 11 } }
            }
        }
    };
}

/* ASSET DONUT */
const donutCtx = document.getElementById('assetDonut');
if (donutCtx && catValues.length > 0) {
    const donut = new Chart(donutCtx, {
        type: 'doughnut',
        data: {
            labels: catLabels,
            datasets: [{ data: catValues, backgroundColor: catColors, borderColor: '#fff', borderWidth: 3, hoverOffset: 5 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0d0f12', padding: 12,
                    titleColor: '#f1f5f9', bodyColor: '#94a3b8',
                    borderColor: '#2a2f3a', borderWidth: 1,
                    callbacks: { label: ctx => ' KES ' + ctx.parsed.toLocaleString() }
                }
            },
            onHover: (e, el) => {
                const valEl = document.getElementById('donutVal');
                if (el.length) {
                    const i = el[0].index;
                    valEl.textContent = 'KES ' + catValues[i].toLocaleString();
                } else {
                    valEl.textContent = 'KES ' + totalAssets.toLocaleString();
                }
            }
        }
    });

    /* Build legend */
    const legend = document.getElementById('assetLegend');
    catLabels.forEach((lbl, i) => {
        const pct = totalAssets > 0 ? ((catValues[i] / totalAssets) * 100).toFixed(1) : '0.0';
        legend.innerHTML += `
            <div class="legend-item">
                <div class="legend-dot" style="background:${catColors[i]}"></div>
                <span class="legend-lbl">${lbl}</span>
                <span class="legend-val">KES ${catValues[i].toLocaleString()}</span>
                <span class="legend-pct">${pct}%</span>
            </div>`;
    });
}

/* ── TOAST ── */
function showToast(msg) {
    const t = document.createElement('div');
    t.className = 'toast success';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>
</body>
</html>