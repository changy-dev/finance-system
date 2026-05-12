<?php
// ============================================================
// import_csv.php — Bulk CSV import for bank statements
// ============================================================
session_start();
require_once 'db.php';
require_once 'audit.php';
require_once 'csrf.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
$user_id = (int) $_SESSION['user_id'];

$errors   = [];
$imported = 0;
$skipped  = 0;
$preview  = [];
$step     = 'upload'; // upload | map | confirm | done

// ── Valid categories ──────────────────────────────────────────
$valid_categories = [
    'Food','Transport','Utilities','Entertainment','Shopping','Health',
    'Education','Rent','Salary','Freelance','Investment','Other'
];

// ── STEP 3: Confirm & import ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { die('Invalid CSRF token'); }

    $rows_json = $_POST['rows_json'] ?? '';
    $rows = json_decode($rows_json, true);

    if (!$rows || !is_array($rows)) {
        $errors[] = 'No data to import.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO transactions (user_id, date, type, category, amount, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $conn->begin_transaction();
        try {
            foreach ($rows as $row) {
                $date     = $row['date']        ?? '';
                $type     = $row['type']        ?? '';
                $category = $row['category']    ?? 'Other';
                $amount   = (float)($row['amount'] ?? 0);
                $desc     = substr($row['description'] ?? '', 0, 255);

                // Validation
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }
                if (!in_array($type, ['income','expense']))        { $skipped++; continue; }
                if ($amount <= 0)                                  { $skipped++; continue; }

                if (!in_array($category, $valid_categories)) $category = 'Other';

                $stmt->bind_param("isssds", $user_id, $date, $type, $category, $amount, $desc);
                $stmt->execute();
                $imported++;
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Database error: ' . htmlspecialchars($e->getMessage());
        }

        if ($imported > 0) {
            Audit::log($conn, $user_id, 'import.csv', 'transaction', null, null,
                ['count' => $imported, 'skipped' => $skipped]);
        }
        $step = 'done';
    }
}

// ── STEP 2: Column mapping & preview ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { die('Invalid CSRF token'); }

    // Column mapping
    $col_date   = (int)($_POST['col_date']        ?? 0);
    $col_amount = (int)($_POST['col_amount']       ?? 1);
    $col_type   = (int)($_POST['col_type']         ?? -1);
    $col_desc   = (int)($_POST['col_desc']         ?? -1);
    $col_cat    = (int)($_POST['col_cat']          ?? -1);
    $def_type   = $_POST['default_type']           ?? 'expense';
    $def_cat    = $_POST['default_category']       ?? 'Other';
    $skip_rows  = (int)($_POST['skip_header_rows'] ?? 1);
    $rows_raw   = $_POST['csv_raw']                ?? '';

    $lines = array_filter(explode("\n", $rows_raw), fn($l) => trim($l) !== '');
    $all   = array_map('str_getcsv', $lines);
    $data  = array_slice($all, $skip_rows);

    foreach ($data as $i => $cols) {
        if ($i >= 200) break; // max preview
        $date   = trim($cols[$col_date]    ?? '');
        $amount = trim($cols[$col_amount]  ?? '');
        $type   = $col_type >= 0 ? strtolower(trim($cols[$col_type] ?? '')) : $def_type;
        $desc   = $col_desc >= 0 ? trim($cols[$col_desc]  ?? '') : '';
        $cat    = $col_cat  >= 0 ? trim($cols[$col_cat]   ?? '') : $def_cat;

        // Normalise date
        foreach (['Y-m-d','d/m/Y','m/d/Y','d-m-Y','Y/m/d'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $date);
            if ($dt) { $date = $dt->format('Y-m-d'); break; }
        }
        // Normalise amount
        $amount = (float) preg_replace('/[^0-9.\-]/', '', $amount);
        // Normalise type
        if (in_array($type, ['cr','credit','in','deposit'])) $type = 'income';
        if (in_array($type, ['dr','debit','out','withdrawal','purchase'])) $type = 'expense';
        if (!in_array($type, ['income','expense'])) $type = $def_type;

        $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $amount > 0;

        $preview[] = [
            'date' => $date, 'type' => $type, 'category' => $cat,
            'amount' => $amount, 'description' => $desc, 'valid' => $ok
        ];
    }
    $step = 'confirm';
}

