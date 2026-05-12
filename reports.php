<?php
// ============================================================
// reports.php — Monthly / Yearly financial reports + PDF export
// ============================================================
session_start();
require_once 'db.php';
require_once 'audit.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$user_id = (int) $_SESSION['user_id'];

// ── Check export permission ──────────────────────────────────
$plan_stmt = $conn->prepare("
    SELECT p.has_exports
    FROM subscriptions s JOIN plans p ON p.plan_id = s.plan_id
    WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 1
");
$plan_stmt->bind_param("i", $user_id);
$plan_stmt->execute();
$plan = $plan_stmt->get_result()->fetch_assoc();
$can_export = (bool)($plan['has_exports'] ?? false);

// ── Parameters ───────────────────────────────────────────────
$mode   = $_GET['mode']  ?? 'monthly';    // 'monthly' or 'yearly'
$year   = (int)($_GET['year']  ?? date('Y'));
$month  = (int)($_GET['month'] ?? date('n'));
$format = $_GET['format'] ?? '';

// ── Build date range ─────────────────────────────────────────
if ($mode === 'yearly') {
    $date_from = "{$year}-01-01";
    $date_to   = "{$year}-12-31";
    $period_label = "Year {$year}";
} else {
    $date_from = date("Y-m-01", mktime(0, 0, 0, $month, 1, $year));
    $date_to   = date("Y-m-t",  mktime(0, 0, 0, $month, 1, $year));
    $period_label = date("F Y", mktime(0, 0, 0, $month, 1, $year));
}

// ── Fetch transactions ───────────────────────────────────────
$stmt = $conn->prepare("
    SELECT date, type, category, amount, description, created_at
    FROM transactions
    WHERE user_id=? AND is_deleted_by_user=0 AND date BETWEEN ? AND ?
    ORDER BY date DESC
");
$stmt->bind_param("iss", $user_id, $date_from, $date_to);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Aggregate ────────────────────────────────────────────────
$total_income  = 0;
$total_expense = 0;
$categories    = [];
$monthly_data  = [];

foreach ($rows as $row) {
    $amt = (float)$row['amount'];
    if ($row['type'] === 'income') {
        $total_income += $amt;
    } else {
        $total_expense += $amt;
        $cat = $row['category'] ?? 'Other';
        $categories[$cat] = ($categories[$cat] ?? 0) + $amt;
    }
    // Group by month (for yearly mode)
    $m = date('M', strtotime($row['date']));
    $monthly_data[$m][$row['type']] = ($monthly_data[$m][$row['type']] ?? 0) + $amt;
}
arsort($categories);
$net = $total_income - $total_expense;
$savings_rate = $total_income > 0 ? round(($net / $total_income) * 100) : 0;

// ── Prev/next navigation ─────────────────────────────────────
if ($mode === 'monthly') {
    $prev_ts   = mktime(0,0,0, $month-1, 1, $year);
    $next_ts   = mktime(0,0,0, $month+1, 1, $year);
    $prev_href = '?mode=monthly&year=' . date('Y',$prev_ts) . '&month=' . date('n',$prev_ts);
    $next_href = '?mode=monthly&year=' . date('Y',$next_ts) . '&month=' . date('n',$next_ts);
} else {
    $prev_href = '?mode=yearly&year=' . ($year-1);
    $next_href = '?mode=yearly&year=' . ($year+1);
}

// ── PDF export ───────────────────────────────────────────────
if ($format === 'pdf') {
    if (!$can_export) { header('Location: plans.php'); exit(); }

    Audit::log($conn, $user_id, 'export.pdf', 'report', null, null, [
        'mode' => $mode, 'from' => $date_from, 'to' => $date_to
    ]);

    $u = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
    $u->bind_param("i", $user_id); $u->execute();
    $urow = $u->get_result()->fetch_assoc();

    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Report <?= htmlspecialchars($period_label) ?></title>
<style>
@media print{body{-webkit-print-color-adjust:exact}.no-print{display:none}}
body{font-family:system-ui,sans-serif;padding:2rem;color:#1e293b;max-width:920px;margin:0 auto}
.hdr{display:flex;justify-content:space-between;border-bottom:3px solid #7c3aed;padding-bottom:1rem;margin-bottom:1.5rem}
.brand{font-size:1.5rem;font-weight:800;color:#7c3aed}
.meta{font-size:.8rem;color:#64748b;text-align:right}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
.stat{background:#f8fafc;border-radius:8px;padding:1rem}
.stat .lbl{font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.stat .val{font-size:1.3rem;font-weight:700;margin-top:.25rem}
.income{color:#10b981}.expense{color:#ef4444}.neutral{color:#7c3aed}
table{width:100%;border-collapse:collapse;font-size:.85rem}
th{background:#f1f5f9;padding:.6rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;color:#64748b}
td{padding:.6rem .75rem;border-bottom:1px solid #f1f5f9}
.cat-bar{height:8px;background:#7c3aed;border-radius:4px;display:inline-block;min-width:4px}
.section-title{font-size:1rem;font-weight:700;margin:1.5rem 0 .75rem;border-left:4px solid #7c3aed;padding-left:.75rem}
.btn-print{background:#7c3aed;color:#fff;border:none;padding:.6rem 1.5rem;border-radius:8px;cursor:pointer;font-weight:700;margin-bottom:1.5rem}
</style></head><body>
<button class="btn-print no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
<div class="hdr">
    <div><div class="brand">Royal Matrix</div>
         <div style="font-size:.85rem;color:#64748b;margin-top:.3rem"><?= htmlspecialchars($mode==='yearly'?'Annual':'Monthly') ?> Financial Report</div></div>
    <div class="meta">
        <div><?= htmlspecialchars($urow['full_name'] ?? '') ?></div>
        <div>Period: <?= htmlspecialchars($period_label) ?></div>
        <div>Generated: <?= date('d M Y H:i') ?></div>
    </div>
</div>
<div class="summary">
    <div class="stat"><div class="lbl">Total Income</div><div class="val income">KES <?= number_format($total_income, 2) ?></div></div>
    <div class="stat"><div class="lbl">Total Expenses</div><div class="val expense">KES <?= number_format($total_expense, 2) ?></div></div>
    <div class="stat"><div class="lbl">Net Balance</div><div class="val <?= $net>=0?'income':'expense' ?>">KES <?= number_format($net, 2) ?></div></div>
    <div class="stat"><div class="lbl">Savings Rate</div><div class="val neutral"><?= $savings_rate ?>%</div></div>
</div>
<?php if (!empty($categories)): ?>
<div class="section-title">Spending by Category</div>
<table><thead><tr><th>Category</th><th>Amount (KES)</th><th>% of Expenses</th><th>Bar</th></tr></thead><tbody>
<?php foreach ($categories as $cat => $amt):
    $pct = $total_expense > 0 ? round($amt / $total_expense * 100) : 0;
    $barw = max(4, round($pct * 2.5));
?>
<tr>
    <td><?= htmlspecialchars($cat) ?></td>
    <td><?= number_format($amt, 2) ?></td>
    <td><?= $pct ?>%</td>
    <td><span class="cat-bar" style="width:<?= $barw ?>px"></span></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<div class="section-title">Transaction Detail</div>
<table><thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th style="text-align:right">Amount (KES)</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= htmlspecialchars($r['date']) ?></td>
    <td style="color:<?= $r['type']==='income'?'#10b981':'#ef4444' ?>;font-weight:600"><?= ucfirst($r['type']) ?></td>
    <td><?= htmlspecialchars($r['category'] ?? '—') ?></td>
    <td><?= htmlspecialchars($r['description'] ?? '—') ?></td>
    <td style="text-align:right;font-weight:600"><?= number_format((float)$r['amount'], 2) ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($rows)): ?>
<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:2rem">No transactions in this period.</td></tr>
<?php endif; ?>
</tbody></table>
<p style="margin-top:2rem;font-size:.72rem;color:#94a3b8;text-align:center">Royal Matrix — Confidential Financial Report · <?= htmlspecialchars($period_label) ?></p>
</body></html>
    <?php exit();
}

// ── Prepare chart data ────────────────────────────────────────
$months_order = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$chart_income  = [];
$chart_expense = [];
$chart_labels  = $mode === 'yearly' ? $months_order : [];

if ($mode === 'yearly') {
    foreach ($months_order as $m) {
        $chart_income[]  = $monthly_data[$m]['income']  ?? 0;
        $chart_expense[] = $monthly_data[$m]['expense'] ?? 0;
    }
} else {
    // Daily breakdown for monthly mode
    $daily = [];
    foreach ($rows as $r) {
        $d = date('j', strtotime($r['date']));
        $daily[$d][$r['type']] = ($daily[$d][$r['type']] ?? 0) + (float)$r['amount'];
    }
    ksort($daily);
    foreach ($daily as $day => $vals) {
        $chart_labels[]  = $day;
        $chart_income[]  = $vals['income']  ?? 0;
        $chart_expense[] = $vals['expense'] ?? 0;
    }
}

$cat_labels = array_keys(array_slice($categories, 0, 8));
$cat_values = array_values(array_slice($categories, 0, 8));

// Available years
$yr_stmt = $conn->prepare("SELECT DISTINCT YEAR(date) yr FROM transactions WHERE user_id=? ORDER BY yr DESC");
$yr_stmt->bind_param("i", $user_id);
$yr_stmt->execute();
$available_years = array_column($yr_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'yr');
if (!in_array($year, $available_years)) $available_years[] = $year;
rsort($available_years);

// User full name
$uname_q = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
$uname_q->bind_param("i", $user_id);
$uname_q->execute();
$uname = $uname_q->get_result()->fetch_assoc()['full_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports | Royal Matrix</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/darkmode.js" defer></script>
<style>
:root{--bg:#0f172a;--bg2:#1e293b;--bg3:#334155;--border:rgba(255,255,255,.08);
      --text:#f1f5f9;--muted:#94a3b8;--purple:#7c3aed;--cyan:#06b6d4;
      --green:#22c55e;--red:#ef4444;--gold:#fbbf24}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.sidebar{width:240px;height:100vh;position:fixed;background:var(--bg2);border-right:1px solid var(--border);padding:24px 16px;display:flex;flex-direction:column;gap:4px}
.sidebar .logo{font-size:18px;font-weight:800;padding:8px 12px 20px;color:var(--purple)}
.sidebar a{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;text-decoration:none;color:var(--muted);font-size:14px;font-weight:500;transition:.2s}
.sidebar a:hover{background:var(--bg3);color:var(--text)}
.sidebar a.active{background:linear-gradient(135deg,#7c3aed22,#06b6d422);color:var(--purple);border:1px solid #7c3aed44}
.main{margin-left:240px;padding:32px;min-height:100vh}
.page-hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;flex-wrap:wrap;gap:14px}
h1{font-size:22px;font-weight:700}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.controls form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{padding:9px 18px;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:.2s}
.btn-primary{background:var(--purple);color:#fff}.btn-primary:hover{background:#6d28d9}
.btn-outline{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-outline:hover{background:var(--bg3);color:var(--text)}
.btn-pdf{background:#ef4444;color:#fff}.btn-pdf:hover{background:#dc2626}
.btn-lock{background:var(--bg3);color:var(--muted);cursor:not-allowed}
select{padding:9px 14px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:13px;outline:none}
.tabs{display:flex;gap:4px;background:var(--bg2);padding:4px;border-radius:12px;width:fit-content;margin-bottom:24px;border:1px solid var(--border)}
.tab{padding:8px 22px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:var(--muted);transition:.2s}
.tab.active{background:var(--purple);color:#fff}
.nav-row{display:flex;align-items:center;gap:14px;margin-bottom:22px}
.nav-btn{padding:7px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-weight:600;text-decoration:none;transition:.2s}
.nav-btn:hover{background:var(--bg3)}
.period-badge{font-size:18px;font-weight:800;color:var(--text)}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px}
.kpi{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:20px 22px;position:relative;overflow:hidden}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:3px 3px 0 0}
.kpi.income::before{background:var(--green)}
.kpi.expense::before{background:var(--red)}
.kpi.net::before{background:var(--purple)}
.kpi.rate::before{background:var(--gold)}
.kpi .label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:8px}
.kpi .value{font-size:24px;font-weight:800}
.kpi .change{font-size:12px;color:var(--muted);margin-top:4px}
.kpi.income .value{color:var(--green)}
.kpi.expense .value{color:var(--red)}
.kpi.net .value{color:var(--purple)}
.kpi.rate .value{color:var(--gold)}
.charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:28px}
.chart-card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:22px}
.chart-card h3{font-size:14px;font-weight:700;margin-bottom:18px;color:var(--text)}
.chart-wrap{position:relative;height:240px}
.cat-table{background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:28px}
.cat-table table{width:100%;border-collapse:collapse}
.cat-table th{padding:11px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);background:var(--bg);border-bottom:1px solid var(--border);text-align:left}
.cat-table td{padding:12px 18px;font-size:13px;border-bottom:1px solid var(--border)}
.cat-table tr:last-child td{border-bottom:none}
.cat-table tr:hover td{background:var(--bg3)}
.bar-bg{background:var(--bg3);border-radius:4px;height:8px;overflow:hidden;min-width:80px}
.bar-fill{height:8px;border-radius:4px;background:linear-gradient(90deg,var(--purple),var(--cyan));transition:.4s}
.empty-state{text-align:center;padding:60px;color:var(--muted)}
.empty-state .big{font-size:42px;margin-bottom:12px}
@media(max-width:900px){.main{margin-left:0;padding:20px}.charts-grid{grid-template-columns:1fr}.sidebar{display:none}}
</style>
</head>
<body>
<div class="sidebar">
    <div class="logo">💎 Royal Matrix</div>
    <a href="dashboard.php">📊 Dashboard</a>
    <a href="transactions.php">💸 Transactions</a>
    <a href="budget.php">🎯 Budget</a>
    <a href="savings_goals.php">💰 Savings</a>
    <a href="reports.php" class="active">📈 Reports</a>
    <a href="activity_log.php">🕵️ Activity Log</a>
    <a href="settings.php">⚙️ Settings</a>
    <a href="logout.php" style="margin-top:auto;color:var(--red)">🚪 Logout</a>
</div>

<div class="main">
    <div class="page-hdr">
        <div>
            <h1>📈 Financial Reports</h1>
            <p class="sub">Detailed income &amp; expense analysis · <?= htmlspecialchars($uname) ?></p>
        </div>
        <div class="controls">
            <form method="GET">
                <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($available_years as $yr): ?>
                        <option value="<?= $yr ?>" <?= $yr===$year?'selected':'' ?>><?= $yr ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($mode === 'monthly'): ?>
                <select name="month" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
                <?php endif; ?>
            </form>
            <?php if ($can_export): ?>
                <a href="?mode=<?= $mode ?>&year=<?= $year ?>&month=<?= $month ?>&format=pdf" class="btn btn-pdf">⬇ PDF Report</a>
            <?php else: ?>
                <span class="btn btn-lock" title="Upgrade to Pro to export">🔒 PDF (Pro)</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?mode=monthly&year=<?= $year ?>&month=<?= $month ?>" class="tab <?= $mode==='monthly'?'active':'' ?>">📅 Monthly</a>
        <a href="?mode=yearly&year=<?= $year ?>"                      class="tab <?= $mode==='yearly'?'active':'' ?>">📆 Yearly</a>
    </div>

    <!-- Period nav -->
    <div class="nav-row">
        <a href="<?= $prev_href ?>" class="nav-btn">← Prev</a>
        <span class="period-badge"><?= htmlspecialchars($period_label) ?></span>
        <a href="<?= $next_href ?>" class="nav-btn">Next →</a>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi income">
            <div class="label">Total Income</div>
            <div class="value">KES <?= number_format($total_income, 0) ?></div>
            <div class="change"><?= count(array_filter($rows, fn($r) => $r['type']==='income')) ?> transactions</div>
        </div>
        <div class="kpi expense">
            <div class="label">Total Expenses</div>
            <div class="value">KES <?= number_format($total_expense, 0) ?></div>
            <div class="change"><?= count(array_filter($rows, fn($r) => $r['type']==='expense')) ?> transactions</div>
        </div>
        <div class="kpi net">
            <div class="label">Net Balance</div>
            <div class="value" style="color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><?= $net>=0?'+'.$net:$net ?></div>
            <div class="change">Income minus expenses</div>
        </div>
        <div class="kpi rate">
            <div class="label">Savings Rate</div>
            <div class="value"><?= $savings_rate ?>%</div>
            <div class="change">Of total income saved</div>
        </div>
    </div>

    <!-- Charts -->
    <?php if (!empty($rows)): ?>
    <div class="charts-grid">
        <div class="chart-card">
            <h3>Income vs Expenses</h3>
            <div class="chart-wrap"><canvas id="barChart"></canvas></div>
        </div>
        <div class="chart-card">
            <h3>Spending by Category</h3>
            <div class="chart-wrap"><canvas id="pieChart"></canvas></div>
        </div>
    </div>

    <!-- Category Table -->
    <?php if (!empty($categories)): ?>
    <div class="cat-table">
        <table>
            <thead><tr><th>Category</th><th>Amount (KES)</th><th>% of Expenses</th><th style="min-width:120px">Breakdown</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $cat => $amt):
                $pct = $total_expense > 0 ? round($amt/$total_expense*100) : 0;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($cat) ?></strong></td>
                <td>KES <?= number_format($amt, 2) ?></td>
                <td><?= $pct ?>%</td>
                <td>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="empty-state"><div class="big">📭</div>No transactions in this period.</div>
    <?php endif; ?>
</div>

<script>
const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
const gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
const tickColor = isDark ? '#94a3b8' : '#64748b';

// Bar chart
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [
            { label: 'Income',   data: <?= json_encode($chart_income) ?>,  backgroundColor: 'rgba(34,197,94,.7)',  borderRadius: 6 },
            { label: 'Expenses', data: <?= json_encode($chart_expense) ?>, backgroundColor: 'rgba(239,68,68,.7)', borderRadius: 6 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { color: tickColor } } },
        scales: {
            x: { ticks: { color: tickColor }, grid: { color: gridColor } },
            y: { ticks: { color: tickColor }, grid: { color: gridColor } }
        }
    }
});

// Pie chart
new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($cat_labels) ?>,
        datasets: [{
            data: <?= json_encode($cat_values) ?>,
            backgroundColor: ['#7c3aed','#06b6d4','#22c55e','#f59e0b','#ef4444','#a855f7','#14b8a6','#f43f5e'],
            borderWidth: 2,
            borderColor: isDark ? '#1e293b' : '#fff'
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { color: tickColor, padding: 12, boxWidth: 12 } }
        },
        cutout: '60%'
    }
});
</script>
</body>
</html>
