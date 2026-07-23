<?php
session_start();
include '../core/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id   = (int) $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'User';

/* ── VALIDATE PERIOD SELECTION ── */
$allowed_periods = ['this_month', 'last_month', 'this_year', 'last_year', 'last_30', 'all_time', 'custom'];
$period = $_GET['period'] ?? 'this_month';
if (!in_array($period, $allowed_periods, true)) {
    $period = 'this_month';
}

$date_error = '';
$today      = date('Y-m-d');

switch ($period) {
    case 'last_month':
        $rangeStart = date('Y-m-01', strtotime('first day of last month'));
        $rangeEnd   = date('Y-m-t',  strtotime('last day of last month'));
        $rangeLabel = date('F Y', strtotime($rangeStart));
        break;
    case 'this_year':
        $rangeStart = date('Y-01-01');
        $rangeEnd   = date('Y-12-31');
        $rangeLabel = date('Y');
        break;
    case 'last_year':
        $rangeStart = date('Y-01-01', strtotime('-1 year'));
        $rangeEnd   = date('Y-12-31', strtotime('-1 year'));
        $rangeLabel = date('Y', strtotime('-1 year'));
        break;
    case 'last_30':
        $rangeStart = date('Y-m-d', strtotime('-29 days'));
        $rangeEnd   = $today;
        $rangeLabel = 'Last 30 days';
        break;
    case 'all_time':
        $rangeStart = '2000-01-01';
        $rangeEnd   = $today;
        $rangeLabel = 'All time';
        break;
    case 'custom':
        $from = trim($_GET['date_from'] ?? '');
        $to   = trim($_GET['date_to']   ?? '');

        $valid_from = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && strtotime($from) !== false;
        $valid_to   = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   && strtotime($to)   !== false;

        if (!$valid_from || !$valid_to) {
            $date_error = 'Please choose valid start and end dates.';
            $period     = 'this_month';
            $rangeStart = date('Y-m-01');
            $rangeEnd   = date('Y-m-t');
            $rangeLabel = date('F Y');
        } elseif ($from > $to) {
            $date_error = 'Start date must be on or before the end date.';
            $period     = 'this_month';
            $rangeStart = date('Y-m-01');
            $rangeEnd   = date('Y-m-t');
            $rangeLabel = date('F Y');
        } else {
            $rangeStart = $from;
            $rangeEnd   = $to;
            $rangeLabel = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
        }
        break;
    case 'this_month':
    default:
        $rangeStart = date('Y-m-01');
        $rangeEnd   = date('Y-m-t');
        $rangeLabel = date('F Y');
        break;
}

$dateExpr = "COALESCE(NULLIF(date,'0000-00-00'), DATE(created_at))";

/* ── SUMMARY TOTALS ── */
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
        COUNT(*) AS total_count
    FROM transactions
    WHERE user_id = ? AND is_deleted_by_user = 0
      AND $dateExpr BETWEEN ? AND ?
");
$stmt->bind_param('iss', $user_id, $rangeStart, $rangeEnd);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalIncome  = (float) ($summary['total_income']  ?? 0);
$totalExpense = (float) ($summary['total_expense'] ?? 0);
$netSavings   = $totalIncome - $totalExpense;
$savingsRate  = $totalIncome > 0 ? round(($netSavings / $totalIncome) * 100, 1) : 0;
$txCount      = (int) ($summary['total_count'] ?? 0);

