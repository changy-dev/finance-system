<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* MONTHLY TREND */
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthly = array_fill(0,12,0);

$res = $conn->query("
SELECT MONTH(created_at) m, SUM(amount) total 
FROM transactions 
WHERE user_id='$user_id' AND type='expense'
GROUP BY m
");

if ($res && $res->num_rows > 0) {
    while($row = $res->fetch_assoc()){
        $monthly[$row['m']-1] = (int)$row['total'];
    }
}

/* CATEGORY */
$catLabels = [];
$catData = [];
$catColors = [];

$colors = ['#3b82f6','#ef4444','#10b981','#f59e0b','#8b5cf6','#06b6d4'];

$q = $conn->query("
SELECT category, SUM(amount) total 
FROM transactions 
WHERE user_id='$user_id' AND type='expense'
GROUP BY category
ORDER BY total DESC
");

$totalExpense = 0;
$i = 0;

if ($q && $q->num_rows > 0) {
    while($r = $q->fetch_assoc()){
        $catLabels[] = $r['category'];
        $catData[] = (int)$r['total'];
        $catColors[] = $colors[$i % count($colors)];
        $totalExpense += (int)$r['total'];
        $i++;
    }
}

/* Get recent transactions */
$recent = $conn->query("
SELECT * FROM transactions 
WHERE user_id='$user_id'
ORDER BY created_at DESC
LIMIT 5
");

if (!$recent) {
    $recent = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Finance Dashboard</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #3b82f6;
    --primary-dark: #1e40af;
    --secondary: #8b5cf6;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --bg-dark: #0f172a;
    --bg-darker: #020617;
    --surface: #1e293b;
    --border: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-tertiary: #94a3b8;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    background: linear-gradient(135deg, var(--bg-darker) 0%, #0a0e27 100%);
    color: var(--text-primary);
    line-height: 1.6;
}

.layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    min-height: 100vh;
}

/* SIDEBAR */
.sidebar {
    background: var(--bg-dark);
    border-right: 1px solid var(--border);
    padding: 24px 0;
    position: fixed;
    left: 0;
    top: 0;
    width: 260px;
    height: 100vh;
    overflow-y: auto;
    z-index: 100;
}

.sidebar-header {
    padding: 0 20px 30px;
    border-bottom: 1px solid var(--border);
}

.logo {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 24px;
    font-weight: 700;
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.nav {
    margin-top: 20px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: var(--text-tertiary);
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
    border-left: 3px solid transparent;
}

.nav-item:hover {
    color: var(--text-secondary);
    background: rgba(59, 130, 246, 0.1);
}

.nav-item.active {
    color: var(--primary);
    background: rgba(59, 130, 246, 0.15);
    border-left-color: var(--primary);
}

.nav-item svg {
    width: 18px;
    height: 18px;
}

/* MAIN */
.main {
    grid-column: 2;
    padding: 40px;
    margin-left: 0;
}

/* HEADER */
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 40px;
}

.header-title {
    font-size: 32px;
    font-weight: 700;
}

.header-subtitle {
    font-size: 14px;
    color: var(--text-tertiary);
    margin-top: 4px;
}

.header-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 16px;
    border-radius: 8px;
    border: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
}

.btn-secondary {
    background: var(--surface);
    color: var(--text-primary);
    border: 1px solid var(--border);
}

.btn-secondary:hover {
    background: rgba(59, 130, 246, 0.1);
    border-color: var(--primary);
}

/* STATS */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.stat-card {
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    transition: all 0.3s ease;
    animation: fadeIn 0.4s ease forwards;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }

.stat-card:hover {
    border-color: var(--primary);
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.1);
    transform: translateY(-2px);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.stat-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(59, 130, 246, 0.15);
    color: var(--primary);
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 8px;
}

.stat-change {
    font-size: 12px;
    color: var(--text-tertiary);
}

.stat-change.positive {
    color: var(--success);
}

.stat-change.negative {
    color: var(--danger);
}

/* CHARTS */
.charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 40px;
}

.card {
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    animation: fadeIn 0.4s ease forwards;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.card-title {
    font-size: 18px;
    font-weight: 700;
}

.card-actions {
    display: flex;
    gap: 8px;
}

.chart-container {
    position: relative;
    height: 300px;
}

.doughnut-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 300px;
}

.doughnut-center {
    position: absolute;
    text-align: center;
    pointer-events: none;
}

.doughnut-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
}

.doughnut-value {
    font-size: 24px;
    font-weight: 700;
    margin-top: 4px;
}

/* TRANSACTIONS */
.transactions-card {
    grid-column: 1 / -1;
}

.tx-table {
    width: 100%;
    border-collapse: collapse;
}

.tx-table th {
    text-align: left;
    padding: 12px 0;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    border-bottom: 1px solid var(--border);
}

.tx-table td {
    padding: 16px 0;
    border-bottom: 1px solid var(--border);
}

.tx-category {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
}

.tx-badge {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.tx-amount {
    font-weight: 600;
    color: var(--danger);
}

.tx-date {
    font-size: 12px;
    color: var(--text-tertiary);
}

.tx-list-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-tertiary);
}

