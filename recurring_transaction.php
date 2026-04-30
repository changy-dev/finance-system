<?php
session_start();
require_once 'db.php';
require_once 'csrf.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/* =========================
   CONSTANTS / WHITELISTS
========================= */
const ALLOWED_TYPES       = ['expense', 'income'];
const ALLOWED_FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];
const MAX_AMOUNT          = 999999999.99;
const ITEMS_PER_PAGE      = 20;

/* =========================
   HELPERS
========================= */

/**
 * Validate and normalise a date string.
 * Returns a Y-m-d string or null if invalid.
 */
function parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;

    $d = DateTime::createFromFormat('Y-m-d', $raw);
    if ($d && $d->format('Y-m-d') === $raw) {
        return $raw;
    }
    return null;
}

/**
 * Sanitise a plain-text field for DB storage (no HTML encoding — that happens on output).
 */
function clean_text(string $value, int $maxLen = 255): string
{
    return mb_substr(trim($value), 0, $maxLen);
}

/**
 * Render an inline error banner and a back link.
 */
function show_error(string $msg): void
{
    echo '<div class="alert alert-error">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
}

/* =========================
   FLASH MESSAGES
========================= */
$flash = '';
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* =========================
   HANDLE POST ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); // exits / throws on failure

    $action = $_POST['action'] ?? '';

    /* ── ADD ── */
    if ($action === 'add') {
        $errors = [];

        $name        = clean_text($_POST['name'] ?? '');
        $type        = $_POST['type'] ?? '';
        $category    = clean_text($_POST['category'] ?? '');
        $amount_raw  = $_POST['amount'] ?? '';
        $frequency   = $_POST['frequency'] ?? '';
        $next_due    = parse_date($_POST['next_due'] ?? '');
        $description = clean_text($_POST['description'] ?? '', 1000);

        // Validate name
        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        // Validate type whitelist
        if (!in_array($type, ALLOWED_TYPES, true)) {
            $errors[] = 'Invalid transaction type.';
        }

        // Validate category
        if ($category === '') {
            $errors[] = 'Category is required.';
        }

        // Validate amount
        if (!is_numeric($amount_raw)) {
            $errors[] = 'Amount must be a number.';
        } else {
            $amount = (float) $amount_raw;
            if ($amount <= 0) {
                $errors[] = 'Amount must be greater than zero.';
            } elseif ($amount > MAX_AMOUNT) {
                $errors[] = 'Amount exceeds the maximum allowed value.';
            }
        }

        // Validate frequency whitelist
        if (!in_array($frequency, ALLOWED_FREQUENCIES, true)) {
            $errors[] = 'Invalid frequency.';
        }

        // Validate next_due (optional but must be a real date if provided)
        if (!empty($_POST['next_due']) && $next_due === null) {
            $errors[] = 'Next due date is not a valid date (expected YYYY-MM-DD).';
        }

        if (!empty($errors)) {
            $_SESSION['flash'] = implode(' ', $errors);
            header("Location: ");
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO recurring_transactions
                (user_id, name, type, category, amount, frequency, next_due, description, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param(
            "isssdsss",
            $user_id,
            $name,
            $type,
            $category,
            $amount,
            $frequency,
            $next_due,
            $description
        );

        if ($stmt->execute()) {
            $_SESSION['flash'] = 'Recurring transaction added successfully.';
        } else {
            $_SESSION['flash'] = 'Database error — could not add transaction.';
        }

        header("Location: ");
        exit;
    }

    /* ── EDIT ── */
    if ($action === 'edit') {
        $errors = [];

        $recurring_id = (int) ($_POST['recurring_id'] ?? 0);
        $name         = clean_text($_POST['name'] ?? '');
        $type         = $_POST['type'] ?? '';
        $category     = clean_text($_POST['category'] ?? '');
        $amount_raw   = $_POST['amount'] ?? '';
        $frequency    = $_POST['frequency'] ?? '';
        $next_due     = parse_date($_POST['next_due'] ?? '');
        $description  = clean_text($_POST['description'] ?? '', 1000);

        if ($recurring_id <= 0)          $errors[] = 'Invalid transaction ID.';
        if ($name === '')                 $errors[] = 'Name is required.';
        if (!in_array($type, ALLOWED_TYPES, true))        $errors[] = 'Invalid type.';
        if ($category === '')             $errors[] = 'Category is required.';
        if (!is_numeric($amount_raw))    $errors[] = 'Amount must be a number.';
        else {
            $amount = (float) $amount_raw;
            if ($amount <= 0)             $errors[] = 'Amount must be greater than zero.';
            if ($amount > MAX_AMOUNT)     $errors[] = 'Amount exceeds the maximum allowed value.';
        }
        if (!in_array($frequency, ALLOWED_FREQUENCIES, true)) $errors[] = 'Invalid frequency.';
        if (!empty($_POST['next_due']) && $next_due === null)  $errors[] = 'Invalid next due date.';

        if (!empty($errors)) {
            $_SESSION['flash'] = implode(' ', $errors);
            header("Location: ");
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE recurring_transactions
            SET name = ?, type = ?, category = ?, amount = ?,
                frequency = ?, next_due = ?, description = ?
            WHERE recurring_id = ? AND user_id = ?
        ");
        $stmt->bind_param(
            "sssdsssii",
            $name,
            $type,
            $category,
            $amount,
            $frequency,
            $next_due,
            $description,
            $recurring_id,
            $user_id
        );

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['flash'] = 'Transaction updated successfully.';
        } else {
            $_SESSION['flash'] = 'Could not update — record not found or no changes made.';
        }

        header("Location: ");
        exit;
    }

    /* ── DELETE ── */
    if ($action === 'delete') {
        $recurring_id  = (int) ($_POST['recurring_id'] ?? 0);
        $confirm_token = trim($_POST['confirm_token'] ?? '');

        // Server-side deletion confirmation: require the token that was embedded in the form
        $expected_token = hash_hmac('sha256', (string) $recurring_id, session_id());

        if ($recurring_id <= 0 || !hash_equals($expected_token, $confirm_token)) {
            $_SESSION['flash'] = 'Invalid or missing deletion confirmation.';
            header("Location: ");
            exit;
        }

        $stmt = $conn->prepare("
            DELETE FROM recurring_transactions
            WHERE recurring_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $recurring_id, $user_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['flash'] = 'Transaction deleted.';
        } else {
            $_SESSION['flash'] = 'Could not delete — record not found.';
        }

        header("Location: ");
        exit;
    }

    /* ── TOGGLE ACTIVE ── */
    if ($action === 'toggle') {
        $recurring_id = (int) ($_POST['recurring_id'] ?? 0);

        if ($recurring_id <= 0) {
            $_SESSION['flash'] = 'Invalid transaction ID.';
            header("Location: ");
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE recurring_transactions
            SET is_active = IF(is_active = 1, 0, 1)
            WHERE recurring_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $recurring_id, $user_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['flash'] = 'Status updated.';
        } else {
            $_SESSION['flash'] = 'Could not update status — record not found.';
        }

        header("Location: ");
        exit;
    }
}

/* =========================
   FETCH DATA (paginated)
========================= */
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * ITEMS_PER_PAGE;

// Total count for pagination controls
$count_stmt  = $conn->prepare("SELECT COUNT(*) FROM recurring_transactions WHERE user_id = ?");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$total_rows  = (int) $count_stmt->get_result()->fetch_row()[0];
$total_pages = (int) ceil($total_rows / ITEMS_PER_PAGE);

$stmt = $conn->prepare("
    SELECT *
    FROM recurring_transactions
    WHERE user_id = ?
    ORDER BY next_due ASC
    LIMIT ? OFFSET ?
");
$limit = ITEMS_PER_PAGE;
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================
   FETCH A SINGLE ROW FOR EDIT MODAL (if ?edit=ID)
========================= */
$edit_row = null;
if (!empty($_GET['edit'])) {
    $edit_id  = (int) $_GET['edit'];
    $edit_stmt = $conn->prepare("
        SELECT * FROM recurring_transactions
        WHERE recurring_id = ? AND user_id = ?
    ");
    $edit_stmt->bind_param("ii", $edit_id, $user_id);
    $edit_stmt->execute();
    $edit_row = $edit_stmt->get_result()->fetch_assoc();
}

/* helper: generate server-side delete token */
function delete_token(int $id): string
{
    return hash_hmac('sha256', (string) $id, session_id());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Transactions</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, sans-serif;
            background: #f4f6f9;
            color: #1a1a2e;
            padding: 2rem 1rem;
        }

        h2 { font-size: 1.5rem; margin-bottom: 1.25rem; }

        .container { max-width: 1100px; margin: 0 auto; }

        /* Flash */
        .alert {
            padding: .75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            font-size: .925rem;
        }
        .alert-error   { background: #fde8e8; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }

        /* Card / form wrapper */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            padding: 1.5rem;
            margin-bottom: 1.75rem;
        }
        .card h3 { font-size: 1.1rem; margin-bottom: 1rem; }

        /* Form grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: .75rem;
        }
        .form-grid .full { grid-column: 1 / -1; }

        label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .25rem; color: #555; }

        input[type="text"],
        input[type="number"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            padding: .5rem .75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: .9rem;
            background: #fafafa;
            transition: border-color .15s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #6366f1;
            background: #fff;
        }
        textarea { resize: vertical; min-height: 70px; }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: .45rem .9rem;
            border: none;
            border-radius: 6px;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s;
            text-decoration: none;
        }
        .btn:hover { opacity: .85; }
        .btn-primary  { background: #6366f1; color: #fff; }
        .btn-warning  { background: #f59e0b; color: #fff; }
        .btn-success  { background: #10b981; color: #fff; }
        .btn-danger   { background: #ef4444; color: #fff; }
        .btn-ghost    { background: #e5e7eb; color: #374151; }
        .btn-sm       { padding: .3rem .65rem; font-size: .8rem; }
        .mt-1         { margin-top: .6rem; }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { padding: .75rem 1rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-size: .8rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f9fafb; }

        /* Badges */
        .badge {
            display: inline-block;
            padding: .2rem .55rem;
            border-radius: 99px;
            font-size: .75rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .badge-income   { background: #d1fae5; color: #065f46; }
        .badge-expense  { background: #fee2e2; color: #991b1b; }
        .badge-active   { background: #dbeafe; color: #1e40af; }
        .badge-inactive { background: #e5e7eb; color: #6b7280; }

        /* Actions cell */
        .actions { display: flex; gap: .4rem; flex-wrap: wrap; }

        /* Modal overlay */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            width: min(560px, 95vw);
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 8px 30px rgba(0,0,0,.18);
            position: relative;
        }
        .modal h3 { margin-bottom: 1rem; }
        .modal-close {
            position: absolute; top: 1rem; right: 1rem;
            background: none; border: none; font-size: 1.4rem;
            cursor: pointer; color: #6b7280;
        }

        /* Pagination */
        .pagination { display: flex; gap: .4rem; margin-top: 1rem; align-items: center; }
        .pagination a, .pagination span {
            padding: .4rem .75rem;
            border-radius: 6px;
            font-size: .875rem;
            text-decoration: none;
            border: 1px solid #d1d5db;
            color: #374151;
        }
        .pagination a:hover { background: #f3f4f6; }
        .pagination .current { background: #6366f1; color: #fff; border-color: #6366f1; }

        /* Empty state */
        .empty { text-align: center; padding: 3rem 1rem; color: #9ca3af; }
        .empty p { margin-top: .5rem; font-size: .9rem; }
    </style>
</head>
<body>
<div class="container">

    <h2>Recurring Transactions</h2>

    <!-- Flash message -->
    <?php if ($flash): ?>
        <?php
        // Determine if it's an error-ish message or success
        $isError = stripos($flash, 'error') !== false
                || stripos($flash, 'invalid') !== false
                || stripos($flash, 'could not') !== false
                || stripos($flash, 'required') !== false
                || stripos($flash, 'must') !== false
                || stripos($flash, 'exceed') !== false
                || stripos($flash, 'not found') !== false;
        $alertClass = $isError ? 'alert-error' : 'alert-success';
        ?>
        <div class="alert <?= $alertClass ?>">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════
         ADD FORM
    ══════════════════════════════════ -->
    <div class="card">
        <h3>Add Recurring Transaction</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div class="form-grid">
                <div>
                    <label for="name">Name *</label>
                    <input id="name" type="text" name="name" placeholder="e.g. Netflix" required maxlength="255">
                </div>

                <div>
                    <label for="type">Type *</label>
                    <select id="type" name="type" required>
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                </div>

                <div>
                    <label for="category">Category *</label>
                    <input id="category" type="text" name="category" placeholder="e.g. Subscriptions" required maxlength="255">
                </div>

                <div>
                    <label for="amount">Amount (KES) *</label>
                    <input id="amount" type="number" step="0.01" min="0.01" max="999999999.99"
                           name="amount" placeholder="0.00" required>
                </div>

                <div>
                    <label for="frequency">Frequency *</label>
                    <select id="frequency" name="frequency" required>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>

                <div>
                    <label for="next_due">Next Due Date</label>
                    <input id="next_due" type="date" name="next_due">
                </div>

                <div class="full">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Optional notes..." maxlength="1000"></textarea>
                </div>
            </div>

            <div class="mt-1">
                <button type="submit" class="btn btn-primary">Add Transaction</button>
            </div>
        </form>
    </div>

    <!-- ══════════════════════════════════
         TABLE
    ══════════════════════════════════ -->
    <div class="card">
        <h3>Your Recurring Transactions</h3>

        <?php if (empty($rows)): ?>
            <div class="empty">
                <strong>No recurring transactions yet.</strong>
                <p>Add one above to get started.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Frequency</th>
                            <th>Next Due</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge badge-<?= $row['type'] === 'income' ? 'income' : 'expense' ?>">
                                    <?= htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>KES <?= number_format((float) $row['amount'], 2) ?></td>
                            <td><?= htmlspecialchars(ucfirst($row['frequency']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= $row['next_due']
                                    ? htmlspecialchars($row['next_due'], ENT_QUOTES, 'UTF-8')
                                    : '—' ?>
                            </td>
                            <td>
                                <span class="badge <?= $row['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <!-- Edit button → opens modal -->
                                    <a href="?edit=<?= (int) $row['recurring_id'] ?>"
                                       class="btn btn-warning btn-sm">Edit</a>

                                    <!-- Toggle active/inactive -->
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="recurring_id" value="<?= (int) $row['recurring_id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm">
                                            <?= $row['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>

                                    <!-- Delete — server-side token required -->
                                    <form method="POST"
                                          onsubmit="return confirm('Permanently delete this recurring transaction?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="recurring_id" value="<?= (int) $row['recurring_id'] ?>">
                                        <input type="hidden" name="confirm_token"
                                               value="<?= htmlspecialchars(delete_token((int) $row['recurring_id']), ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>">&laquo; Prev</a>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <?php if ($p === $page): ?>
                            <span class="current"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $p ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div><!-- /.card -->

</div><!-- /.container -->

<!-- ══════════════════════════════════
     EDIT MODAL
══════════════════════════════════ -->
<div class="modal-overlay <?= $edit_row ? 'open' : '' ?>" id="editModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <button class="modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
        <h3 id="editModalTitle">Edit Recurring Transaction</h3>

        <?php if ($edit_row): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="recurring_id" value="<?= (int) $edit_row['recurring_id'] ?>">

            <div class="form-grid">
                <div>
                    <label for="edit_name">Name *</label>
                    <input id="edit_name" type="text" name="name" required maxlength="255"
                           value="<?= htmlspecialchars($edit_row['name'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div>
                    <label for="edit_type">Type *</label>
                    <select id="edit_type" name="type" required>
                        <?php foreach (ALLOWED_TYPES as $t): ?>
                            <option value="<?= $t ?>" <?= $edit_row['type'] === $t ? 'selected' : '' ?>>
                                <?= ucfirst($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="edit_category">Category *</label>
                    <input id="edit_category" type="text" name="category" required maxlength="255"
                           value="<?= htmlspecialchars($edit_row['category'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div>
                    <label for="edit_amount">Amount (KES) *</label>
                    <input id="edit_amount" type="number" step="0.01" min="0.01" max="999999999.99"
                           name="amount" required
                           value="<?= htmlspecialchars($edit_row['amount'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div>
                    <label for="edit_frequency">Frequency *</label>
                    <select id="edit_frequency" name="frequency" required>
                        <?php foreach (ALLOWED_FREQUENCIES as $f): ?>
                            <option value="<?= $f ?>" <?= $edit_row['frequency'] === $f ? 'selected' : '' ?>>
                                <?= ucfirst($f) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="edit_next_due">Next Due Date</label>
                    <input id="edit_next_due" type="date" name="next_due"
                           value="<?= htmlspecialchars($edit_row['next_due'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="full">
                    <label for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" maxlength="1000"><?= htmlspecialchars($edit_row['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <div class="mt-1" style="display:flex; gap:.5rem;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            </div>
        </form>
        <?php else: ?>
            <p>Transaction not found.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function closeModal() {
    // Remove ?edit= from URL and close the modal
    const url = new URL(window.location.href);
    url.searchParams.delete('edit');
    window.history.replaceState({}, '', url);
    document.getElementById('editModal').classList.remove('open');
}

// Close modal on overlay click (outside the modal box)
document.getElementById('editModal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

// Close modal on Escape key
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>