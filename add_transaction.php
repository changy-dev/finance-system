<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg = "";

if (isset($_POST['save_transaction'])) {
    $type = $_POST['type'];
    $amount = $conn->real_escape_string($_POST['amount']);
    $category = $conn->real_escape_string($_POST['category']);
    $date = $_POST['date'];

    if($amount > 0 && !empty($category) && !empty($date)) {
        $sql = "INSERT INTO transactions (user_id, type, amount, category, date, is_deleted_by_user) 
                VALUES ('$user_id', '$type', '$amount', '$category', '$date', 0)";
        
        if ($conn->query($sql)) {
            $msg = "<p class='success'><i class='fas fa-crown'></i> Asset logged successfully!</p>";
        } else {
            $msg = "<p class='error'>Ledger sync failed.</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Royal Finance | Transaction Matrix</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --royal-purple: #7c3aed;
            --gold: #fbbf24;
            --pink: #db2777;
            --emerald: #10b981;
            --ocean: #0ea5e9;
            --slate: #64748b;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background: radial-gradient(circle at top right, #1e1b4b, #0f172a); 
            margin: 0; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            color: white; 
        }
        
        .matrix-card { 
            background: rgba(255, 255, 255, 0.02); 
            backdrop-filter: blur(25px); 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 40px; 
            padding: 50px; 
            width: 100%; 
            max-width: 500px; 
            box-shadow: 0 50px 100px rgba(0,0,0,0.5); 
            position: relative;
            overflow: hidden;
        }

        /* Decorative Glow */
        .matrix-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.05) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-title { 
            font-weight: 900; 
            font-size: 2.5em; 
            text-align: center; 
            margin-bottom: 10px; 
            background: linear-gradient(to bottom right, #fff, var(--slate)); 
            -webkit-background-clip: text; 
            -webkit-text-fill-color: transparent; 
            letter-spacing: -2px;
        }

        .subtitle {
            text-align: center;
            color: var(--slate);
            font-size: 0.9em;
            margin-bottom: 40px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 700;
        }

        .input-box { position: relative; margin-bottom: 30px; }
        
        input, select { 
            width: 100%; 
            padding: 20px 20px 20px 60px; 
            background: rgba(0,0,0,0.3); 
            border: 1px solid rgba(255,255,255,0.05); 
            border-radius: 20px; 
            color: white; 
            font-size: 1.05em; 
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            box-sizing: border-box;
        }

        input:focus, select:focus { 
            background: rgba(0,0,0,0.5); 
            border-color: var(--royal-purple); 
            box-shadow: 0 0 30px rgba(124, 58, 237, 0.2);
            outline: none; 
        }

        .input-box i { 
            position: absolute; 
            left: 25px; 
            top: 22px; 
            color: var(--slate); 
            font-size: 1.2em; 
            transition: 0.3s; 
        }

        input:focus + i, select:focus + i { color: var(--royal-purple); transform: scale(1.2); }

        .btn-execute { 
            background: linear-gradient(135deg, var(--royal-purple), var(--pink)); 
            border: none; 
            width: 100%; 
            padding: 22px; 
            border-radius: 22px; 
            color: white; 
            font-weight: 900; 
            font-size: 1.1em; 
            cursor: pointer; 
            transition: 0.4s; 
            text-transform: uppercase;
            letter-spacing: 2px;
            box-shadow: 0 15px 35px rgba(219, 39, 119, 0.3);
        }

        .btn-execute:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 25px 50px rgba(124, 58, 237, 0.5); 
        }

        .success { color: var(--emerald); text-align: center; margin-bottom: 20px; font-weight: 800; }
    </style>
</head>
<body>

<div class="matrix-card">
    <h1 class="hero-title">Royal Ledger</h1>
    <p class="subtitle">Transaction Matrix</p>
    
    <?php echo $msg; ?>

    <form method="POST">
        <div class="input-box">
            <i class="fas fa-fingerprint"></i>
            <select name="type" id="typeSelect" onchange="updateTheme()">
                <option value="income">Wealth Inflow (Income)</option>
                <option value="expense">Capital Outflow (Expense)</option>
                <option value="investment">Asset Growth (Investment)</option>
                <option value="debt">Liability (Debt/Loan)</option>
            </select>
        </div>

        <div class="input-box">
            <i class="fas fa-coins"></i>
            <input type="number" step="0.01" name="amount" placeholder="Volume (KES)" required>
        </div>

        <div class="input-box">
            <i class="fas fa-project-diagram"></i>
            <input type="text" name="category" placeholder="Category Designation" required>
        </div>

        <div class="input-box">
            <i class="fas fa-clock"></i>
            <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
        </div>

        <button type="submit" name="save_transaction" class="btn-execute" id="execBtn">
            Sync Transaction
        </button>
    </form>
    
    <a href="dashboard.php" style="display:block; text-align:center; margin-top:30px; color:var(--slate); text-decoration:none; font-weight:700;">
        <i class="fas fa-power-off"></i> Terminate & Exit
    </a>
</div>

<script>
    function updateTheme() {
        const type = document.getElementById('typeSelect').value;
        const btn = document.getElementById('execBtn');
        const colors = {
            income: 'linear-gradient(135deg, #10b981, #34d399)',
            expense: 'linear-gradient(135deg, #db2777, #f472b6)',
            investment: 'linear-gradient(135deg, #0ea5e9, #7dd3fc)',
            debt: 'linear-gradient(135deg, #475569, #94a3b8)'
        };
        btn.style.background = colors[type];
    }
    updateTheme();
</script>

</body>
</html>