.view-all-link {
    color: var(--primary);
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.view-all-link:hover {
    color: var(--primary-dark);
}

.select-filter {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text-primary);
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    transition: all 0.3s ease;
    cursor: pointer;
}

.select-filter:hover {
    border-color: var(--primary);
}

.select-filter:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* MODAL */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 1000;
    animation: fadeIn 0.3s ease;
}

.modal-overlay.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal {
    background: var(--bg-dark);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 32px;
    max-width: 500px;
    width: 90%;
    animation: slideUp 0.3s ease;
}

.modal-header {
    margin-bottom: 24px;
}

.modal-title {
    font-size: 24px;
    font-weight: 700;
}

.modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    background: none;
    border: none;
    color: var(--text-tertiary);
    cursor: pointer;
    font-size: 24px;
    transition: color 0.3s ease;
}

.modal-close:hover {
    color: var(--text-primary);
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    margin-bottom: 8px;
}

.form-input,
.form-select,
.form-textarea {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text-primary);
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

.modal-actions {
    display: flex;
    gap: 12px;
    margin-top: 32px;
}

.modal-actions button {
    flex: 1;
    padding: 12px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.modal-actions .btn-primary {
    background: var(--primary);
    color: white;
}

.modal-actions .btn-primary:hover {
    background: var(--primary-dark);
}

.modal-actions .btn-secondary {
    background: var(--surface);
    color: var(--text-primary);
    border: 1px solid var(--border);
}

.modal-actions .btn-secondary:hover {
    background: rgba(59, 130, 246, 0.1);
}

/* TOAST NOTIFICATION */
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--success);
    color: white;
    padding: 16px 24px;
    border-radius: 8px;
    animation: slideUp 0.3s ease;
    z-index: 2000;
}

.toast.error {
    background: var(--danger);
}

/* RESPONSIVE */
@media (max-width: 1200px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .layout {
        grid-template-columns: 1fr;
    }

    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
        border-right: none;
        border-bottom: 1px solid var(--border);
        padding: 16px 20px;
    }

    .sidebar-header {
        padding: 0;
        border: none;
    }

    .nav {
        display: flex;
        gap: 8px;
        margin-top: 0;
        overflow-x: auto;
        padding-bottom: 8px;
    }

    .nav-item {
        white-space: nowrap;
        padding: 8px 12px;
        border-left: none;
        border-bottom: 2px solid transparent;
    }

    .nav-item.active {
        border-left: none;
        border-bottom-color: var(--primary);
    }

    .main {
        padding: 20px;
    }

    .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .header-title {
        font-size: 24px;
    }

    .modal {
        padding: 24px;
    }
}

/* ANIMATIONS */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.stat-card, .card {
    animation: fadeIn 0.4s ease forwards;
}

/* SCROLLBAR */
::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

::-webkit-scrollbar-track {
    background: var(--bg-dark);
}

::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--text-tertiary);
}
</style>
</head>

<body>

<div class="layout">
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                💰 Finance
            </div>
        </div>
        
        <nav class="nav">
            <a href="#" class="nav-item active">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-3m0 0l7-4 7 4M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9m-9 16l-7-4m0 0l-2-3m2 3v10a1 1 0 001 1h12a1 1 0 001-1v-10m0 0l2-3" />
                </svg>
                Dashboard
            </a>
            <a href="transactions.php" class="nav-item">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Transactions
            </a>
            <a href="settings.php" class="nav-item">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Settings
            </a>
        </nav>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- HEADER -->
        <div class="header">
            <div>
                <h1 class="header-title">Welcome Back</h1>
                <p class="header-subtitle">Here's your financial overview</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary">Export</button>
                <button class="btn btn-primary" onclick="openAddTransactionModal()">+ Add Transaction</button>
            </div>
        </div>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Total Expenses</span>
                    <div class="stat-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                        </svg>
                    </div>
                </div>
                <div class="stat-value">KES <?php echo number_format($totalExpense); ?></div>
                <div class="stat-change negative">↑ 12.5% from last month</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Categories</span>
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.15); color: var(--success);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 11l3 3L22 4" />
                        </svg>
                    </div>
                </div>
                <div class="stat-value"><?php echo count($catLabels); ?></div>
                <div class="stat-change">Active spending categories</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Monthly Average</span>
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.15); color: var(--warning);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="12 1 v22 M4.22 4.22l15.56 15.56 M4.22 19.78L19.78 4.22" />
                        </svg>
                    </div>
                </div>
                <div class="stat-value">KES <?php echo number_format(count($catLabels) > 0 ? $totalExpense/12 : 0); ?></div>
                <div class="stat-change positive">↓ 5% optimized</div>
            </div>
        </div>

        <!-- CHARTS -->
        <div class="charts-grid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Spending Trend</h3>
                    <div class="card-actions">
                        <select class="select-filter">
                            <option>Last 12 Months</option>
                            <option>Last 6 Months</option>
                            <option>Last 3 Months</option>
                        </select>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="lineChart"></canvas>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Category Breakdown</h3>
                </div>
                <?php if(count($catLabels) > 0): ?>
                    <div class="doughnut-wrapper">
                        <canvas id="pieChart" style="max-width: 200px;"></canvas>
                        <div class="doughnut-center">
                            <div class="doughnut-label">Total</div>
                            <div class="doughnut-value" id="centerValue">KES 0</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tx-list-empty">
                        <p>No expenses recorded yet</p>
                        <p style="font-size: 12px; margin-top: 8px;">Add a transaction to see your breakdown</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TRANSACTIONS -->
        <div class="card transactions-card">
            <div class="card-header">
                <h3 class="card-title">Recent Transactions</h3>
                <a href="transactions.php" class="view-all-link">View All →</a>
            </div>

            <table class="tx-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody id="txList">
                    <?php if($recent && $recent->num_rows > 0): ?>
                        <?php while($tx = $recent->fetch_assoc()): 
                            $color = isset($catColors[array_search($tx['category'], $catLabels)]) 
                                ? $catColors[array_search($tx['category'], $catLabels)] 
                                : '#3b82f6';
                        ?>
                            <tr>
                                <td>
                                    <div class="tx-category">
                                        <div class="tx-badge" style="background: <?php echo $color; ?>"></div>
                                        <?php echo ucfirst($tx['category']); ?>
                                    </div>
                                </td>
                                <td><span class="tx-amount">-KES <?php echo number_format($tx['amount']); ?></span></td>
                                <td><span class="tx-date"><?php echo date('M d, Y', strtotime($tx['created_at'])); ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="tx-list-empty">No transactions yet. Start by adding one!</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- ADD TRANSACTION MODAL -->
