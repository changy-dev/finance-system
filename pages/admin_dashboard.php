<?php
session_start();
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

// Enforce admin-only access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../pages/dashboard.php");
    exit();
}

$user_id   = (int) $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Administrator';

/* ── SYSTEM AGGREGATES ── */
$total_users = (int) $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$active_users = (int) $conn->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetch_row()[0];
$suspended_users = (int) $conn->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetch_row()[0];

$tx_stats = $conn->query("
    SELECT 
        COUNT(*) AS total_tx,
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
    FROM transactions 
    WHERE is_deleted_by_user = 0
")->fetch_assoc();

$total_tx      = (int) ($tx_stats['total_tx'] ?? 0);
$total_income  = (float) ($tx_stats['total_income'] ?? 0);
$total_expense = (float) ($tx_stats['total_expense'] ?? 0);
$net_volume    = $total_income - $total_expense;

$total_budgets = (int) $conn->query("SELECT COUNT(*) FROM budgets")->fetch_row()[0];

/* ── PLATFORM MONTHLY EXPENSE TREND ── */
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthly_expenses = array_fill(0, 12, 0);
$monthly_incomes  = array_fill(0, 12, 0);

$res = $conn->query("
    SELECT 
        MONTH(COALESCE(NULLIF(date, '0000-00-00'), DATE(created_at))) as m,
        type,
        SUM(amount) as total
    FROM transactions
    WHERE is_deleted_by_user = 0
    GROUP BY m, type
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $m_idx = ((int)$r['m']) - 1;
        if ($m_idx >= 0 && $m_idx < 12) {
            if ($r['type'] === 'expense') {
                $monthly_expenses[$m_idx] = (float)$r['total'];
            } elseif ($r['type'] === 'income') {
                $monthly_incomes[$m_idx] = (float)$r['total'];
            }
        }
    }
}

/* ── TOP PLATFORM CATEGORIES ── */
$cat_labels = [];
$cat_data   = [];
$palette    = ['#FF6B35','#4F46E5','#059669','#E11D48','#D97706','#7C3AED','#0891B2'];