// ── STEP 1: File upload ───────────────────────────────────────
$csv_raw    = '';
$csv_header = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { die('Invalid CSRF token'); }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please select a CSV file to upload.';
    } else {
        $mime = mime_content_type($_FILES['csv_file']['tmp_name']);
        if (!in_array($mime, ['text/csv','text/plain','application/csv','application/vnd.ms-excel'])) {
            $errors[] = 'Only CSV files are accepted.';
        } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File too large. Maximum size is 5 MB.';
        } else {
            $csv_raw = file_get_contents($_FILES['csv_file']['tmp_name']);
            $lines = explode("\n", $csv_raw);
            $csv_header = str_getcsv($lines[0] ?? '');
            $step = 'map';
        }
    }
}

$csrf = csrf_generate();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import CSV | Royal Matrix</title>
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
h1{font-size:22px;font-weight:700;margin-bottom:6px}
.sub{color:var(--muted);font-size:14px;margin-bottom:28px}
.steps{display:flex;gap:0;margin-bottom:32px;background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.step{flex:1;padding:14px;text-align:center;font-size:12px;font-weight:700;color:var(--muted);border-right:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em}
.step:last-child{border-right:none}
.step.active{background:linear-gradient(135deg,#7c3aed22,#06b6d422);color:var(--purple)}
.step.done{color:var(--green)}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:28px;margin-bottom:20px}
.card h2{font-size:16px;font-weight:700;margin-bottom:18px}
label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px}
input[type=file],input[type=text]{width:100%;padding:12px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px;outline:none;transition:.2s}
input:focus,select:focus{border-color:var(--purple)}
select{width:100%;padding:11px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;color:var(--text);font-size:14px;outline:none}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;transition:.2s}
.btn-primary{background:var(--purple);color:#fff}.btn-primary:hover{background:#6d28d9}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}.btn-outline:hover{background:var(--bg3)}
.btn-green{background:var(--green);color:#fff}.btn-green:hover{background:#16a34a}
.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;color:#fca5a5;font-size:13px;margin-bottom:16px}
.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:16px 20px;color:#86efac;font-size:14px;margin-bottom:16px}
.drop-zone{border:2px dashed var(--border);border-radius:14px;padding:48px;text-align:center;cursor:pointer;transition:.25s;margin-bottom:20px}
.drop-zone:hover,.drop-zone.drag{border-color:var(--purple);background:rgba(124,58,237,.05)}
.drop-zone .icon{font-size:42px;margin-bottom:12px}
.drop-zone p{color:var(--muted);font-size:14px}
.drop-zone strong{color:var(--purple)}
.format-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.chip{padding:5px 12px;background:var(--bg3);border:1px solid var(--border);border-radius:20px;font-size:12px;color:var(--muted)}
.preview-table{overflow-x:auto;max-height:400px;overflow-y:auto}
.preview-table table{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap}
.preview-table th{position:sticky;top:0;background:var(--bg);padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);text-align:left}
.preview-table td{padding:10px 14px;border-bottom:1px solid var(--border)}
.preview-table .valid td{}.preview-table .invalid td{color:var(--red);opacity:.7}
.badge-income{background:rgba(34,197,94,.15);color:var(--green);padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.badge-expense{background:rgba(239,68,68,.15);color:var(--red);padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.summary-row{display:flex;gap:20px;margin-bottom:20px;flex-wrap:wrap}
.summary-stat{background:var(--bg3);border-radius:10px;padding:14px 20px}
.summary-stat .n{font-size:22px;font-weight:800}.summary-stat .l{font-size:11px;color:var(--muted);text-transform:uppercase}
@media(max-width:900px){.main{margin-left:0}.sidebar{display:none}.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="sidebar">
    <div class="logo">💎 Royal Matrix</div>
    <a href="dashboard.php">📊 Dashboard</a>
    <a href="transactions.php">💸 Transactions</a>
    <a href="budget.php">🎯 Budget</a>
    <a href="import_csv.php" class="active">📥 Import CSV</a>
    <a href="reports.php">📈 Reports</a>
    <a href="activity_log.php">🕵️ Activity Log</a>
    <a href="settings.php">⚙️ Settings</a>
    <a href="logout.php" style="margin-top:auto;color:var(--red)">🚪 Logout</a>
</div>

<div class="main">
    <h1>📥 Import Bank Statement</h1>
    <p class="sub">Upload a CSV file from your bank to bulk-import transactions.</p>

    <!-- Step indicator -->
    <div class="steps">
        <div class="step <?= $step==='upload'?'active':($step!=='upload'?'done':'') ?>">① Upload</div>
        <div class="step <?= $step==='map'?'active':($step==='confirm'||$step==='done'?'done':'') ?>">② Map Columns</div>
        <div class="step <?= $step==='confirm'?'active':($step==='done'?'done':'') ?>">③ Preview</div>
        <div class="step <?= $step==='done'?'done':'' ?>">④ Done</div>
    </div>

    <?php foreach ($errors as $e): ?>
        <div class="error">⚠️ <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <!-- STEP: DONE -->
    <?php if ($step === 'done' && empty($errors)): ?>
    <div class="success">
        <div style="font-size:32px;margin-bottom:10px">✅</div>
        <strong>Import Complete!</strong><br>
        <b><?= $imported ?></b> transaction(s) imported successfully.
        <?php if ($skipped > 0): ?> · <b><?= $skipped ?></b> row(s) skipped (invalid data).<?php endif; ?>
    </div>
    <div style="display:flex;gap:12px">
        <a href="transactions.php" class="btn btn-primary">📋 View Transactions</a>
        <a href="import_csv.php"   class="btn btn-outline">↩ Import Another File</a>
    </div>

    <!-- STEP: CONFIRM -->
    <?php elseif ($step === 'confirm'): ?>
    <div class="card">
        <h2>③ Preview & Confirm Import</h2>
        <?php
            $valid_count   = count(array_filter($preview, fn($r) => $r['valid']));
            $invalid_count = count($preview) - $valid_count;
        ?>
        <div class="summary-row">
            <div class="summary-stat"><div class="n" style="color:var(--green)"><?= $valid_count ?></div><div class="l">Ready to Import</div></div>
            <div class="summary-stat"><div class="n" style="color:var(--red)"><?= $invalid_count ?></div><div class="l">Will Be Skipped</div></div>
            <div class="summary-stat"><div class="n"><?= count($preview) ?></div><div class="l">Total Rows</div></div>
        </div>
        <?php if ($invalid_count > 0): ?>
        <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:10px;padding:12px 16px;font-size:13px;color:var(--gold);margin-bottom:16px">
            ⚠️ Rows in red will be skipped. Common issues: invalid date format, zero amount, or unknown type.
        </div>
        <?php endif; ?>
        <div class="preview-table">
            <table>
                <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Category</th><th>Amount</th><th>Description</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $i => $row): ?>
                <tr class="<?= $row['valid'] ? 'valid' : 'invalid' ?>">
                    <td style="color:var(--muted)"><?= $i+1 ?></td>
                    <td><?= htmlspecialchars($row['date']) ?></td>
                    <td><span class="badge-<?= $row['type'] ?>"><?= ucfirst($row['type']) ?></span></td>
                    <td><?= htmlspecialchars($row['category']) ?></td>
                    <td>KES <?= number_format($row['amount'], 2) ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($row['description']) ?></td>
                    <td><?= $row['valid'] ? '✅' : '❌' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($valid_count > 0): ?>
        <form method="POST" style="margin-top:20px;display:flex;gap:12px;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="rows_json" value="<?= htmlspecialchars(json_encode(array_values(array_filter($preview, fn($r) => $r['valid'])))) ?>">
            <button type="submit" class="btn btn-green">✅ Import <?= $valid_count ?> Transaction(s)</button>
            <a href="import_csv.php" class="btn btn-outline">↩ Start Over</a>
        </form>
        <?php else: ?>
        <div class="error" style="margin-top:16px">No valid rows found. Please check your column mapping.</div>
        <a href="import_csv.php" class="btn btn-outline" style="margin-top:12px">↩ Start Over</a>
        <?php endif; ?>
    </div>

    <!-- STEP: MAP -->
    <?php elseif ($step === 'map'): ?>
    <div class="card">
        <h2>② Map Your CSV Columns</h2>
        <p style="color:var(--muted);font-size:13px;margin-bottom:20px">Tell us which column in your CSV corresponds to each field.</p>

        <?php
            $sample_lines = array_slice(explode("\n", $csv_raw), 0, 3);
            $sample_cols  = str_getcsv($sample_lines[0] ?? '');
            $col_options  = '';
            foreach ($sample_cols as $i => $col) {
                $col_options .= "<option value='{$i}'>" . htmlspecialchars($col ?: "Column {$i}") . " (col {$i})</option>";
            }
        ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="csv_raw" value="<?= htmlspecialchars($csv_raw) ?>">

            <div class="form-grid">
                <div>
                    <label>Date Column *</label>
                    <select name="col_date"><?= $col_options ?></select>
                </div>
                <div>
                    <label>Amount Column *</label>
                    <select name="col_amount">
                    <?php foreach ($sample_cols as $i => $col): ?>
                        <option value="<?= $i ?>" <?= $i===1?'selected':'' ?>><?= htmlspecialchars($col ?: "Column {$i}") ?> (col <?= $i ?>)</option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Type Column (leave blank to use default)</label>
                    <select name="col_type">
                        <option value="-1">— None, use default below —</option>
                        <?= $col_options ?>
                    </select>
                </div>
                <div>
                    <label>Default Transaction Type</label>
                    <select name="default_type">
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                </div>
                <div>
                    <label>Description Column</label>
                    <select name="col_desc">
                        <option value="-1">— None —</option>
                        <?= $col_options ?>
                    </select>
                </div>
                <div>
                    <label>Category Column</label>
                    <select name="col_cat">
                        <option value="-1">— None, use default —</option>
                        <?= $col_options ?>
                    </select>
                </div>
                <div>
                    <label>Default Category</label>
                    <select name="default_category">
                        <?php foreach ($valid_categories as $vc): ?>
                            <option value="<?= $vc ?>" <?= $vc==='Other'?'selected':'' ?>><?= $vc ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Header Rows to Skip</label>
                    <select name="skip_header_rows">
                        <option value="0">0 (no header)</option>
                        <option value="1" selected>1 (skip first row)</option>
                        <option value="2">2</option>
                    </select>
                </div>
            </div>

            <?php if (!empty($csv_header)): ?>
            <div style="margin-bottom:20px">
                <div style="font-size:12px;color:var(--muted);margin-bottom:8px;text-transform:uppercase;font-weight:700">Detected Columns</div>
                <div class="format-chips">
                    <?php foreach ($csv_header as $i => $h): ?>
                        <span class="chip"><?= $i ?>: <?= htmlspecialchars($h ?: "(empty)") ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">Preview Import →</button>
        </form>
    </div>

    <!-- STEP: UPLOAD -->
    <?php else: ?>
    <div class="card">
        <h2>① Upload Your CSV File</h2>
        <p style="color:var(--muted);font-size:13px;margin-bottom:20px">
            Export a CSV from your bank's online portal (M-Pesa, Equity, KCB, etc.) and upload it here.
            Max file size: 5 MB.
        </p>
        <div class="format-chips" style="margin-bottom:20px">
            <span class="chip">✅ .csv files</span>
            <span class="chip">✅ M-Pesa statements</span>
            <span class="chip">✅ Bank exports</span>
            <span class="chip">✅ Custom spreadsheets</span>
        </div>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="upload">

            <div class="drop-zone" id="dropZone" onclick="document.getElementById('csv_file').click()">
                <div class="icon">📄</div>
                <p><strong>Click to browse</strong> or drag &amp; drop your CSV here</p>
                <p id="fileName" style="margin-top:8px;color:var(--purple);font-weight:600"></p>
            </div>
            <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" style="display:none" required>
            <button type="submit" class="btn btn-primary" style="margin-top:4px">Upload &amp; Continue →</button>
        </form>

        <div style="margin-top:28px;padding-top:20px;border-top:1px solid var(--border)">
            <div style="font-size:13px;font-weight:700;color:var(--muted);margin-bottom:12px">📋 Expected CSV Format (any order)</div>
            <div class="preview-table" style="max-height:160px">
                <table>
                    <thead><tr><th>date</th><th>amount</th><th>type</th><th>description</th><th>category</th></tr></thead>
                    <tbody>
                        <tr><td>2026-05-01</td><td>5000</td><td>income</td><td>Salary payment</td><td>Salary</td></tr>
                        <tr><td>2026-05-03</td><td>1200</td><td>expense</td><td>Naivas shopping</td><td>Food</td></tr>
                        <tr><td>01/05/2026</td><td>450</td><td>expense</td><td>Uber ride</td><td>Transport</td></tr>
                    </tbody>
                </table>
            </div>
            <p style="font-size:12px;color:var(--muted);margin-top:10px">Date formats supported: YYYY-MM-DD, DD/MM/YYYY, MM/DD/YYYY. Type values: income/expense/credit/debit/cr/dr.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const dz = document.getElementById('dropZone');
const fi = document.getElementById('csv_file');
if (dz && fi) {
    fi.addEventListener('change', e => {
        const f = e.target.files[0];
        if (f) document.getElementById('fileName').textContent = '📎 ' + f.name;
    });
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('drag');
        const f = e.dataTransfer.files[0];
        if (f) {
            const dt = new DataTransfer(); dt.items.add(f);
            fi.files = dt.files;
            document.getElementById('fileName').textContent = '📎 ' + f.name;
        }
    });
}
</script>
</body>
</html>
