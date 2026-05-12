<?php
ob_start();

session_start();

require_once 'db.php';
require_once 'security.php';
require_once 'audit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$can_export = true;

$format      = $_GET['format'] ?? '';
$date_from   = $_GET['from'] ?? date('Y-m-01');
$date_to     = $_GET['to'] ?? date('Y-m-d');
$type_filter = $_GET['type'] ?? '';

if (in_array($format, ['csv', 'pdf']) && !$can_export) {

    $_SESSION['export_flash'] =
        'Exports are available on Pro and Business plans.';

    header("Location: plans.php");
    exit();
}

/* =====================================================
   CSV EXPORT
===================================================== */

if ($format === 'csv') {

    $where = "
        WHERE user_id=?
        AND is_deleted_by_user=0
        AND DATE(created_at) BETWEEN ? AND ?
    ";

    $params = [$user_id, $date_from, $date_to];
    $types  = "iss";

    if ($type_filter !== '') {

        $where .= " AND type=?";
        $params[] = $type_filter;
        $types .= "s";
    }

    $stmt = $conn->prepare("
        SELECT
            DATE(created_at) as date,
            type,
            category,
            amount,
            description,
            created_at
        FROM transactions
        $where
        ORDER BY created_at DESC
    ");

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    Audit::log(
        $conn,
        $user_id,
        'export.csv',
        'transactions',
        null,
        null,
        [
            'from'  => $date_from,
            'to'    => $date_to,
            'count' => count($rows)
        ]
    );

    $filename =
        'transactions_' .
        $date_from .
        '_to_' .
        $date_to .
        '.csv';

    ob_clean();

    header('Content-Type: text/csv');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'Date',
        'Type',
        'Category',
        'Amount (KES)',
        'Description',
        'Created At'
    ]);

    foreach ($rows as $row) {

        fputcsv($out, [
            $row['date'],
            ucfirst($row['type']),
            $row['category'],
            number_format((float)$row['amount'], 2),
            $row['description'] ?? '',
            $row['created_at']
        ]);
    }

    fclose($out);
    exit();
}

/* =====================================================
   PDF VIEW
===================================================== */

