<?php
session_start();
require_once 'db.php';
require_once 'security.php';
require_once 'audit.php';


$plan_stmt = $conn->prepare("
    SELECT p.has_exports, p.name AS plan_name
    FROM subscriptions s
    JOIN plans p ON p.plan_id = s.plan_id
    WHERE s.user_id = ?
");
$plan_stmt->bind_param("i", $user_id);
$plan_stmt->execute();
$plan = $plan_stmt->get_result()->fetch_assoc();

$can_export = (bool)($plan['has_exports'] ?? false);


$format     = $_GET['format']   ?? '';
$date_from  = $_GET['from']     ?? date('Y-m-01');
$date_to    = $_GET['to']       ?? date('Y-m-d');
$type_filter = $_GET['type']    ?? '';

if (in_array($format, ['csv', 'pdf']) && !$can_export) {
    $_SESSION['export_flash'] = 'Exports are available on Pro and Business plans. Please upgrade.';
    header("Location: plans.php");
    exit();
}

if ($format === 'csv') {
    
    $where = "WHERE user_id=? AND is_deleted_by_user=0 AND date BETWEEN ? AND ?";
    $params = [$user_id, $date_from, $date_to];
    $types  = "iss";

    if ($type_filter !== '') {
        $where  .= " AND type=?";
        $params[] = $type_filter;
        $types   .= "s";
    }

    $stmt = $conn->prepare("
        SELECT date, type, category, amount, description, created_at
        FROM transactions
        $where
        ORDER BY date DESC, created_at DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    
    Audit::log($conn, $user_id, 'export.csv', 'transactions', null, null, [
        'from' => $date_from, 'to' => $date_to, 'count' => count($rows)
    ]);

    
    $filename = 'transactions_' . $date_from . '_to_' . $date_to . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Category', 'Amount (KES)', 'Description', 'Created At']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['date'],
            ucfirst($row['type']),
            $row['category'],
            number_format((float)$row['amount'], 2),
            $row['description'] ?? '',
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit();
}

if ($format === 'pdf') {
    
    $stmt = $conn->prepare("
        SELECT date, type, category, amount, description
        FROM transactions
        WHERE user_id=? AND is_deleted_by_user=0
          AND date BETWEEN ? AND ?
        ORDER BY date DESC
    ");
    $stmt->bind_param("iss", $user_id, $date_from, $date_to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $total_in  = array_sum(array_column(array_filter($rows, fn($r) => $r['type']==='income'),  'amount'));
    $total_out = array_sum(array_column(array_filter($rows, fn($r) => $r['type']==='expense'), 'amount'));

    
    $u = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
    $u->bind_param("i", $user_id);
    $u->execute();
    $urow = $u->get_result()->fetch_assoc();

    Audit::log($conn, $user_id, 'export.pdf', 'transactions', null, null, [
        'from' => $date_from, 'to' => $date_to, 'count' => count($rows)
    ]);


    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Statement <?= $date_from ?> to <?= $date_to ?></title>
<style>
  @media print { body { -webkit-print-color-adjust: exact; } .no-print { display: none; } }
  body { font-family: system-ui, sans-serif; padding: 2rem; color: #1e293b; max-width: 900px; margin: 0 auto; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #7c3aed; padding-bottom: 1rem; margin-bottom: 1.5rem; }
  .brand { font-size: 1.4rem; font-weight: 800; color: #7c3aed; }
  .meta { font-size: .8rem; color: #64748b; text-align: right; }
  .summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
  .stat { background: #f8fafc; border-radius: 8px; padding: 1rem; }
  .stat .label { font-size: .75rem; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
  .stat .value { font-size: 1.25rem; font-weight: 700; margin-top: .25rem; }
  .income  { color: #10b981; }
  .expense { color: #ef4444; }
  table { width: 100%; border-collapse: collapse; font-size: .875rem; }
  th { background: #f1f5f9; padding: .6rem .75rem; text-align: left; font-size: .75rem; text-transform: uppercase; color: #64748b; }
  td { padding: .6rem .75rem; border-bottom: 1px solid #f1f5f9; }
  .type-income  { color: #10b981; font-weight: 600; }
  .type-expense { color: #ef4444; font-weight: 600; }
  .btn-print { background: #7c3aed; color: #fff; border: none; padding: .6rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 700; margin-bottom: 1.5rem; }
</style>
</head>
<body>
<button class="btn-print no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>

<div class="header">
    <div>
        <div class="brand">Royal Matrix</div>
        <div style="font-size:.85rem;color:#64748b;margin-top:.25rem;">Transaction Statement</div>
    </div>
    <div class="meta">
        <div><?= htmlspecialchars($urow['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <div>Period: <?= $date_from ?> to <?= $date_to ?></div>
        <div>Generated: <?= date('d M Y H:i') ?></div>
    </div>
</div>

<div class="summary">
    <div class="stat">
        <div class="label">Total Income</div>
        <div class="value income">KES <?= number_format($total_in, 2) ?></div>
    </div>
    <div class="stat">
        <div class="label">Total Expenses</div>
        <div class="value expense">KES <?= number_format($total_out, 2) ?></div>
    </div>
    <div class="stat">
        <div class="label">Net Balance</div>
        <div class="value" style="color:<?= ($total_in - $total_out) >= 0 ? '#10b981' : '#ef4444' ?>">
            KES <?= number_format($total_in - $total_out, 2) ?>
        </div>
    </div>
</div>

<table>
    <thead>
        <tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th style="text-align:right">Amount (KES)</th></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="type-<?= strtolower($row['type']) ?>"><?= ucfirst(htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8')) ?></td>
            <td><?= htmlspecialchars($row['category'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($row['description'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td style="text-align:right;font-weight:600;"><?= number_format((float)$row['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:2rem;">No transactions found for this period.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
<p style="margin-top:2rem;font-size:.75rem;color:#94a3b8;text-align:center;">Royal Matrix — Confidential Financial Statement</p>
</body></html>
    <?php
    exit();
}

$flash = '';
if (!empty($_SESSION['export_flash'])) {
    $flash = $_SESSION['export_flash'];
    unset($_SESSION['export_flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Data | Royal Matrix</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f4f6f9; color: #1a1a2e; padding: 2rem 1rem; }
        .container { max-width: 700px; margin: 0 auto; }
        h2 { font-size: 1.5rem; margin-bottom: 1.5rem; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h3 { font-size: 1rem; margin-bottom: 1rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: .75rem; }
        label { display: block; font-size: .8rem; font-weight: 600; color: #555; margin-bottom: .25rem; }
        input[type="date"], select { width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: .9rem; }
        .btn { display: inline-block; padding: .65rem 1.25rem; border: none; border-radius: 8px; font-weight: 700; font-size: .875rem; cursor: pointer; text-decoration: none; transition: opacity .15s; }
        .btn:hover { opacity: .85; }
        .btn-csv  { background: #10b981; color: #fff; }
        .btn-pdf  { background: #ef4444; color: #fff; }
        .btn-lock { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
        .btn-row  { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1rem; }
        .alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: .9rem; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .upgrade-banner { background: linear-gradient(135deg,#7c3aed,#06b6d4); color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .upgrade-banner p { font-size: .9rem; opacity: .9; margin-top: .25rem; }
        .upgrade-banner a { background: #fff; color: #7c3aed; padding: .55rem 1.1rem; border-radius: 8px; font-weight: 700; font-size: .875rem; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <h2>Export Data</h2>

    <?php if ($flash): ?>
        <div class="alert"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!$can_export): ?>
    <div class="upgrade-banner" style="margin-bottom:1.5rem;">
        <div>
            <strong>Exports require Pro or Business</strong>
            <p>Upgrade to download CSV and PDF reports of your transactions.</p>
        </div>
        <a href="plans.php">Upgrade Now →</a>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Transaction Export</h3>
        <form method="GET">
            <div class="form-row">
                <div>
                    <label>From Date</label>
                    <input type="date" name="from" value="<?= date('Y-m-01') ?>">
                </div>
                <div>
                    <label>To Date</label>
                    <input type="date" name="to" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div>
                <label>Type</label>
                <select name="type">
                    <option value="">All Types</option>
                    <option value="income">Income only</option>
                    <option value="expense">Expenses only</option>
                </select>
            </div>

            <div class="btn-row">
                <?php if ($can_export): ?>
                    <button type="submit" name="format" value="csv" class="btn btn-csv">⬇ Download CSV</button>
                    <button type="submit" name="format" value="pdf" class="btn btn-pdf">⬇ Download PDF</button>
                <?php else: ?>
                    <span class="btn btn-lock">⬇ CSV (Pro only)</span>
                    <span class="btn btn-lock">⬇ PDF (Pro only)</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
</body>
</html>