$cat_q = $conn->query("
    SELECT category, SUM(amount) total
    FROM transactions
    WHERE type = 'expense' AND is_deleted_by_user = 0
    GROUP BY category
    ORDER BY total DESC
    LIMIT 6
");
$cat_colors = [];
$ci = 0;
if ($cat_q) {
    while ($cr = $cat_q->fetch_assoc()) {
        $cat_labels[] = $cr['category'];
        $cat_data[]   = (float)$cr['total'];
        $cat_colors[] = $palette[$ci % count($palette)];
        $ci++;
    }
}

/* ── SOFT-DELETED AUDIT RECOVERY ── */
$deleted_txs = [];
$del_q = $conn->query("
    SELECT t.*, u.full_name, u.name, u.email
    FROM transactions t
    JOIN users u ON u.user_id = t.user_id
    WHERE t.is_deleted_by_user = 1
    ORDER BY t.transaction_id DESC
    LIMIT 20
");
if ($del_q) {
    while ($dr = $del_q->fetch_assoc()) {
        $deleted_txs[] = $dr;
    }
}
$deleted_count = count($deleted_txs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — Fintrack System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/fintrack.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.stats-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}
.pill-type {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
}
.pill-income { background: rgba(16,185,129,.12); color: #10B981; }
.pill-expense { background: rgba(239,68,68,.12); color: #EF4444; }

@media(max-width: 1024px) {
    .stats-4 { grid-template-columns: repeat(2, 1fr); }
    .charts-grid { grid-template-columns: 1fr; }
}
@media(max-width: 600px) {
    .stats-4 { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="layout">

    <!-- ADMIN SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <div class="brand-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="brand-text">
                    <div class="brand-name">Fintrack</div>
                    <div class="brand-tagline">Admin Control Panel</div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Overview</div>
            <a href="admin_dashboard.php" class="nav-item active" title="Dashboard">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10-3a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"/></svg>
                <span class="nav-label">System Overview</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Management</div>
            <a href="users.php" class="nav-item" title="Users">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                <span class="nav-label">User Directory</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Analytics</div>
            <a href="reports.php" class="nav-item" title="Reports">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2zM9 19H5a2 2 0 01-2-2v-3a2 2 0 012-2h2"/></svg>
                <span class="nav-label">System Reports</span>
            </a>

            <div class="nav-section-label" style="margin-top:16px;">Account</div>
            <a href="settings.php" class="nav-item" title="Settings">
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
                    <div style="font-size:10px;color:#FF6B35;font-weight:700;letter-spacing:.5px;">ADMINISTRATOR</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left" style="display:flex;align-items:center;gap:10px;">
                <button class="topbar-menu-btn" onclick="toggleSidebar()" aria-label="Toggle navigation">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="breadcrumbs">
                    <span>Admin</span>
                    <span class="breadcrumb-sep">›</span>
                    <span class="breadcrumb-current">Dashboard</span>
                </div>
            </div>
            <div class="topbar-right">
                <a href="users.php" class="btn btn-primary">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <span>Manage Users</span>
                </a>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <!-- METRIC CARDS -->
            <div class="stats-4">
                <div class="card" style="padding:20px;">
                    <div class="card-sub" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Registered Accounts</div>
                    <div style="font-size:26px;font-weight:800;color:var(--text-1);margin:6px 0 2px;">
                        <?= $total_users ?>
                    </div>
                    <div style="font-size:11.5px;color:var(--text-3);font-weight:600;">
                        ● <?= $active_users ?> Active <?php if ($suspended_users > 0): ?><span style="margin-left:6px;">● <?= $suspended_users ?> Suspended</span><?php endif; ?>
                    </div>
                </div>

                <div class="card" style="padding:20px;">
                    <div class="card-sub" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total Transactions</div>
                    <div style="font-size:26px;font-weight:800;color:var(--text-1);margin:6px 0 2px;">
                        <?= $total_tx ?>
                    </div>
                    <div style="font-size:11.5px;color:var(--text-3);">Platform ledger entries</div>
                </div>

                <div class="card" style="padding:20px;">
                    <div class="card-sub" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total Inflows Tracked</div>
                    <div style="font-size:24px;font-weight:800;color:var(--text-1);font-family:var(--font-mono);margin:6px 0 2px;">
                        KES <?= number_format($total_income, 2) ?>
                    </div>
                    <div style="font-size:11.5px;color:var(--text-3);">Combined system income</div>
                </div>

                <div class="card" style="padding:20px;">
                    <div class="card-sub" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total Outflows Tracked</div>
                    <div style="font-size:24px;font-weight:800;color:var(--text-1);font-family:var(--font-mono);margin:6px 0 2px;">
                        KES <?= number_format($total_expense, 2) ?>
                    </div>
                    <div style="font-size:11.5px;color:var(--text-3);">Combined system expenses</div>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="charts-grid">
                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Platform Financial Activity Trend</div>
                            <div class="card-sub">Monthly system-wide income vs expense volume</div>
                        </div>
                    </div>
                    <div style="height:280px;position:relative;">
                        <canvas id="systemTrendChart"></canvas>
                    </div>
                </div>

                <div class="card">
                    <div class="card-hd">
                        <div>
                            <div class="card-title">Top Expense Categories</div>
                            <div class="card-sub">Platform-wide spending distribution</div>
                        </div>
                    </div>
                    <div style="height:220px;position:relative;">
                        <canvas id="systemCatChart"></canvas>
                    </div>
                    <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($cat_labels as $idx => $clabel): ?>
                            <span style="font-size:11px;color:var(--text-2);display:inline-flex;align-items:center;gap:4px;">
                                <span style="width:8px;height:8px;border-radius:2px;background:<?= $cat_colors[$idx] ?>;"></span>
                                <?= htmlspecialchars($clabel) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- AUDIT RECOVERY / SOFT-DELETED TRANSACTIONS -->
            <div class="card" style="margin-top:20px;">
                <div class="card-hd" style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div class="card-title" style="display:flex;align-items:center;gap:8px;">
                            <span>Audit Trail: Soft-Deleted Transactions</span>
                            <?php if ($deleted_count > 0): ?>
                                <span class="badge" style="background:rgba(239,68,68,0.15);color:#EF4444;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700;">
                                    <?= $deleted_count ?> Pending Recovery
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-sub">Transactions deleted by standard users can be audited and restored back to active ledgers.</div>
                    </div>
                </div>

                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'restored'): ?>
                    <div style="background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:#10B981;padding:10px 14px;border-radius:8px;font-size:13px;margin:14px 20px 0;">
                        Transaction successfully restored to the user's active ledger!
                    </div>
                <?php endif; ?>

                <?php if ($deleted_count === 0): ?>
                    <div style="padding:32px 20px;text-align:center;color:var(--text-3);font-size:13px;">
                        <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin:0 auto 8px;opacity:0.4;display:block;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        No soft-deleted transactions in the audit queue. All user transaction records are currently active.
                    </div>
                <?php else: ?>
                    <div class="table-wrap" style="overflow-x:auto;padding:12px 20px 20px;">
                        <table style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="border-bottom:1px solid var(--border);text-align:left;color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">
                                    <th style="padding:10px 8px;">ID</th>
                                    <th style="padding:10px 8px;">User</th>
                                    <th style="padding:10px 8px;">Type</th>
                                    <th style="padding:10px 8px;">Category</th>
                                    <th style="padding:10px 8px;">Amount</th>
                                    <th style="padding:10px 8px;">Date</th>
                                    <th style="padding:10px 8px;text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deleted_txs as $dtx): ?>
                                    <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                        <td style="padding:12px 8px;font-family:var(--font-mono);color:var(--text-3);">#<?= $dtx['transaction_id'] ?></td>
                                        <td style="padding:12px 8px;">
                                            <div style="font-weight:600;color:var(--text-1);"><?= htmlspecialchars($dtx['full_name'] ?: $dtx['name']) ?></div>
                                            <div style="font-size:11px;color:var(--text-3);"><?= htmlspecialchars($dtx['email']) ?></div>
                                        </td>
                                        <td style="padding:12px 8px;">
                                            <span class="pill-type <?= $dtx['type'] === 'income' ? 'pill-income' : 'pill-expense' ?>">
                                                <?= strtoupper($dtx['type']) ?>
                                            </span>
                                        </td>
                                        <td style="padding:12px 8px;color:var(--text-2);"><?= htmlspecialchars($dtx['category']) ?></td>
                                        <td style="padding:12px 8px;font-weight:700;font-family:var(--font-mono);color:var(--text-1);">
                                            KES <?= number_format($dtx['amount'], 2) ?>
                                        </td>
                                        <td style="padding:12px 8px;color:var(--text-3);"><?= htmlspecialchars($dtx['date'] ?: substr($dtx['created_at'], 0, 10)) ?></td>
                                        <td style="padding:12px 8px;text-align:right;">
                                            <form method="POST" action="../actions/restore_transaction.php" style="display:inline;" onsubmit="return confirm('Restore this transaction back to the user\'s active ledger?');">
                                                <?= csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?= $dtx['transaction_id'] ?>">
                                                <button type="submit" class="btn btn-sm" style="background:rgba(16,185,129,0.15);color:#10B981;border:1px solid rgba(16,185,129,0.3);padding:6px 12px;font-size:11.5px;font-weight:600;border-radius:6px;cursor:pointer;">
                                                    ↻ Restore to Ledger
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->

<script>
// Monthly trend chart
const trendCtx = document.getElementById('systemTrendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($months) ?>,
        datasets: [
            {
                label: 'System Income',
                data: <?= json_encode($monthly_incomes) ?>,
                backgroundColor: 'rgba(16,185,129,0.7)',
                borderRadius: 4
            },
            {
                label: 'System Expenses',
                data: <?= json_encode($monthly_expenses) ?>,
                backgroundColor: 'rgba(239,68,68,0.7)',
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#94a3b8', font: { family: 'Plus Jakarta Sans' } } }
        },
        scales: {
            x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b' } },
            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b' } }
        }
    }
});

// Category chart
const catCtx = document.getElementById('systemCatChart').getContext('2d');
new Chart(catCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($cat_labels) ?>,
        datasets: [{
            data: <?= json_encode($cat_data) ?>,
            backgroundColor: <?= json_encode($cat_colors) ?>,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        cutout: '70%'
    }
});

function toggleSidebar() {
    const layout  = document.querySelector('.layout');
    const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;

    if (window.innerWidth <= 900) {
        const isOpen = sidebar.classList.toggle('open');
        sidebar.classList.toggle('mobile-open', isOpen);
        if (overlay) {
            overlay.classList.toggle('open', isOpen);
            overlay.classList.toggle('active', isOpen);
        }
        document.body.style.overflow = isOpen ? 'hidden' : '';
    } else {
        const collapsed = sidebar.classList.toggle('collapsed');
        if (layout) layout.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    }
}

window.addEventListener('resize', function() {
    if (window.innerWidth > 900) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('open', 'mobile-open');
        if (overlay) overlay.classList.remove('open', 'active');
        document.body.style.overflow = '';
    }
});
</script>

</body>
</html>

