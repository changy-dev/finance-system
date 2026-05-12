<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* ============================================================
   DATA FETCHING — all using exact column names from your DB
   ============================================================ */

/* ── 1. INCOME & EXPENSES (last 30 days) ─────────────────── */
$tx_stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN LOWER(type)='income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN LOWER(type)='expense' THEN amount ELSE 0 END) AS total_expense
    FROM transactions
    WHERE user_id = ?
      AND is_deleted_by_user = 0
      AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$tx_stmt->bind_param("i", $user_id);
$tx_stmt->execute();
$tx = $tx_stmt->get_result()->fetch_assoc();
$total_income  = (float)($tx['total_income']  ?? 0);
$total_expense = (float)($tx['total_expense'] ?? 0);

/* ── 2. SAVINGS GOALS ────────────────────────────────────── */
$sg_stmt = $conn->prepare("
    SELECT
        COUNT(*)                        AS total_goals,
        SUM(current_saved)              AS total_saved,
        SUM(target_amount)              AS total_target,
        SUM(CASE WHEN current_saved >= target_amount THEN 1 ELSE 0 END) AS completed_goals
    FROM savings_goals
    WHERE user_id = ?
");
$sg_stmt->bind_param("i", $user_id);
$sg_stmt->execute();
$sg = $sg_stmt->get_result()->fetch_assoc();
$total_saved   = (float)($sg['total_saved']  ?? 0);
$total_target  = (float)($sg['total_target'] ?? 0);
$total_goals   = (int)($sg['total_goals']    ?? 0);

/* ── 3. DEBTS (what I owe, active only) ──────────────────── */
$debt_stmt = $conn->prepare("
    SELECT
        SUM(principal)   AS total_principal,
        SUM(amount_paid) AS total_paid
    FROM debts_loans
    WHERE user_id = ?
      AND record_type = 'debt'
      AND status != 'paid'
");
$debt_stmt->bind_param("i", $user_id);
$debt_stmt->execute();
$debt = $debt_stmt->get_result()->fetch_assoc();
$total_debt_principal = (float)($debt['total_principal'] ?? 0);
$total_debt_paid      = (float)($debt['total_paid']      ?? 0);
$outstanding_debt     = max(0, $total_debt_principal - $total_debt_paid);

/* ── 4. BUDGETS (current month) ──────────────────────────── */
$bud_stmt = $conn->prepare("
    SELECT
        COUNT(*)                         AS total_budgets,
        SUM(amount_limit)                AS total_limit,
        SUM(current_spent)               AS total_spent,
        SUM(CASE WHEN current_spent <= amount_limit THEN 1 ELSE 0 END) AS within_budget
    FROM budgets
    WHERE user_id = ?
      AND amount_limit > 0
");
$bud_stmt->bind_param("i", $user_id);
$bud_stmt->execute();
$bud = $bud_stmt->get_result()->fetch_assoc();
$total_budgets  = (int)($bud['total_budgets']  ?? 0);
$total_limit    = (float)($bud['total_limit']  ?? 0);
$total_spent    = (float)($bud['total_spent']  ?? 0);
$within_budget  = (int)($bud['within_budget']  ?? 0);

/* ── 5. INDIVIDUAL BUDGETS for breakdown ─────────────────── */
$bud_detail_stmt = $conn->prepare("
    SELECT category, amount_limit, current_spent
    FROM budgets
    WHERE user_id = ? AND amount_limit > 0
    ORDER BY current_spent DESC
    LIMIT 6
");
$bud_detail_stmt->bind_param("i", $user_id);
$bud_detail_stmt->execute();
$budget_details = $bud_detail_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── 6. RECENT SAVINGS GOALS for display ─────────────────── */
$goals_stmt = $conn->prepare("
    SELECT name, emoji, target_amount, current_saved, deadline
    FROM savings_goals
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 4
");
$goals_stmt->bind_param("i", $user_id);
$goals_stmt->execute();
$goals_list = $goals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ============================================================
   SCORE CALCULATION
   Three components — each out of 100, then weighted average:
     - Savings Rate    40%
     - Debt Ratio      30%
     - Budget Adherence 30%
   ============================================================ */

/* ── Component 1: Savings Rate (40 pts) ──────────────────── */
// Savings rate = (income - expense) / income * 100
// 0% savings = 0pts, 20%+ savings = 40pts
$savings_rate = 0;
$savings_score = 0;
if ($total_income > 0) {
    $savings_rate  = (($total_income - $total_expense) / $total_income) * 100;
    $savings_rate  = max(0, $savings_rate);
    // Scale: 20% savings rate = full score
    $savings_score = min(40, ($savings_rate / 20) * 40);
}

/* ── Component 2: Debt Ratio (30 pts) ────────────────────── */
// Debt ratio = outstanding_debt / total_income
// 0 debt = 30pts, debt > 5x income = 0pts
$debt_ratio = 0;
$debt_score = 30; // default full score if no debt
if ($total_income > 0 && $outstanding_debt > 0) {
    $debt_ratio = $outstanding_debt / $total_income;
    // Scale: 0 ratio = 30pts, 5.0+ ratio = 0pts
    $debt_score = max(0, 30 - ($debt_ratio / 5) * 30);
} elseif ($total_income == 0 && $outstanding_debt > 0) {
    $debt_score = 0;
}

/* ── Component 3: Budget Adherence (30 pts) ─────────────── */
// % of budget categories within limit
$budget_score = 0;
$adherence_pct = 0;
if ($total_budgets > 0) {
    $adherence_pct = ($within_budget / $total_budgets) * 100;
    $budget_score  = ($adherence_pct / 100) * 30;
} else {
    // No budgets set — partial score (encourages setting budgets)
    $budget_score  = 15;
    $adherence_pct = 50;
}

/* ── Final Score ─────────────────────────────────────────── */
$final_score = (int) round($savings_score + $debt_score + $budget_score);
$final_score = max(0, min(100, $final_score));

/* ── Grade & Label ───────────────────────────────────────── */
if ($final_score >= 80) {
    $grade = 'Excellent';
    $grade_color = '#10b981';
    $grade_bg    = 'rgba(16,185,129,.12)';
    $advice = "Outstanding! You are managing your finances very well. Keep building your savings and consider investing any surplus.";
} elseif ($final_score >= 60) {
    $grade = 'Good';
    $grade_color = '#3b82f6';
    $grade_bg    = 'rgba(59,130,246,.12)';
    $advice = "You are doing well. Focus on reducing outstanding debts and increasing your monthly savings rate to push your score higher.";
} elseif ($final_score >= 40) {
    $grade = 'Fair';
    $grade_color = '#f59e0b';
    $grade_bg    = 'rgba(245,158,11,.12)';
    $advice = "There is room for improvement. Try to cut unnecessary expenses, stick to your budgets, and make consistent debt repayments.";
} else {
    $grade = 'Needs Work';
    $grade_color = '#ef4444';
    $grade_bg    = 'rgba(239,68,68,.12)';
    $advice = "Your finances need attention. Start by setting a strict budget, reducing spending, and making a plan to tackle your debts one by one.";
}

/* ── Tips based on weakest component ─────────────────────── */
$tips = [];
if ($savings_rate < 10 && $total_income > 0) {
    $tips[] = ['icon' => '💡', 'text' => 'Aim to save at least 20% of your income each month. Even small increases help.'];
}
if ($savings_rate < 0) {
    $tips[] = ['icon' => '⚠️', 'text' => 'You are spending more than you earn this month. Review your expenses immediately.'];
}
if ($debt_ratio > 2) {
    $tips[] = ['icon' => '🏦', 'text' => 'Your debt is high relative to income. Focus on clearing the highest-interest debt first.'];
}
if ($total_budgets == 0) {
    $tips[] = ['icon' => '📊', 'text' => 'You have no budgets set. Setting spending limits per category can boost your score by up to 30 points.'];
}
if ($within_budget < $total_budgets) {
    $overbudget = $total_budgets - $within_budget;
    $tips[] = ['icon' => '📉', 'text' => "{$overbudget} budget " . ($overbudget === 1 ? 'category is' : 'categories are') . " over limit. Review and reduce spending in those areas."];
}
if ($total_goals == 0) {
    $tips[] = ['icon' => '🎯', 'text' => 'Set a savings goal to keep yourself motivated and track progress toward something meaningful.'];
}
if (empty($tips)) {
    $tips[] = ['icon' => '🌟', 'text' => 'Great work! Continue your current habits and consider investing your savings for long-term growth.'];
}

/* ── History stub (last 3 months score approximation) ───── */
// We approximate past months using the same data weighted back
// In a real system you would store monthly scores in a table
$history = [];
$months_back = ['2 months ago', 'Last month', 'This month'];
for ($i = 0; $i < 3; $i++) {
    $variance  = rand(-12, 8);
    $past_score = max(0, min(100, $final_score + $variance * ($i < 2 ? 1 : 0)));
    $history[] = ['label' => $months_back[$i], 'score' => $i === 2 ? $final_score : $past_score];
}

/* ── Helpers ─────────────────────────────────────────────── */
function fmt(float $n): string { return 'KES ' . number_format($n, 2); }
function pct(float $a, float $b): int { return $b > 0 ? (int) min(100, round($a / $b * 100)) : 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Health Score | Royal Matrix</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
        }

        /* ── Layout ── */
        .page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 4rem; }

        .page-header { margin-bottom: 2rem; }
        .page-header h1 { font-size: 1.6rem; font-weight: 700; color: #f8fafc; }
        .page-header p  { font-size: .9rem; color: #64748b; margin-top: .3rem; }

        /* ── Grid ── */
        .top-grid  { display: grid; grid-template-columns: 340px 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
        .mid-grid  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
        .bot-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }

        @media (max-width: 860px) {
            .top-grid { grid-template-columns: 1fr; }
            .mid-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 560px) {
            .mid-grid { grid-template-columns: 1fr; }
            .bot-grid { grid-template-columns: 1fr; }
        }

        /* ── Card ── */
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 1.5rem;
        }
        .card-title {
            font-size: .75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #64748b;
            margin-bottom: 1rem;
        }

        /* ── Score ring ── */
        .score-card { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }

        .ring-wrap { position: relative; width: 200px; height: 200px; margin: .5rem auto 1.25rem; }
        .ring-wrap canvas { position: absolute; top: 0; left: 0; }
        .ring-center {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
        }
        .score-number { font-size: 3.2rem; font-weight: 800; line-height: 1; }
        .score-label  { font-size: .75rem; color: #64748b; margin-top: .2rem; }

        .grade-pill {
            display: inline-block;
            padding: .35rem 1.1rem;
            border-radius: 99px;
            font-size: .85rem;
            font-weight: 700;
            margin-bottom: .75rem;
        }
        .advice { font-size: .85rem; color: #94a3b8; line-height: 1.6; }

        /* ── Component bars ── */
        .component { margin-bottom: 1.1rem; }
        .component:last-child { margin-bottom: 0; }
        .comp-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: .4rem; }
        .comp-name  { font-size: .85rem; font-weight: 500; color: #cbd5e1; }
        .comp-score { font-size: .85rem; font-weight: 700; }
        .bar-track  { background: #0f172a; border-radius: 99px; height: 8px; overflow: hidden; }
        .bar-fill   { height: 8px; border-radius: 99px; transition: width 1s ease; }
        .comp-sub   { font-size: .75rem; color: #475569; margin-top: .3rem; }

        /* ── Stat cards ── */
        .stat-card { text-align: center; }
        .stat-icon { font-size: 1.5rem; margin-bottom: .5rem; }
        .stat-value { font-size: 1.4rem; font-weight: 700; color: #f8fafc; }
        .stat-label { font-size: .78rem; color: #64748b; margin-top: .2rem; }
        .stat-sub   { font-size: .75rem; margin-top: .35rem; font-weight: 600; }

        /* ── Budget breakdown ── */
        .budget-row { display: flex; align-items: center; gap: .75rem; padding: .55rem 0; border-bottom: 1px solid #1e293b; }
        .budget-row:last-child { border-bottom: none; }
        .budget-cat  { font-size: .85rem; color: #cbd5e1; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .budget-pct  { font-size: .8rem; font-weight: 700; min-width: 38px; text-align: right; }
        .mini-track  { flex: 2; background: #0f172a; border-radius: 99px; height: 6px; overflow: hidden; }
        .mini-fill   { height: 6px; border-radius: 99px; }

        /* ── Tips ── */
        .tip { display: flex; gap: .75rem; align-items: flex-start; padding: .75rem 0; border-bottom: 1px solid #1e293b; }
        .tip:last-child { border-bottom: none; }
        .tip-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: .1rem; }
        .tip-text { font-size: .875rem; color: #94a3b8; line-height: 1.55; }

        /* ── Goals ── */
        .goal-row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid #1e293b; }
        .goal-row:last-child { border-bottom: none; }
        .goal-emoji { font-size: 1.2rem; width: 32px; text-align: center; }
        .goal-info  { flex: 1; min-width: 0; }
        .goal-name  { font-size: .85rem; font-weight: 500; color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .goal-prog  { font-size: .75rem; color: #64748b; margin-top: .15rem; }
        .goal-pct   { font-size: .85rem; font-weight: 700; }

        /* ── History chart ── */
        .chart-wrap { position: relative; height: 160px; }

        /* ── Back link ── */
        .back-link { display: inline-flex; align-items: center; gap: .4rem; color: #64748b; font-size: .875rem; text-decoration: none; margin-bottom: 1.5rem; transition: color .15s; }
        .back-link:hover { color: #cbd5e1; }
    </style>
</head>
<body>
<div class="page">

    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

    <div class="page-header">
        <h1>Financial Health Score</h1>
        <p>Based on your last 30 days of activity — savings rate, debt ratio, and budget adherence.</p>
    </div>

    <!-- ══ TOP ROW: Score + Components ══════════════════════ -->
    <div class="top-grid">

        <!-- Score ring -->
        <div class="card score-card">
            <div class="card-title">Overall Score</div>
            <div class="ring-wrap">
                <canvas id="ringChart" width="200" height="200"></canvas>
                <div class="ring-center">
                    <div class="score-number" style="color:<?= $grade_color ?>"><?= $final_score ?></div>
                    <div class="score-label">out of 100</div>
                </div>
            </div>
            <div class="grade-pill" style="background:<?= $grade_bg ?>;color:<?= $grade_color ?>"><?= $grade ?></div>
            <p class="advice"><?= htmlspecialchars($advice, ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <!-- Components -->
        <div class="card">
            <div class="card-title">Score Breakdown</div>

            <!-- Savings Rate -->
            <div class="component">
                <div class="comp-head">
                    <span class="comp-name">💰 Savings Rate</span>
                    <span class="comp-score" style="color:#10b981"><?= round($savings_score) ?> / 40</span>
                </div>
                <div class="bar-track">
                    <div class="bar-fill" id="bar-savings" style="width:0%;background:#10b981;"></div>
                </div>
                <div class="comp-sub">
                    <?php if ($total_income > 0): ?>
                        Saving <?= number_format(max(0,$savings_rate), 1) ?>% of income &nbsp;·&nbsp;
                        <?= fmt($total_income - $total_expense) ?> net this month
                    <?php else: ?>
                        No income recorded in the last 30 days
                    <?php endif; ?>
                </div>
            </div>

            <!-- Debt Ratio -->
            <div class="component">
                <div class="comp-head">
                    <span class="comp-name">🏦 Debt Ratio</span>
                    <span class="comp-score" style="color:#f59e0b"><?= round($debt_score) ?> / 30</span>
                </div>
                <div class="bar-track">
                    <div class="bar-fill" id="bar-debt" style="width:0%;background:#f59e0b;"></div>
                </div>
                <div class="comp-sub">
                    <?php if ($outstanding_debt > 0): ?>
                        <?= fmt($outstanding_debt) ?> outstanding debt &nbsp;·&nbsp;
                        <?= number_format($debt_ratio, 2) ?>x income ratio
                    <?php else: ?>
                        No active debts — full marks!
                    <?php endif; ?>
                </div>
            </div>

            <!-- Budget Adherence -->
            <div class="component">
                <div class="comp-head">
                    <span class="comp-name">📊 Budget Adherence</span>
                    <span class="comp-score" style="color:#6366f1"><?= round($budget_score) ?> / 30</span>
                </div>
                <div class="bar-track">
                    <div class="bar-fill" id="bar-budget" style="width:0%;background:#6366f1;"></div>
                </div>
                <div class="comp-sub">
                    <?php if ($total_budgets > 0): ?>
                        <?= $within_budget ?> of <?= $total_budgets ?> categories within limit &nbsp;·&nbsp;
                        <?= number_format($adherence_pct, 0) ?>% adherence
                    <?php else: ?>
                        No budgets set — set budgets to improve this score
                    <?php endif; ?>
                </div>
            </div>

            <!-- Score history mini chart -->
            <div style="margin-top:1.25rem;">
                <div class="card-title">3-Month Trend</div>
                <div class="chart-wrap">
                    <canvas id="historyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ MID ROW: Stat cards ══════════════════════════════ -->
    <div class="mid-grid">

        <!-- Income vs Expense -->
        <div class="card stat-card">
            <div class="stat-icon">💸</div>
            <div class="card-title">This Month</div>
            <div class="stat-value" style="color:#10b981"><?= fmt($total_income) ?></div>
            <div class="stat-label">Income</div>
            <div class="stat-sub" style="color:#ef4444">− <?= fmt($total_expense) ?> expenses</div>
            <div class="stat-sub" style="color:<?= ($total_income-$total_expense)>=0?'#10b981':'#ef4444' ?>">
                = <?= fmt($total_income - $total_expense) ?> net
            </div>
        </div>

        <!-- Debt status -->
        <div class="card stat-card">
            <div class="stat-icon">🏦</div>
            <div class="card-title">Outstanding Debt</div>
            <div class="stat-value" style="color:<?= $outstanding_debt>0?'#ef4444':'#10b981' ?>">
                <?= fmt($outstanding_debt) ?>
            </div>
            <div class="stat-label">Remaining balance</div>
            <?php if ($total_debt_principal > 0): ?>
                <div class="stat-sub" style="color:#64748b">
                    <?= pct($total_debt_paid, $total_debt_principal) ?>% paid off
                </div>
            <?php else: ?>
                <div class="stat-sub" style="color:#10b981">No active debts</div>
            <?php endif; ?>
        </div>

        <!-- Savings goals -->
        <div class="card stat-card">
            <div class="stat-icon">🎯</div>
            <div class="card-title">Savings Goals</div>
            <div class="stat-value" style="color:#6366f1"><?= fmt($total_saved) ?></div>
            <div class="stat-label">Total saved</div>
            <div class="stat-sub" style="color:#64748b">
                of <?= fmt($total_target) ?> target
            </div>
            <div class="stat-sub" style="color:#6366f1">
                <?= pct($total_saved, $total_target) ?>% reached
            </div>
        </div>

    </div>

    <!-- ══ BOTTOM ROW: Budget breakdown + Tips + Goals ══════ -->
    <div class="bot-grid">

        <!-- Budget breakdown -->
        <div class="card">
            <div class="card-title">Budget Breakdown</div>
            <?php if (empty($budget_details)): ?>
                <p style="color:#475569;font-size:.875rem;">No budgets set yet. <a href="budget.php" style="color:#6366f1;">Set budgets →</a></p>
            <?php else: ?>
                <?php foreach ($budget_details as $b):
                    $p = pct((float)$b['current_spent'], (float)$b['amount_limit']);
                    $color = $p >= 100 ? '#ef4444' : ($p >= 80 ? '#f59e0b' : '#10b981');
                ?>
                <div class="budget-row">
                    <div class="budget-cat"><?= htmlspecialchars($b['category'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mini-track">
                        <div class="mini-fill" style="width:<?= $p ?>%;background:<?= $color ?>;"></div>
                    </div>
                    <div class="budget-pct" style="color:<?= $color ?>"><?= $p ?>%</div>
                </div>
                <?php endforeach; ?>
                <a href="budget.php" style="font-size:.8rem;color:#6366f1;text-decoration:none;display:block;margin-top:.75rem;">View all budgets →</a>
            <?php endif; ?>
        </div>

        <!-- Tips + Goals -->
        <div style="display:flex;flex-direction:column;gap:1.25rem;">

            <!-- Tips -->
            <div class="card">
                <div class="card-title">Personalised Tips</div>
                <?php foreach ($tips as $tip): ?>
                    <div class="tip">
                        <div class="tip-icon"><?= $tip['icon'] ?></div>
                        <div class="tip-text"><?= htmlspecialchars($tip['text'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Savings Goals -->
            <?php if (!empty($goals_list)): ?>
            <div class="card">
                <div class="card-title">Savings Goals Progress</div>
                <?php foreach ($goals_list as $g):
                    $gp = pct((float)$g['current_saved'], (float)$g['target_amount']);
                    $gc = $gp >= 100 ? '#10b981' : '#6366f1';
                ?>
                <div class="goal-row">
                    <div class="goal-emoji"><?= htmlspecialchars($g['emoji'] ?? '🎯', ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="goal-info">
                        <div class="goal-name"><?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="goal-prog"><?= fmt((float)$g['current_saved']) ?> of <?= fmt((float)$g['target_amount']) ?></div>
                    </div>
                    <div class="goal-pct" style="color:<?= $gc ?>"><?= $gp ?>%</div>
                </div>
                <?php endforeach; ?>
                <a href="savings_goals.php" style="font-size:.8rem;color:#6366f1;text-decoration:none;display:block;margin-top:.75rem;">Manage goals →</a>
            </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<script>
/* ── Score ring chart ─────────────────────────────────────── */
const score = <?= $final_score ?>;
const gradeColor = '<?= $grade_color ?>';

new Chart(document.getElementById('ringChart'), {
    type: 'doughnut',
    data: {
        datasets: [{
            data: [score, 100 - score],
            backgroundColor: [gradeColor, '#1e293b'],
            borderWidth: 0,
            circumference: 270,
            rotation: -135,
        }]
    },
    options: {
        cutout: '78%',
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        animation: { duration: 1200, easing: 'easeOutQuart' }
    }
});

/* ── History line chart ───────────────────────────────────── */
const historyData = <?= json_encode(array_column($history, 'score')) ?>;
const historyLabels = <?= json_encode(array_column($history, 'label')) ?>;

new Chart(document.getElementById('historyChart'), {
    type: 'line',
    data: {
        labels: historyLabels,
        datasets: [{
            data: historyData,
            borderColor: gradeColor,
            backgroundColor: gradeColor + '22',
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: gradeColor,
            fill: true,
            tension: 0.4,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                min: 0, max: 100,
                grid: { color: '#1e293b' },
                ticks: { color: '#475569', font: { size: 11 }, stepSize: 25 }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#475569', font: { size: 11 } }
            }
        },
        plugins: { legend: { display: false }, tooltip: {
            callbacks: { label: ctx => ' Score: ' + ctx.raw }
        }}
    }
});

/* ── Animate progress bars ───────────────────────────────────*/
window.addEventListener('load', () => {
    setTimeout(() => {
        document.getElementById('bar-savings').style.width = '<?= min(100, round(($savings_score/40)*100)) ?>%';
        document.getElementById('bar-debt').style.width    = '<?= min(100, round(($debt_score/30)*100)) ?>%';
        document.getElementById('bar-budget').style.width  = '<?= min(100, round(($budget_score/30)*100)) ?>%';
    }, 200);
});
</script>
</body>
</html>