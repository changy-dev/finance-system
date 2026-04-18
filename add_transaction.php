<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION['user_id'];
    $amount = $_POST['amount'];
    $type = $_POST['type'];
    $category = $_POST['category'];

    if ($amount <= 0) {
        die("Invalid amount");
    }

    $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, category) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("idss", $user_id, $amount, $type, $category);
    $stmt->execute();

    header("Location: dashboard.php?success=1");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Transaction</title>

<style>
body {
    background:#030303;
    color:white;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    font-family:Arial;
}

.box {
    background:rgba(255,255,255,0.05);
    padding:30px;
    border-radius:20px;
    width:300px;
}

input,select {
    width:100%;
    padding:10px;
    margin:10px 0;
}

button {
    width:100%;
    padding:10px;
    background:#e6006f;
    color:white;
    border:none;
}
</style>
</head>

<body>

<div class="box">
<h2>Add Transaction</h2>

<form method="POST">
<input type="number" name="amount" placeholder="Amount" required autofocus>

<select name="type" id="type" onchange="updateCat()">
<option value="income">Income</option>
<option value="expense">Expense</option>
</select>

<select name="category" id="category"></select>

<button type="submit">Add</button>
</form>

</div>

<script>
const categories = {
income:["Salary","Investment"],
expense:["Food","Rent","Bills","Entertainment","Health","Traveling"]
};

function updateCat(){
let type = document.getElementById("type").value;
let cat = document.getElementById("category");
cat.innerHTML="";

categories[type].forEach(c=>{
let o=document.createElement("option");
o.value=c;
o.text=c;
cat.appendChild(o);
});
}

updateCat();
</script>

</body>
</html>