<div class="modal-overlay" id="transactionModal">
    <div class="modal">
        <button class="modal-close" onclick="closeAddTransactionModal()">✕</button>
        
        <div class="modal-header">
            <h2 class="modal-title">Add Transaction</h2>
        </div>

        <form id="transactionForm">
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-select" required>
                    <option value="">Select a category</option>
                    <option value="Food">Food & Dining</option>
                    <option value="Transport">Transport</option>
                    <option value="Utilities">Utilities</option>
                    <option value="Entertainment">Entertainment</option>
                    <option value="Shopping">Shopping</option>
                    <option value="Health">Health</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Amount (KES)</label>
                <input type="number" name="amount" class="form-input" placeholder="Enter amount" step="0.01" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-textarea" placeholder="Add a note (optional)"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Date</label>
                <input type="date" name="created_at" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAddTransactionModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Transaction</button>
            </div>
        </form>
    </div>
</div>

<script>
/* MODAL FUNCTIONS */
function openAddTransactionModal() {
    document.getElementById('transactionModal').classList.add('active');
}

function closeAddTransactionModal() {
    document.getElementById('transactionModal').classList.remove('active');
}

/* Close modal when clicking outside */
document.getElementById('transactionModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddTransactionModal();
    }
});

/* FORM SUBMISSION */
document.getElementById('transactionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('add_transaction.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Transaction added successfully!');
            closeAddTransactionModal();
            document.getElementById('transactionForm').reset();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred', 'error');
    });
});

/* TOAST NOTIFICATION */
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

/* CHARTS */
let labels = <?php echo json_encode($catLabels); ?>;
let dataVals = <?php echo json_encode($catData); ?>;
let colors = <?php echo json_encode($catColors); ?>;
let total = dataVals.length > 0 ? dataVals.reduce((a,b)=>a+b,0) : 0;

if (document.getElementById('centerValue')) {
    document.getElementById('centerValue').innerText = "KES " + total.toLocaleString();
}

/* PIE CHART */
if (labels.length > 0) {
    const pieCtx = document.getElementById('pieChart');
    if (pieCtx) {
        let pie = new Chart(pieCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: dataVals,
                    backgroundColor: colors,
                    borderColor: '#0f172a',
                    borderWidth: 2,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 12,
                        titleColor: '#f1f5f9',
                        bodyColor: '#cbd5e1',
                        borderColor: '#334155',
                        borderWidth: 1,
                        displayColors: true
                    }
                },
                onHover: (e, el) => {
                    if(el.length) {
                        let i = el[0].index;
                        let label = document.querySelector('.doughnut-label');
                        if (label) label.innerText = labels[i];
                        document.getElementById('centerValue').innerText = "KES " + dataVals[i].toLocaleString();
                    }
                }
            }
        });
    }
}

/* LINE CHART */
const lineCtx = document.getElementById('lineChart');
if (lineCtx) {
    new Chart(lineCtx.getContext('2d'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                data: <?php echo json_encode($monthly); ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 5,
                pointHoverRadius: 7,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#0f172a',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    padding: 12,
                    titleColor: '#f1f5f9',
                    bodyColor: '#cbd5e1',
                    borderColor: '#334155',
                    borderWidth: 1,
                    displayColors: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(52, 65, 85, 0.5)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 12 }
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 12 }
                    }
                }
            }
        }
    });
}
</script>

</body>
</html>
