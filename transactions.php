<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* FETCH */
$transactions = $conn->query("
SELECT * FROM transactions 
WHERE user_id=$user_id 
ORDER BY created_at DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>

body{
    margin:0;
    font-family:Inter;
    background:#020617;
    color:#e2e8f0;
}

.app{display:flex;}

.sidebar{
    width:250px;
    padding:25px;
    background:#020617;
}

.sidebar a{
    display:block;
    padding:12px;
    color:#94a3b8;
    text-decoration:none;
    border-radius:10px;
}

.sidebar a.active{
    background:linear-gradient(135deg,#6366f1,#3b82f6);
    color:white;
}

.main{flex:1;padding:40px;}

.card{
    background:#0f172a;
    padding:20px;
    border-radius:12px;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,td{
    padding:12px;
    text-align:left;
}

tr{
    border-bottom:1px solid #1e293b;
}

.income{color:#22c55e;}
.expense{color:#ef4444;}

</style>
</head>

<body>

<div class="app">

<div class="sidebar">
<h2>Finance</h2>
<a href="dashboard.php">Dashboard</a>
<a href="transactions.php" class="active">Transactions</a>
<a href="settings.php">Settings</a>
</div>

<div class="main">

<h2>Transactions</h2>

<div class="card">

<table>
<tr>
<th>Category</th>
<th>Amount</th>
<th>Type</th>
<th>Date</th>
</tr>

<?php while($t = $transactions->fetch_assoc()): ?>

<tr>
<td><?php echo $t['category']; ?></td>

<td class="<?php echo $t['type']; ?>">
KES <?php echo number_format($t['amount']); ?>
</td>

<td><?php echo $t['type']; ?></td>
<td><?php echo $t['created_at']; ?></td>
</tr>

<?php endwhile; ?>

</table>

</div>

</div>
</div>

</body>
</html>