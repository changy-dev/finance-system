<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* MONTHLY TREND */
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthly = array_fill(0,12,0);

$res = $conn->query("
SELECT MONTH(created_at) m, SUM(amount) total 
FROM transactions 
WHERE user_id=$user_id AND type='expense'
GROUP BY m
");

while($row = $res->fetch_assoc()){
    $monthly[$row['m']-1] = (int)$row['total'];
}

/* CATEGORY */
$catLabels = [];
$catData = [];
$catColors = [];

$colors = ['#f43f5e','#8b5cf6','#3b82f6','#22c55e','#f59e0b','#06b6d4'];

$q = $conn->query("
SELECT category, SUM(amount) total 
FROM transactions 
WHERE user_id=$user_id AND type='expense'
GROUP BY category
");

$totalExpense = 0;
$i=0;

while($r = $q->fetch_assoc()){
    $catLabels[] = $r['category'];
    $catData[] = $r['total'];
    $catColors[] = $colors[$i % count($colors)];
    $totalExpense += $r['total'];
    $i++;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body{
margin:0;
font-family:Inter;
background:#020617;
color:#e2e8f0;
}

.app{display:flex;}
.sidebar{width:240px;padding:20px;background:#020617;}
.sidebar a{
display:block;padding:12px;border-radius:10px;
color:#94a3b8;text-decoration:none;
}
.sidebar a.active{
background:linear-gradient(135deg,#6366f1,#3b82f6);
color:white;
}

.main{flex:1;padding:40px;}

.card{
background:#0f172a;
padding:20px;
border-radius:16px;
margin-bottom:20px;
}

.grid{display:grid;gap:20px;}
@media(min-width:992px){
.grid-2{grid-template-columns:2fr 1fr;}
.grid-3{grid-template-columns:repeat(3,1fr);}
}

.center{
position:absolute;
top:50%;
left:50%;
transform:translate(-50%,-50%);
text-align:center;
}

.tx{
padding:10px;
border-bottom:1px solid #1e293b;
cursor:pointer;
}
</style>
</head>

<body>

<div class="app">

<div class="sidebar">
<h2>Finance</h2>
<a href="#" class="active">Dashboard</a>
<a href="transactions.php">Transactions</a>
<a href="settings.php">Settings</a>
</div>

<div class="main">

<h2>Analytics</h2>

<!-- SUMMARY -->
<div class="grid grid-3">
<div class="card">Total: KES <?php echo number_format($totalExpense); ?></div>
<div class="card">Categories: <?php echo count($catLabels); ?></div>
<div class="card">Avg/Month: KES <?php echo number_format($totalExpense/12); ?></div>
</div>

<!-- CHARTS -->
<div class="grid grid-2">

<div class="card">
<h3>Trend</h3>
<canvas id="lineChart"></canvas>
</div>

<div class="card" style="position:relative;">
<h3>Breakdown</h3>

<div style="width:250px;margin:auto;position:relative;">
<canvas id="pieChart"></canvas>

<div id="center" class="center">
<div id="centerLabel">Total</div>
<div id="centerValue">KES 0</div>
</div>

</div>
</div>

</div>

<!-- TRANSACTIONS -->
<div class="card">
<h3>Filtered Transactions</h3>
<div id="txList">Click a category to filter</div>
</div>

</div>
</div>

<script>

/* DATA */
let labels = <?php echo json_encode($catLabels); ?>;
let dataVals = <?php echo json_encode($catData); ?>;
let total = dataVals.reduce((a,b)=>a+b,0);

document.getElementById('centerValue').innerText =
"KES " + total.toLocaleString();

/* PIE */
let pie = new Chart(document.getElementById('pieChart'),{
type:'doughnut',
data:{
labels:labels,
datasets:[{
data:dataVals,
backgroundColor: <?php echo json_encode($catColors); ?>
}]
},
options:{
cutout:'70%',
onHover:(e,el)=>{
if(el.length){
let i = el[0].index;
updateCenter(labels[i], dataVals[i]);
}else{
updateCenter('Total', total);
}
},
onClick:(e,el)=>{
if(el.length){
let i = el[0].index;
filterTransactions(labels[i]);
}
}
}
});

/* LINE */
new Chart(document.getElementById('lineChart'),{
type:'line',
data:{
labels: <?php echo json_encode($months); ?>,
datasets:[{
data: <?php echo json_encode($monthly); ?>,
borderColor:'#60a5fa',
backgroundColor:'rgba(96,165,250,0.2)',
fill:true,
tension:0.4
}]
}
});

/* CENTER UPDATE */
function updateCenter(label,val){
document.getElementById('centerLabel').innerText = label;
document.getElementById('centerValue').innerText =
"KES " + val.toLocaleString();
}

/* FILTER */
function filterTransactions(category){

fetch('filter.php?category='+category)
.then(res=>res.text())
.then(html=>{
document.getElementById('txList').innerHTML = html;
});

}
</script>

</body>
</html>