<?php
session_start();
include 'db.php';

// 1. Security & Identity Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['name'] ?? 'User';

// Extract Last Name logic
$name_parts = explode(' ', trim($full_name));
$last_name = (count($name_parts) > 1) ? end($name_parts) : $full_name;

// 2. Temporal Greeting & Glow Logic (Nairobi Timezone)
date_default_timezone_set('Africa/Nairobi'); 
$hour = date('H');
$day_of_month = (int)date('j');

if ($hour < 12) {
    $greeting = "Good Morning"; $time_icon = "fa-sun"; $glow_color = "rgba(251, 191, 36, 0.2)";
} elseif ($hour < 17) {
    $greeting = "Good Afternoon"; $time_icon = "fa-cloud-sun"; $glow_color = "rgba(124, 58, 237, 0.2)";
} elseif ($hour < 21) {
    $greeting = "Good Evening"; $time_icon = "fa-moon"; $glow_color = "rgba(148, 163, 184, 0.2)";
} else {
    $greeting = "Good Night"; $time_icon = "fa-stars"; $glow_color = "rgba(255, 255, 255, 0.1)";
}

// 3. Financial Data & Quantum Calculations
$income = $conn->query("SELECT SUM(amount) AS total FROM transactions WHERE user_id='$user_id' AND type='income' AND is_deleted_by_user = 0")->fetch_assoc()['total'] ?? 0;
$expense = $conn->query("SELECT SUM(amount) AS total FROM transactions WHERE user_id='$user_id' AND type='expense' AND is_deleted_by_user = 0")->fetch_assoc()['total'] ?? 0;
$balance = $income - $expense;

// Advanced Metrics
$daily_burn = ($expense > 0) ? ($expense / $day_of_month) : 0;
$efficiency = ($income > 0) ? ($balance / $income) * 100 : 0;
$runway = ($daily_burn > 0 && $balance > 0) ? floor($balance / $daily_burn) : 0;

// Wealth Tier Logic
if ($balance <= 0) { $tier = "Void Node"; $tier_color = "#f87171"; }
elseif ($balance < 50000) { $tier = "Bronze Node"; $tier_color = "#cd7f32"; }
elseif ($balance < 200000) { $tier = "Silver Node"; $tier_color = "#e2e8f0"; }
elseif ($balance < 1000000) { $tier = "Gold Sovereign"; $tier_color = "#fbbf24"; }
else { $tier = "Royal Matrix King"; $tier_color = "#a78bfa"; }

// 4. Fetch Recent Transactions
$records = $conn->query("SELECT * FROM transactions WHERE user_id='$user_id' AND is_deleted_by_user = 0 ORDER BY date DESC LIMIT 5");

