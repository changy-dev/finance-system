<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
session_start();
include __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/csrf.php';

// If this is an AJAX POST request, return JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    csrf_verify_json();

    function csrf_json_exit(array $payload): void {
        $payload['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        echo json_encode($payload);
        exit;
    }

    $user_id = (int) $_SESSION['user_id'];

    $type        = trim($_POST['type']        ?? '');
    $category    = trim($_POST['category']    ?? '');
    $amount      = $_POST['amount']           ?? '';
    $description = trim($_POST['description'] ?? '');
    $created_at  = trim($_POST['created_at']  ?? '');

    $allowed_types = ['expense', 'income'];
    if (!in_array($type, $allowed_types, true)) {
        csrf_json_exit(['success' => false, 'message' => 'Invalid transaction type']);
    }

    $allowed_categories = [
        'Groceries', 'Household Supplies', 'Eating Out',
        'Fuel & Charging', 'Vehicle Maintenance', 'Commuting',
        'Utilities', 'Home Decor', 'Maintenance & DIY', 'Kitchenware',
        'Health & Pharmacy', 'Personal Grooming',
        'Clothing & Accessories', 'Electronics & Tech', 'Entertainment',
        'Gifts & Celebrations', 'Pet Supplies', 'Impulse Buys', 'Other',
        'Food', 'Transport', 'Shopping', 'Health',
        'Salary', 'Business', 'Investments', 'Shares', 'Freelance', 'Family', 'Rental', 'Gift', 'Other Income',
    ];
    if (!in_array($category, $allowed_categories, true)) {
        csrf_json_exit(['success' => false, 'message' => 'Invalid category: ' . htmlspecialchars($category)]);
    }

    $amount = floatval($amount);
    if ($amount <= 0) {
        csrf_json_exit(['success' => false, 'message' => 'Amount must be greater than 0']);
    }

    if (empty($created_at) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_at)) {
        $created_at = date('Y-m-d');
    }

    // Do NOT htmlspecialchars here — the DB should store raw text.
    // Output escaping happens at render time (in HTML templates).
    // Storing HTML-encoded text causes double-encoding when displayed.

    $stmt = $conn->prepare(
        "INSERT INTO transactions (user_id, type, category, amount, description, created_at)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        csrf_json_exit(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
    }

    $stmt->bind_param('issdss', $user_id, $type, $category, $amount, $description, $created_at);

    if ($stmt->execute()) {
        csrf_json_exit([
            'success' => true,
            'message' => 'Transaction saved successfully!',
            'id'      => $stmt->insert_id,
        ]);
    } else {
        csrf_json_exit(['success' => false, 'message' => 'Insert failed: ' . $stmt->error]);
    }

    $stmt->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Transaction</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background-color: #f4f7f6; }
        .form-container { max-width: 450px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background-color: #28a745; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
        button:hover { background-color: #218838; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; display: none; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
<div class="form-container">
    <h2>Add Transaction</h2>
    <div id="alertBox" class="alert"></div>
    <form id="transactionForm">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label for="type">Transaction Type</label>
            <select name="type" id="type" required>
                <option value="expense">Expense</option>
                <option value="income">Income</option>
            </select>
        </div>
        <div class="form-group">
            <label for="category">Category</label>
            <select name="category" id="category" required>
                <optgroup label="Expenses">
                    <option value="Groceries">Groceries</option>
                    <option value="Household Supplies">Household Supplies</option>
                    <option value="Eating Out">Eating Out</option>
                    <option value="Fuel & Charging">Fuel & Charging</option>
                    <option value="Vehicle Maintenance">Vehicle Maintenance</option>
                    <option value="Commuting">Commuting</option>
                    <option value="Utilities">Utilities</option>
                    <option value="Home Decor">Home Decor</option>
                    <option value="Entertainment">Entertainment</option>
                    <option value="Other">Other Expense</option>
                </optgroup>
                <optgroup label="Income">
                    <option value="Salary">Salary</option>
                    <option value="Business">Business</option>
                    <option value="Investments">Investments</option>
                    <option value="Freelance">Freelance</option>
                    <option value="Gift">Gift</option>
                    <option value="Other Income">Other Income</option>
                </optgroup>
            </select>
        </div>
        <div class="form-group">
            <label for="amount">Amount ($)</label>
            <input type="number" step="0.01" name="amount" id="amount" placeholder="0.00" required>
        </div>
        <div class="form-group">
            <label for="created_at">Date</label>
            <input type="date" name="created_at" id="created_at" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea name="description" id="description" rows="3" placeholder="Notes..."></textarea>
        </div>
        <button type="submit">Save Transaction</button>
    </form>
</div>
<script>
document.getElementById('transactionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const alertBox = document.getElementById('alertBox');
    alertBox.style.display = 'none';
    const formData = new FormData(this);
    fetch('add_transaction.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alertBox.className = 'alert alert-success';
            alertBox.textContent = data.message;
            alertBox.style.display = 'block';
            this.reset();
            document.getElementById('created_at').value = new Date().toISOString().split('T')[0];
        } else {
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = data.message;
            alertBox.style.display = 'block';
        }
        if (data.csrf_token) {
            const el = document.querySelector('input[name="csrf_token"]');
            if (el) el.value = data.csrf_token;
        }
    })
    .catch(error => {
        alertBox.className = 'alert alert-danger';
        alertBox.textContent = 'Error: ' + error.message;
        alertBox.style.display = 'block';
    });
});
</script>
</body>
</html>