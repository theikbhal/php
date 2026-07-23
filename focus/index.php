<?php

$db = new SQLite3(__DIR__ . "/focus.db");

$db->exec("
CREATE TABLE IF NOT EXISTS focus_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    focus TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $focus = trim($_POST['focus'] ?? '');

    if ($focus !== '') {

        $stmt = $db->prepare("INSERT INTO focus_history(focus) VALUES(:focus)");
        $stmt->bindValue(':focus', $focus, SQLITE3_TEXT);
        $stmt->execute();

        header("Location: ?");
        exit;
    }
}

$current = $db->querySingle("
SELECT focus
FROM focus_history
ORDER BY id DESC
LIMIT 1
");

$history = $db->query("
SELECT *
FROM focus_history
ORDER BY id DESC
");

function timeAgo($datetime)
{
    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . " min ago";
    if ($diff < 86400) return floor($diff / 3600) . " hr ago";
    if ($diff < 604800) return floor($diff / 86400) . " day ago";

    return date("M d, Y", $time);
}

$celebrate = $_SERVER['REQUEST_METHOD'] === 'POST';

?>
<!doctype html>
<html>
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Focus</title>

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}

body{
background:#000;
color:#fff;
}

.container{
max-width:600px;
margin:auto;
}

.header{
padding:18px;
font-size:22px;
font-weight:700;
border-bottom:1px solid #222;
position:sticky;
top:0;
background:#000;
}

.card{
padding:20px;
border-bottom:1px solid #222;
}

.label{
font-size:13px;
color:#888;
margin-bottom:8px;
}

.current{
font-size:30px;
font-weight:700;
line-height:1.4;
word-break:break-word;
}

textarea{
width:100%;
margin-top:18px;
background:#111;
color:white;
border:1px solid #333;
border-radius:16px;
padding:16px;
font-size:18px;
resize:none;
height:90px;
outline:none;
}

button{
margin-top:12px;
width:100%;
background:white;
color:black;
border:none;
padding:15px;
font-size:18px;
font-weight:bold;
border-radius:16px;
cursor:pointer;
}

button:hover{
opacity:.9;
}

.historyTitle{
padding:18px;
font-weight:bold;
font-size:18px;
border-bottom:1px solid #222;
}

.post{
padding:18px;
border-bottom:1px solid #222;
}

.postText{
font-size:19px;
line-height:1.5;
white-space:pre-wrap;
word-break:break-word;
}

.postTime{
margin-top:10px;
font-size:13px;
color:#777;
}

.empty{
padding:50px 20px;
text-align:center;
color:#666;
}

.toast{
position:fixed;
top:25px;
left:50%;
transform:translateX(-50%);
background:#00d26a;
color:#000;
padding:14px 24px;
border-radius:999px;
font-weight:bold;
animation:toast 2.2s forwards;
z-index:999;
}

@keyframes toast{

0%{
opacity:0;
transform:translateX(-50%) scale(.6);
}

15%{
opacity:1;
transform:translateX(-50%) scale(1.1);
}

80%{
opacity:1;
}

100%{
opacity:0;
transform:translateX(-50%) translateY(-40px);
}

}

.footer{
padding:25px;
text-align:center;
color:#666;
font-size:13px;
}

</style>

</head>

<body>

<?php if(isset($_GET['saved'])): ?>
<div class="toast">
🎉 Focus Updated!
</div>
<?php endif; ?>

<div class="container">

<div class="header">
🎯 Focus
</div>

<div class="card">

<div class="label">
CURRENT FOCUS
</div>

<div class="current">
<?= $current ? htmlspecialchars($current) : "No focus yet." ?>
</div>

<form method="post">

<textarea
name="focus"
placeholder="What are you focusing on?"
required></textarea>

<button>
Set Focus 🚀
</button>

</form>

</div>

<div class="historyTitle">
History
</div>

<?php

$count = 0;

while($row = $history->fetchArray(SQLITE3_ASSOC))
{
$count++;
?>

<div class="post">

<div class="postText">
<?= htmlspecialchars($row['focus']) ?>
</div>

<div class="postTime">
<?= timeAgo($row['created_at']) ?>
</div>

</div>

<?php
}

if($count==0){
?>

<div class="empty">
No focus history yet.
</div>

<?php
}
?>

<div class="footer">
Built with PHP + SQLite
</div>

</div>

<script>

const form=document.querySelector("form");

form.addEventListener("submit",function(){

const div=document.createElement("div");

div.className="toast";

div.innerHTML="🎉 Great! Stay focused.";

document.body.appendChild(div);

});

</script>

</body>
</html>