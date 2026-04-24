<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) exit();

$user_id = $_SESSION['user_id'];

/* FETCH USER */
$user = $conn->query("SELECT * FROM users WHERE user_id=$user_id")->fetch_assoc();

/* HANDLE AJAX SAVE */
if(isset($_POST['ajax'])){
    
    $name = $_POST['full_name'];
    $phone = $_POST['phone'];
    $theme = $_POST['theme'];
    $lang = $_POST['language'];

    if(!empty($_FILES['profile_pic']['name'])){
        $file = time().'_'.$_FILES['profile_pic']['name'];
        move_uploaded_file($_FILES['profile_pic']['tmp_name'], "uploads/".$file);

        $conn->query("UPDATE users SET profile_pic='$file' WHERE user_id=$user_id");
    }

    $conn->query("
    UPDATE users SET 
    full_name='$name',
    phone_number='$phone',
    theme='$theme',
    language='$lang'
    WHERE user_id=$user_id
    ");

    echo json_encode(["status"=>"ok"]);
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
:root{
--bg:#020617;
--card:#0f172a;
--text:#e2e8f0;
}

body.light{
--bg:#f1f5f9;
--card:white;
--text:#0f172a;
}

body{
margin:0;
background:var(--bg);
color:var(--text);
font-family:Inter;
}

.container{
max-width:700px;
margin:auto;
padding:30px;
}

.card{
background:var(--card);
padding:25px;
border-radius:20px;
}

/* PROFILE */
.profile{
text-align:center;
cursor:pointer;
}
.profile img{
width:100px;height:100px;border-radius:50%;
object-fit:cover;
}

/* MODAL */
.modal{
position:fixed;
top:0;left:0;
width:100%;height:100%;
background:rgba(0,0,0,0.6);
display:none;
justify-content:center;
align-items:center;
}
.modal-content{
background:var(--card);
padding:20px;
border-radius:15px;
width:300px;
}

/* INPUT */
input, select{
width:100%;
padding:10px;
margin:10px 0;
border-radius:10px;
border:none;
}

/* BUTTON */
button{
padding:10px;
width:100%;
background:#6366f1;
border:none;
color:white;
border-radius:10px;
cursor:pointer;
}

/* SWITCHES */
.switch{
display:flex;
justify-content:space-between;
align-items:center;
margin:15px 0;
}
</style>
</head>

<body class="<?php echo ($user['theme']=='light')?'light':''; ?>">

<div class="container">

<div class="card">

<!-- PROFILE -->
<div class="profile" onclick="openModal()">
<img id="avatar" src="uploads/<?php echo $user['profile_pic'] ?: 'default.png'; ?>">
<p><?php echo $user['full_name']; ?></p>
</div>

<h3>Settings</h3>

<!-- LANGUAGE -->
<div class="switch">
<span>🌍 Language</span>
<select id="lang">
<option value="en">🇬🇧 English</option>
<option value="sw">🇰🇪 Swahili</option>
</select>
</div>

<!-- THEME -->
<div class="switch">
<span>🎨 Theme</span>
<select id="theme">
<option value="dark">🌙 Dark</option>
<option value="light">☀️ Light</option>
</select>
</div>

<button onclick="saveSettings()">Save Changes</button>

</div>

</div>

<!-- MODAL -->
<div class="modal" id="modal">
<div class="modal-content">

<h3>Edit Profile</h3>

<input type="text" id="name" value="<?php echo $user['full_name']; ?>">
<input type="text" id="phone" value="<?php echo $user['phone_number']; ?>">

<input type="file" id="file">

<button onclick="saveSettings()">Save</button>
<button onclick="closeModal()">Cancel</button>

</div>
</div>

<script>

/* LOAD DEFAULT VALUES */
document.getElementById('theme').value = "<?php echo $user['theme']; ?>";
document.getElementById('lang').value = "<?php echo $user['language']; ?>";

/* MODAL */
function openModal(){
document.getElementById('modal').style.display='flex';
}
function closeModal(){
document.getElementById('modal').style.display='none';
}

/* SAVE */
function saveSettings(){

let formData = new FormData();

formData.append('ajax',1);
formData.append('full_name', document.getElementById('name').value);
formData.append('phone', document.getElementById('phone').value);
formData.append('theme', document.getElementById('theme').value);
formData.append('language', document.getElementById('lang').value);

let file = document.getElementById('file').files[0];
if(file){
    formData.append('profile_pic', file);
}

fetch('settings.php',{
method:'POST',
body:formData
})
.then(res=>res.json())
.then(data=>{
    
    // APPLY THEME LIVE
    if(document.getElementById('theme').value === 'light'){
        document.body.classList.add('light');
    }else{
        document.body.classList.remove('light');
    }

    alert("Saved ✔");
    closeModal();
});
}
</script>

</body>
</html>