/* ── CATEGORY BREAKDOWN (expenses) ── */
$stmt = $conn->prepare("
    SELECT category, SUM(amount) AS total
    FROM transactions
    WHERE user_id = ? AND is_deleted_by_user = 0 AND type = 'expense'
      AND $dateExpr BETWEEN ? AND ?
    GROUP BY category
    ORDER BY total DESC
");
$stmt->bind_param('iss', $user_id, $rangeStart, $rangeEnd);
$stmt->execute();
$catRes = $stmt->get_result();
$catLabels = [];
$catTotals = [];
while ($row = $catRes->fetch_assoc()) {
    $catLabels[] = $row['category'];
    $catTotals[] = (float) $row['total'];
}
$stmt->close();

$palette = ['#FF6B35','#4F46E5','#059669','#E11D48','#D97706','#7C3AED','#0891B2','#EA580C','#DB2777','#65A30D'];
$catColors = [];
foreach ($catLabels as $i => $lbl) {
    $catColors[] = $palette[$i % count($palette)];
}

/* ── MONTHLY INCOME VS EXPENSE TREND WITHIN RANGE ── */
$stmt = $conn->prepare("
    SELECT
        DATE_FORMAT($dateExpr, '%Y-%m') AS ym,
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
    FROM transactions
    WHERE user_id = ? AND is_deleted_by_user = 0
      AND $dateExpr BETWEEN ? AND ?
    GROUP BY ym
    ORDER BY ym ASC
");
$stmt->bind_param('iss', $user_id, $rangeStart, $rangeEnd);
$stmt->execute();
$trendRes = $stmt->get_result();
$trendLabels  = [];
$trendIncome  = [];
$trendExpense = [];
while ($row = $trendRes->fetch_assoc()) {
    $trendLabels[]  = date('M Y', strtotime($row['ym'] . '-01'));
    $trendIncome[]  = (float) $row['income'];
    $trendExpense[] = (float) $row['expense'];
}
$stmt->close();

/* ── TOP 5 EXPENSE CATEGORIES FOR INSIGHTS LIST ── */
$topCats = array_slice(array_combine($catLabels, $catTotals), 0, 5, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Fintrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="../assets/fintrack.css">
<style>
    /* Page-specific bits not covered by the shared stylesheet */
    .filter-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 20px; }
    .filter-bar .field { margin-bottom: 0; }
    .custom-range { display: none; align-items: flex-end; gap: 10px; }
    .custom-range.show { display: flex; }
    .error-banner {
        background: var(--red-dim); color: var(--red); border: 1px solid rgba(225,29,72,.25);
        border-radius: var(--r); padding: 10px 14px; font-size: 13px; margin-bottom: 16px;
    }
    .empty { text-align: center; padding: 40px 16px; color: var(--text-2); }
    .empty-ico { font-size: 30px; margin-bottom: 8px; opacity: .5; }
    @media print {
        .sidebar, .topbar, .filter-bar, .btn { display: none !important; }
        .main { margin-left: 0 !important; }
    }
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
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Overview</div>
            <a href="../pages/dashboard.php" class="nav-item" title="Dashboard">
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
            <a href="../pages/reports.php" class="nav-item active" title="Reports">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/></svg>
                <span class="nav-label">Reports</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Account</div>
            <a href="../pages/settings.php" class="nav-item" title="Settings">
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
                <div class="user-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <div class="nav-label">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
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
                    <span class="breadcrumb-current">Reports</span>
                </div>
            </div>
            <div class="topbar-right">
                <button class="btn btn-ghost" onclick="window.print()">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a1 1 0 001-1v-4a1 1 0 00-1-1H9a1 1 0 00-1 1v4a1 1 0 001 1zm8-14V4a1 1 0 00-1-1H8a1 1 0 00-1 1v3"/></svg>
                    Print
                </button>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <div class="page-header">
                <div class="page-header-left">
                    <h1 class="page-title">Reports</h1>
                    <p class="page-sub">Income vs expenses and category breakdown for <?php echo htmlspecialchars($rangeLabel); ?></p>
                </div>
            </div>

            <?php if ($date_error): ?>
            <div class="error-banner"><?php echo htmlspecialchars($date_error); ?> Showing this month instead.</div>
            <?php endif; ?>

            <!-- FILTER BAR -->
            <form class="filter-bar" method="GET" id="filterForm" novalidate>
                <div class="field">
                    <label>Period</label>
                    <select name="period" id="periodSelect" onchange="onPeriodChange()">
                        <option value="this_month" <?php echo $period==='this_month'?'selected':''; ?>>This Month</option>
                        <option value="last_month" <?php echo $period==='last_month'?'selected':''; ?>>Last Month</option>
                        <option value="this_year"  <?php echo $period==='this_year' ?'selected':''; ?>>This Year</option>
                        <option value="last_year"  <?php echo $period==='last_year' ?'selected':''; ?>>Last Year</option>
                        <option value="last_30"    <?php echo $period==='last_30'   ?'selected':''; ?>>Last 30 Days</option>
                        <option value="all_time"   <?php echo $period==='all_time'  ?'selected':''; ?>>All Time</option>
                        <option value="custom"     <?php echo $period==='custom'    ?'selected':''; ?>>Custom Range</option>
                    </select>
                </div>
                <div class="custom-range<?php echo $period==='custom' ? ' show' : ''; ?>" id="customRange">
                    <div class="field">
                        <label>From</label>
                        <input type="date" name="date_from" id="dateFrom" max="<?php echo $today; ?>"
                               value="<?php echo htmlspecialchars($_GET['date_from'] ?? $rangeStart); ?>">
                    </div>
                    <div class="field">
                        <label>To</label>
                        <input type="date" name="date_to" id="dateTo" max="<?php echo $today; ?>"
                               value="<?php echo htmlspecialchars($_GET['date_to'] ?? $rangeEnd); ?>">
                    </div>
                </div>
                <div class="field" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
                <div class="field" id="dateFieldError" style="display:none;align-self:center;color:var(--red);font-size:12.5px;font-weight:600;"></div>
            </form>

            <!-- STATS -->
            <div class="stats-row">
                <div class="stat">
                    <div class="stat-top">
                        <span class="stat-lbl">Total Income</span>
                        <div class="stat-ico ico-green">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
                        </div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($totalIncome, 0); ?></div>
                    <div class="stat-meta">Money in for the period</div>
                </div>
                <div class="stat">
                    <div class="stat-top">
                        <span class="stat-lbl">Total Expenses</span>
                        <div class="stat-ico ico-red">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"/></svg>
                        </div>
                    </div>
                    <div class="stat-val">KES <?php echo number_format($totalExpense, 0); ?></div>
                    <div class="stat-meta">Money out for the period</div>
                </div>
                <div class="stat">
                    <div class="stat-top">
                        <span class="stat-lbl">Net Savings</span>
                        <div class="stat-ico ico-blue">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1"/></svg>
                        </div>
                    </div>
                    <div class="stat-val" style="color:<?php echo $netSavings >= 0 ? 'var(--green)' : 'var(--red)'; ?>">
                        KES <?php echo number_format($netSavings, 0); ?>
                    </div>
                    <div class="stat-meta">Income minus expenses</div>
                </div>
                <div class="stat">
                    <div class="stat-top">
                        <span class="stat-lbl">Savings Rate</span>
                        <div class="stat-ico ico-amber">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6"/></svg>
                        </div>
                    </div>
                    <div class="stat-val"><?php echo $savingsRate; ?>%</div>
                    <div class="stat-meta"><?php echo $txCount; ?> transaction<?php echo $txCount===1?'':'s'; ?> in range</div>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="charts-row">
                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Income vs Expenses</div>
                            <div class="card-sub">Month-by-month for the selected range</div>
                        </div>
                    </div>
                    <?php if (count($trendLabels) > 0): ?>
                    <div class="chart-wrap">
                        <canvas id="trendChart"></canvas>
                    </div>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-ico">📊</div>
                        <div>No transactions in this period yet.</div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">By Category</div>
                            <div class="card-sub">Where expenses went</div>
                        </div>
                    </div>
                    <?php if (count($catLabels) > 0): ?>
                    <div class="donut-wrap">
                        <canvas id="catChart"></canvas>
                        <div class="donut-center">
                            <div class="donut-lbl">Total Spent</div>
                            <div class="donut-val">KES <?php echo number_format($totalExpense, 0); ?></div>
                        </div>
                    </div>
                    <div class="legend" id="catLegend"></div>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-ico">🍩</div>
                        <div>No expenses in this period yet.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CATEGORY TABLE + INSIGHTS -->
            <div class="bottom-row">
                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Category Breakdown</div>
                            <div class="card-sub">All expense categories, highest first</div>
                        </div>
                    </div>
                    <?php if (count($catLabels) > 0): ?>
                    <table class="tx-table">
                        <thead>
                            <tr><th>Category</th><th>Amount</th><th>% of Spend</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($catLabels as $i => $lbl):
                            $pct = $totalExpense > 0 ? round(($catTotals[$i] / $totalExpense) * 100, 1) : 0; ?>
                            <tr>
                                <td>
                                    <div class="tx-cat-wrap" style="display:flex;align-items:center;gap:10px;">
                                        <span class="legend-dot" style="width:10px;height:10px;background:<?php echo $catColors[$i]; ?>;border-radius:50%;display:inline-block;"></span>
                                        <span class="tx-name"><?php echo htmlspecialchars($lbl); ?></span>
                                    </div>
                                </td>
                                <td class="tx-amount expense">KES <?php echo number_format($catTotals[$i], 0); ?></td>
                                <td class="tx-date"><?php echo $pct; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-ico">🔍</div>
                        <div>Nothing to show for this period.</div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Top Categories</div>
                            <div class="card-sub">Biggest spend areas</div>
                        </div>
                    </div>
                    <?php if (count($topCats) > 0): ?>
                    <div class="insights">
                        <?php foreach ($topCats as $lbl => $amt):
                            $pct = $totalExpense > 0 ? round(($amt / $totalExpense) * 100) : 0; ?>
                        <div class="insight-item">
                            <div class="insight-label"><?php echo htmlspecialchars($lbl); ?></div>
                            <div class="insight-val">KES <?php echo number_format($amt, 0); ?></div>
                            <div class="insight-sub"><?php echo $pct; ?>% of total spend</div>
                            <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $pct; ?>%"></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-ico">✨</div>
                        <div>Nothing to rank yet.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->

<script>
const trendLabels  = <?php echo json_encode($trendLabels); ?>;
const trendIncome  = <?php echo json_encode($trendIncome); ?>;
const trendExpense = <?php echo json_encode($trendExpense); ?>;
const catLabels     = <?php echo json_encode($catLabels); ?>;
const catTotals      = <?php echo json_encode($catTotals); ?>;
const catColors      = <?php echo json_encode($catColors); ?>;

/* ── PERIOD FILTER: show/hide + validate custom range ── */
function onPeriodChange() {
    const period = document.getElementById('periodSelect').value;
    document.getElementById('customRange').classList.toggle('show', period === 'custom');
}

document.getElementById('filterForm').addEventListener('submit', function (e) {
    const period  = document.getElementById('periodSelect').value;
    const errBox  = document.getElementById('dateFieldError');
    errBox.style.display = 'none';
    errBox.textContent = '';

    if (period === 'custom') {
        const from = document.getElementById('dateFrom').value;
        const to   = document.getElementById('dateTo').value;

        if (!from || !to) {
            e.preventDefault();
            errBox.textContent = 'Please choose both a start and end date.';
            errBox.style.display = 'block';
            return;
        }
        if (from > to) {
            e.preventDefault();
            errBox.textContent = 'Start date must be on or before the end date.';
            errBox.style.display = 'block';
            return;
        }
        const todayStr = new Date().toISOString().split('T')[0];
        if (from > todayStr || to > todayStr) {
            e.preventDefault();
            errBox.textContent = 'Dates cannot be in the future.';
            errBox.style.display = 'block';
            return;
        }
    }
});

/* ── TREND CHART ── */
if (trendLabels.length > 0) {
    new Chart(document.getElementById('trendChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: trendLabels,
            datasets: [
                { label: 'Income',  data: trendIncome,  backgroundColor: '#059669', borderRadius: 4 },
                { label: 'Expense', data: trendExpense, backgroundColor: '#E11D48', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
            scales: {
                x: { grid: { display: false } },
                y: { ticks: { callback: v => 'KES ' + v.toLocaleString() } }
            }
        }
    });
}

/* ── CATEGORY DONUT CHART ── */
if (catLabels.length > 0) {
    new Chart(document.getElementById('catChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: catLabels,
            datasets: [{ data: catTotals, backgroundColor: catColors, borderColor: '#FFFFFF', borderWidth: 3, hoverOffset: 6 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' KES ' + ctx.parsed.toLocaleString() } }
            }
        }
    });

    const legend = document.getElementById('catLegend');
    const total  = catTotals.reduce((a, b) => a + b, 0);
    catLabels.forEach((lbl, i) => {
        const pct = total > 0 ? ((catTotals[i] / total) * 100).toFixed(1) : '0.0';
        legend.innerHTML += `
            <div class="legend-item">
                <div class="legend-dot" style="background:${catColors[i]}"></div>
                <span class="legend-lbl">${lbl}</span>
                <span class="legend-pct">${pct}%</span>
            </div>`;
    });
}
</script>
</body>
</html>