// 5. Fetch User Profile Data
$user_data = $conn->query("SELECT * FROM users WHERE user_id='$user_id'")->fetch_assoc();
$profile_pic = (!empty($user_data['profile_pic']) && $user_data['profile_pic'] != 'default.png') 
               ? "uploads/" . $user_data['profile_pic'] 
               : "https://ui-avatars.com/api/?name=" . urlencode($full_name) . "&background=7c3aed&color=fff";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Royal Matrix | Quantum Terminal</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --royal-purple: #7c3aed;
            --dark-space: #020617;
            --glass: rgba(255, 255, 255, 0.05);
            --neon-emerald: #10b981;
            --neon-pink: #f472b6;
            --neon-gold: #fbbf24;
            --slate: #94a3b8;
        }

        body { font-family: 'Inter', sans-serif; background: var(--dark-space); margin: 0; color: white; overflow-x: hidden; }

        /* --- SIDEBAR --- */
        .sidebar { height: 100%; width: 0; position: fixed; z-index: 2000; top: 0; left: 0; background: #0a0f1e; overflow-x: hidden; transition: 0.5s; padding-top: 20px; border-right: 1px solid var(--glass); }
        .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid var(--glass); margin-bottom: 15px; }
        .sidebar-header img { width: 70px; height: 70px; border-radius: 50%; border: 2px solid var(--royal-purple); box-shadow: 0 0 15px rgba(124, 58, 237, 0.3); }
        .sidebar a { padding: 15px 25px; text-decoration: none; font-size: 13px; color: var(--slate); display: block; transition: 0.3s; font-weight: 600; }
        .sidebar a i { width: 25px; color: var(--royal-purple); margin-right: 10px; }
        .sidebar a:hover { color: white; background: var(--glass); border-left: 4px solid var(--royal-purple); }
        .closebtn { position: absolute; top: 10px; right: 20px; font-size: 30px; color: white; cursor: pointer; }

        /* --- MAIN --- */
        #main { transition: margin-left .5s; }
        .navbar { background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(10px); padding: 15px 35px; display: flex; align-items: center; border-bottom: 1px solid var(--glass); position: sticky; top: 0; z-index: 1000; }
        .container { max-width: 1300px; margin: 40px auto; padding: 0 25px; }

        /* --- HEADER --- */
        .welcome-section { margin-bottom: 45px; position: relative; }
        .greeting-shimmer { position: absolute; top: -80px; left: 0; width: 200px; height: 250px; background: radial-gradient(circle, <?php echo $glow_color; ?> 0%, transparent 70%); pointer-events: none; }
        .temporal-status i { font-size: 2.5em; color: <?php echo ($hour < 17 ? 'var(--neon-gold)' : 'var(--royal-purple)'); ?>; filter: drop-shadow(0 0 15px <?php echo $glow_color; ?>); margin-right: 10px;}
        .user-last-name { font-size: 1.8em; font-weight: 900; background: linear-gradient(to right, #fff, var(--neon-gold)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        /* --- GRID STATS --- */
        .hero-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .cyber-card { background: var(--glass); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 24px; padding: 25px; position: relative; overflow: hidden; }
        .card-label { font-size: 0.65em; letter-spacing: 2px; font-weight: 800; color: var(--slate); text-transform: uppercase; }
        .card-value { font-size: 1.6em; font-weight: 900; margin-top: 8px; }
        .progress-bg { background: rgba(255,255,255,0.05); height: 6px; border-radius: 10px; margin-top: 15px; }
        .progress-fill { background: var(--royal-purple); height: 100%; border-radius: 10px; }

        /* --- STOCK SURVEILLANCE PANEL --- */
        .stock-panel { background: linear-gradient(145deg, rgba(15, 23, 42, 0.4), rgba(2, 6, 23, 0.6)); border-radius: 30px; padding: 30px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 40px; }
        .stock-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        .stock-item { background: rgba(255,255,255,0.03); padding: 15px; border-radius: 15px; transition: 0.3s; }
        .stock-item:hover { background: rgba(255,255,255,0.06); transform: scale(1.02); }

        /* --- LEDGER --- */
        .analytics-grid { display: grid; grid-template-columns: 1.6fr 1fr; gap: 30px; }
        .panel { background: var(--glass); border-radius: 30px; padding: 30px; border: 1px solid rgba(255,255,255,0.05); }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 15px 0; border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 0.9em; }
        .indicator-pos { color: var(--neon-emerald); font-weight: 900; }
        .indicator-neg { color: var(--neon-pink); font-weight: 900; }

        .btn-action { background: linear-gradient(135deg, var(--royal-purple), #a78bfa); color: white; padding: 18px 45px; border-radius: 20px; text-decoration: none; font-weight: 900; display: inline-block; }
    </style>
</head>
<body>

<div id="mySidebar" class="sidebar">
    <span class="closebtn" onclick="closeNav()">&times;</span>
    <div class="sidebar-header">
        <img src="<?php echo $profile_pic; ?>" alt="User">
        <div style="margin-top:15px; font-weight: 900; letter-spacing: -0.5px;"><?php echo htmlspecialchars($full_name); ?></div>
        <p style="font-size: 11px; font-weight: 800; color: <?php echo $tier_color; ?>; text-transform: uppercase;"><?php echo $tier; ?></p>
    </div>

    <a href="account_settings.php"><i class="fas fa-user-shield"></i> Account Settings</a>
    <a href="user_info.php"><i class="fas fa-database"></i> User Information</a>
    <a href="#"><i class="fas fa-language"></i> App Language</a>
    <a href="#"><i class="fas fa-palette"></i> Theme Interface</a>
    <a href="#"><i class="fas fa-microchip"></i> Storage & Data</a>
    <a href="#"><i class="fas fa-bolt"></i> System Updates</a>
    <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.05); margin: 15px 0;">
    <a href="#"><i class="fas fa-headset"></i> Help & Feedback</a>
    <a href="logout.php" style="color: #fb7185;"><i class="fas fa-power-off"></i> Terminate Session</a>
</div>

<div id="main">
    <nav class="navbar">
        <span style="font-size:24px; cursor:pointer;" onclick="openNav()"><i class="fas fa-bars-staggered"></i></span>
        <div style="font-weight: 900; font-size: 1.4em; margin-left: 25px; letter-spacing: -1px;">ROYAL<span style="color:var(--royal-purple);">MATRIX</span></div>
    </nav>

    <div class="container">
        <div class="welcome-section">
            <div class="greeting-shimmer"></div>
            <div class="temporal-status"><i class="fas <?php echo $time_icon; ?>"></i> <?php echo $greeting; ?>,</div>
            <div class="user-last-name"><?php echo htmlspecialchars($last_name); ?></div>
            <h1 style="font-size: 3.5em; font-weight: 900; margin: 0; background: linear-gradient(to right, #fff, #475569); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -2px;">Quantum Terminal</h1>
        </div>

        <div class="hero-stats">
            <div class="cyber-card"><span class="card-label">Quantum Inflow</span><div class="card-value" style="color: var(--neon-emerald);">KES <?php echo number_format($income, 2); ?></div></div>
            <div class="cyber-card"><span class="card-label">Capital Burn</span><div class="card-value" style="color: var(--neon-pink);">KES <?php echo number_format($expense, 2); ?></div></div>
            <div class="cyber-card"><span class="card-label">Net Sovereignty</span><div class="card-value" style="color: var(--neon-gold);">KES <?php echo number_format($balance, 2); ?></div></div>
            <div class="cyber-card"><span class="card-label">Daily Velocity</span><div class="card-value">KES <?php echo number_format($daily_burn, 2); ?></div></div>
            <div class="cyber-card"><span class="card-label">Survival Runway</span><div class="card-value" style="color: #a78bfa;"><?php echo $runway; ?> <span style="font-size: 0.5em;">DAYS</span></div></div>
            <div class="cyber-card"><span class="card-label">Efficiency</span><div class="card-value"><?php echo number_format($efficiency, 1); ?>%</div><div class="progress-bg"><div class="progress-fill" style="width: <?php echo $efficiency; ?>%;"></div></div></div>
        </div>

        <div class="stock-panel">
            <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin:0; font-weight: 800;"><i class="fas fa-chart-line" style="color:var(--neon-emerald); margin-right:10px;"></i>Market Surveillance</h3>
                <span style="font-size: 0.7em; background: var(--neon-emerald); color: #020617; padding: 4px 12px; border-radius: 20px; font-weight: 900;">LIVE UPDATES</span>
            </div>
            <div class="stock-grid">
                <div class="stock-item" style="border-left: 3px solid var(--neon-emerald);">
                    <div style="font-size: 0.7em; color: var(--slate);">Safaricom (SCOM)</div>
                    <div style="font-weight: 900;">KES 15.40 <span style="color: var(--neon-emerald); font-size: 0.8em;">+1.2%</span></div>
                </div>
                <div class="stock-item" style="border-left: 3px solid var(--neon-pink);">
                    <div style="font-size: 0.7em; color: var(--slate);">KCB Group</div>
                    <div style="font-weight: 900;">KES 32.10 <span style="color: var(--neon-pink); font-size: 0.8em;">-0.4%</span></div>
                </div>
                <div class="stock-item" style="border-left: 3px solid var(--neon-gold);">
                    <div style="font-size: 0.7em; color: var(--slate);">Equity Bank</div>
                    <div style="font-weight: 900;">KES 41.55 <span style="color: var(--neon-emerald); font-size: 0.8em;">+2.1%</span></div>
                </div>
                <div class="stock-item" style="border-left: 3px solid var(--royal-purple);">
                    <div style="font-size: 0.7em; color: var(--slate);">NSE 20 Index</div>
                    <div style="font-weight: 900;">1,542.10 <span style="color: var(--slate); font-size: 0.8em;">0.0%</span></div>
                </div>
            </div>
        </div>

        <div class="analytics-grid">
            <div class="panel">
                <h3 style="margin:0 0 20px 0; font-weight: 800;">Active Ledger</h3>
                <table>
                    <tbody>
                        <?php while($row = $records->fetch_assoc()): ?>
                        <tr>
                            <td><i class="fas fa-circle" style="font-size: 0.5em; color: <?php echo ($row['type']=='income' ? 'var(--neon-emerald)' : 'var(--neon-pink)'); ?>;"></i></td>
                            <td style="font-weight: 600;"><?php echo ucfirst($row['category']); ?></td>
                            <td style="text-align:right;">
                                <span class="<?php echo ($row['type']=='income' ? 'indicator-pos' : 'indicator-neg'); ?>">
                                    <?php echo ($row['type']=='income' ? '+' : '-'); ?> KES <?php echo number_format($row['amount'], 2); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <div class="panel">
                <h3 style="margin:0 0 20px 0; text-align: center; font-weight: 800; color: var(--slate);">FLOW DYNAMICS</h3>
                <div style="height: 250px;"><canvas id="matrixChart"></canvas></div>
            </div>
        </div>

        <div style="margin-top: 50px; text-align: center;"><a href="add_transaction.php" class="btn-action">INITIALIZE NEW RECORD</a></div>
    </div>
</div>

<script>
    function openNav() { document.getElementById("mySidebar").style.width = "300px"; document.getElementById("main").style.marginLeft = "300px"; }
    function closeNav() { document.getElementById("mySidebar").style.width = "0"; document.getElementById("main").style.marginLeft = "0"; }
    const ctx = document.getElementById('matrixChart').getContext('2d');
    new Chart(ctx, { type: 'doughnut', data: { labels: ['Inflow', 'Burn'], datasets: [{ data: [<?php echo $income; ?>, <?php echo $expense; ?>], backgroundColor: ['#10b981', '#f472b6'], borderWidth: 0, cutout: '85%', spacing: 5 }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false } } } });
</script>
</body>
</html>