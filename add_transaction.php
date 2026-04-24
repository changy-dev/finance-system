<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

header("Content-Type: text/html; charset=UTF-8");

// Detect if request is JSON (API) or form
$isJson = strpos($_SERVER["CONTENT_TYPE"] ?? '', 'application/json') !== false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($isJson) {
        $data = json_decode(file_get_contents("php://input"), true);
        $amount = $data['amount'] ?? 0;
        $type = $data['type'] ?? '';
        $category = $data['category'] ?? '';
    } else {
        $amount = $_POST['amount'] ?? 0;
        $type = $_POST['type'] ?? '';
        $category = $_POST['category'] ?? '';
    }

    // ✅ Validation
    if ($amount <= 0 || !in_array($type, ['income','expense'])) {
        if ($isJson) {
            echo json_encode(["status"=>false,"message"=>"Invalid input"]);
            exit();
        } else {
            die("Invalid input");
        }
    }

    // ✅ Insert
    $stmt = $conn->prepare("
        INSERT INTO transactions (user_id, amount, type, category)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("idss", $_SESSION['user_id'], $amount, $type, $category);

    if ($stmt->execute()) {

        if ($isJson) {
            echo json_encode(["status"=>true]);
        } else {
            header("Location: dashboard.php?success=1");
        }

    } else {
        if ($isJson) {
            echo json_encode(["status"=>false,"message"=>"DB error"]);
        } else {
            echo "Error saving transaction";
        }
    }

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
    font-family:Arial;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
}

.box {
    background:#111;
    padding:25px;
    border-radius:15px;
    width:300px;
}

input, select {
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
    cursor:pointer;
}

a {
    display:block;
    text-align:center;
    margin-top:10px;
    color:#888;
}
</style>
</head>

<body>

<div class="box">
<h2>Add Transaction</h2>

<form method="POST">

<input type="number" name="amount" placeholder="Amount" required>

<select name="type" id="type" onchange="updateCategories()">
<option value="income">Income</option>
<option value="expense">Expense</option>
</select>

<select name="category" id="category"></select>

<button type="submit">Add</button>

<a href="dashboard.php">Back</a>

</form>
</div>

<script>
const categories = {
    income:["Salary","Investment"],
    expense:["Food","Rent","Bills","Entertainment","Health","Traveling","Shopping"]
};

function updateCategories(){
    let type = document.getElementById("type").value;
    let cat = document.getElementById("category");

    cat.innerHTML = "";

    categories[type].forEach(c=>{
        let o = document.createElement("option");
        o.value = c;
        o.text = c;
        cat.appendChild(o);
    });
}

updateCategories();
</script>

</body>
</html>