if ($format === 'pdf') {

    $stmt = $conn->prepare("
        SELECT
            DATE(created_at) as date,
            type,
            category,
            amount,
            description
        FROM transactions
        WHERE user_id=?
        AND is_deleted_by_user=0
        AND DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC
    ");

    $stmt->bind_param(
        "iss",
        $user_id,
        $date_from,
        $date_to
    );

    $stmt->execute();

    $rows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $total_in = array_sum(
        array_column(
            array_filter(
                $rows,
                fn($r) => $r['type'] === 'income'
            ),
            'amount'
        )
    );

    $total_out = array_sum(
        array_column(
            array_filter(
                $rows,
                fn($r) => $r['type'] === 'expense'
            ),
            'amount'
        )
    );

    $u = $conn->prepare("
        SELECT full_name
        FROM users
        WHERE user_id=?
    ");

    $u->bind_param("i", $user_id);
    $u->execute();

    $urow = $u
        ->get_result()
        ->fetch_assoc();

    Audit::log(
        $conn,
        $user_id,
        'export.pdf',
        'transactions',
        null,
        null,
        [
            'from'  => $date_from,
            'to'    => $date_to,
            'count' => count($rows)
        ]
    );

    ob_clean();

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Statement <?= $date_from ?> to <?= $date_to ?>
</title>

<style>

@media print {

    body {
        background: white;
        -webkit-print-color-adjust: exact;
    }

    .no-print {
        display: none !important;
    }
}

* {
    box-sizing: border-box;
}

body {

    font-family: system-ui, sans-serif;

    background: #f8fafc;

    color: #1e293b;

    max-width: 950px;

    margin: 0 auto;

    padding: 2rem;
}

/* ACTIONS */

.top-actions {

    display: flex;

    gap: 1rem;

    flex-wrap: wrap;

    margin-bottom: 1.5rem;
}

.btn-nav,
.btn-print {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: .9rem 1.4rem;

    border-radius: 14px;

    font-weight: 700;

    text-decoration: none;

    cursor: pointer;

    transition: .25s ease;
}

.btn-nav {

    background: white;

    color: #1e293b;

    border: 1px solid #dbeafe;

    box-shadow:
        0 8px 20px rgba(0,0,0,.05);
}

.btn-nav:hover {

    transform: translateY(-2px);

    border-color: #2563eb;
}

.btn-print {

    border: none;

    color: white;

    background: linear-gradient(
        135deg,
        #2563eb,
        #06b6d4
    );

    box-shadow:
        0 10px 30px rgba(37,99,235,.25);
}

.btn-print:hover {

    transform: translateY(-2px);
}

/* HEADER */

.header {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    border-bottom: 3px solid #2563eb;

    padding-bottom: 1rem;

    margin-bottom: 2rem;
}

.brand {

    font-size: 1.6rem;

    font-weight: 800;

    color: #2563eb;
}

.meta {

    text-align: right;

    font-size: .85rem;

    color: #64748b;
}

/* SUMMARY */

.summary {

    display: grid;

    grid-template-columns: repeat(3, 1fr);

    gap: 1rem;

    margin-bottom: 2rem;
}

.stat {

    background: white;

    border-radius: 20px;

    padding: 1.4rem;

    box-shadow:
        0 10px 30px rgba(0,0,0,.05);
}

.label {

    font-size: .75rem;

    color: #64748b;

    text-transform: uppercase;
}

.value {

    margin-top: .5rem;

    font-size: 1.35rem;

    font-weight: 800;
}

.income {
    color: #10b981;
}

.expense {
    color: #ef4444;
}

/* TABLE */

table {

    width: 100%;

    border-collapse: collapse;

    background: white;

    border-radius: 18px;

    overflow: hidden;

    box-shadow:
        0 10px 30px rgba(0,0,0,.05);
}

th {

    background: #eff6ff;

    padding: .9rem;

    text-align: left;

    font-size: .75rem;

    text-transform: uppercase;

    color: #475569;
}

td {

    padding: .9rem;

    border-bottom: 1px solid #f1f5f9;
}

.type-income {

    color: #10b981;

    font-weight: 700;
}

.type-expense {

    color: #ef4444;

    font-weight: 700;
}

/* FOOTER */

.footer {

    margin-top: 2rem;

    text-align: center;

    font-size: .75rem;

    color: #94a3b8;
}

/* MOBILE */

@media (max-width: 700px) {

    body {
        padding: 1rem;
    }

    .summary {
        grid-template-columns: 1fr;
    }

    .header {
        flex-direction: column;
        gap: 1rem;
    }

    .meta {
        text-align: left;
    }

    .top-actions {
        flex-direction: column;
    }

    .btn-nav,
    .btn-print {
        width: 100%;
    }
}

</style>
</head>

<body>

<div class="top-actions no-print">

    <a href="export.php" class="btn-nav">
        ← Back to Export
    </a>

    <a href="dashboard.php" class="btn-nav">
        🏠 Dashboard
    </a>

    <button
        class="btn-print"
        onclick="window.print()"
    >
        🖨️ Print or Save PDF
    </button>

</div>

<div class="header">

    <div>

        <div class="brand">
            Royal Matrix
        </div>

        <div
            style="
                font-size:.85rem;
                color:#64748b;
                margin-top:.25rem;
            "
        >
            Transaction Statement
        </div>

    </div>

    <div class="meta">

        <div>
            <?= htmlspecialchars(
                $urow['full_name'] ?? '',
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

        <div>
            Period:
            <?= $date_from ?>
            to
            <?= $date_to ?>
        </div>

        <div>
            Generated:
            <?= date('d M Y H:i') ?>
        </div>

    </div>

</div>

<div class="summary">

    <div class="stat">

        <div class="label">
            Total Income
        </div>

        <div class="value income">
            KES <?= number_format($total_in, 2) ?>
        </div>

    </div>

    <div class="stat">

        <div class="label">
            Total Expenses
        </div>

        <div class="value expense">
            KES <?= number_format($total_out, 2) ?>
        </div>

    </div>

    <div class="stat">

        <div class="label">
            Net Balance
        </div>

        <div
            class="value"
            style="
                color:
                <?= ($total_in - $total_out) >= 0
                    ? '#10b981'
                    : '#ef4444'
                ?>
            "
        >
            KES
            <?= number_format(
                $total_in - $total_out,
                2
            ) ?>
        </div>

    </div>

</div>

<table>

<thead>

<tr>

    <th>Date</th>
    <th>Type</th>
    <th>Category</th>
    <th>Description</th>

    <th style="text-align:right;">
        Amount (KES)
    </th>

</tr>

</thead>

<tbody>

<?php foreach ($rows as $row): ?>

<tr>

    <td>
        <?= htmlspecialchars(
            $row['date'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </td>

    <td class="type-<?= strtolower($row['type']) ?>">

        <?= ucfirst(
            htmlspecialchars(
                $row['type'],
                ENT_QUOTES,
                'UTF-8'
            )
        ) ?>

    </td>

    <td>

        <?= htmlspecialchars(
            $row['category'] ?? '—',
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </td>

    <td>

        <?= htmlspecialchars(
            $row['description'] ?? '—',
            ENT_QUOTES,
            'UTF-8'
        ) ?>

    </td>

    <td style="text-align:right;font-weight:700;">

        KES
        <?= number_format(
            (float)$row['amount'],
            2
        ) ?>

    </td>

</tr>

<?php endforeach; ?>

<?php if (empty($rows)): ?>

<tr>

<td
    colspan="5"
    style="
        text-align:center;
        color:#94a3b8;
        padding:2rem;
    "
>
    No transactions found.
</td>

</tr>

<?php endif; ?>

</tbody>

</table>

<div class="footer">
    Royal Matrix — Confidential Financial Statement
</div>

</body>
</html>
<?php
exit();
}

/* =====================================================
   MAIN EXPORT PAGE
===================================================== */

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

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Export Data | Royal Matrix
</title>

<style>

*,
*::before,
*::after {

    box-sizing: border-box;

    margin: 0;

    padding: 0;
}

body {

    font-family: system-ui, sans-serif;

    background:
        radial-gradient(circle at top left,
        #dbeafe 0%, transparent 30%),

        radial-gradient(circle at bottom right,
        #cffafe 0%, transparent 30%),

        #f8fafc;

    min-height: 100vh;

    color: #1e293b;

    padding: 2rem 1rem;
}

.container {

    max-width: 760px;

    margin: 0 auto;
}

.page-header {

    margin-bottom: 28px;
}

.page-header h2 {

    font-size: 2rem;

    font-weight: 800;

    color: #0f172a;
}

.page-header p {

    color: #64748b;

    margin-top: 6px;
}

.card {

    background: rgba(255,255,255,.78);

    backdrop-filter: blur(18px);

    border: 1px solid rgba(255,255,255,.6);

    border-radius: 28px;

    padding: 2rem;

    box-shadow:
        0 10px 40px rgba(0,0,0,.06);
}

.form-row {

    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 1rem;

    margin-bottom: 1rem;
}

label {

    display: block;

    font-size: .82rem;

    font-weight: 700;

    color: #475569;

    margin-bottom: .45rem;
}

input[type="date"],
select {

    width: 100%;

    padding: 14px 16px;

    border-radius: 16px;

    border: 1px solid #dbeafe;

    background: rgba(255,255,255,.9);
}

.btn-row {

    display: flex;

    gap: 1rem;

    margin-top: 1.5rem;

    flex-wrap: wrap;
}

.btn {

    flex: 1;

    border: none;

    border-radius: 18px;

    padding: 15px 24px;

    font-weight: 700;

    cursor: pointer;

    color: white;

    transition: .3s ease;
}

.btn:hover {

    transform: translateY(-3px);
}

.btn-csv {

    background: linear-gradient(
        135deg,
        #10b981,
        #059669
    );
}

.btn-pdf {

    background: linear-gradient(
        135deg,
        #f43f5e,
        #e11d48
    );
}

.bottom-actions {

    margin-top: 28px;

    display: flex;

    justify-content: center;
}

.back-btn {

    text-decoration: none;

    color: white;

    font-weight: 700;

    padding: 14px 24px;

    border-radius: 18px;

    background: linear-gradient(
        135deg,
        #2563eb,
        #06b6d4
    );
}

@media (max-width: 700px) {

    .form-row {
        grid-template-columns: 1fr;
    }

    .btn-row {
        flex-direction: column;
    }
}

</style>
</head>

<body>

<div class="container">

<div class="page-header">

    <h2>
        Export Financial Data
    </h2>

    <p>
        Download your transaction history
        in CSV or PDF format.
    </p>

</div>

<?php if ($flash): ?>

<div
    style="
        background:#fee2e2;
        color:#991b1b;
        padding:1rem;
        border-radius:16px;
        margin-bottom:1rem;
        font-weight:600;
    "
>
    <?= htmlspecialchars(
        $flash,
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</div>

<?php endif; ?>

<div class="card">

<form method="GET">

<div class="form-row">

<div>

<label>
    From Date
</label>

<input
    type="date"
    name="from"
    value="<?= date('Y-m-01') ?>"
>

</div>

<div>

<label>
    To Date
</label>

<input
    type="date"
    name="to"
    value="<?= date('Y-m-d') ?>"
>

</div>

</div>

<div>

<label>
    Type
</label>

<select name="type">

<option value="">
    All Types
</option>

<option value="income">
    Income only
</option>

<option value="expense">
    Expenses only
</option>

</select>

</div>

<div class="btn-row">

<button
    type="submit"
    name="format"
    value="csv"
    class="btn btn-csv"
>
    ⬇ Download CSV
</button>

<button
    type="submit"
    name="format"
    value="pdf"
    class="btn btn-pdf"
>
    ⬇ Download PDF
</button>

</div>

</form>

</div>

<div class="bottom-actions">

<a href="dashboard.php" class="back-btn">
    ← Back to Dashboard
</a>

</div>

</div>

</body>
</html>