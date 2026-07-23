<?php
$ports=[];
foreach([[3000,3010],[5000,5010],[5173,5180],[8000,8010],[8080,8090],[9000,9010]] as $r){
 for($p=$r[0];$p<=$r[1];$p++){
  $c=@fsockopen("127.0.0.1",$p,$e,$s,0.05);
  $used=(bool)$c;
  if($c) fclose($c);
  $ports[]=["port"=>$p,"used"=>$used];
 }
}
$free=array_values(array_filter($ports,fn($x)=>!$x["used"]));
$rec=$free[0]["port"]??"None";
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Port</title><style>
body{margin:0;background:#000;color:#fff;font:16px system-ui}
.wrap{max-width:600px;margin:auto;padding:16px}
.card{background:#111;border:1px solid #222;border-radius:16px;padding:16px;margin:16px 0}
h1,h2{margin:.2em 0}.pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#1f1}
.port{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #222}
button{width:100%;padding:12px;border:0;border-radius:12px}
</style></head><body><div class=wrap>
<h1>Port</h1>
<div class=card><h2>Recommended</h2><div style="font-size:42px"><?=$rec?></div><button onclick="navigator.clipboard.writeText('<?=$rec?>')">Copy</button></div>
<div class=card><h2>Used Ports</h2>
<?php foreach($ports as $p) if($p["used"]){?><div class=port><span><?=$p["port"]?></span><span>🟢</span></div><?php }?>
</div>
<div class=card><h2>Free Ports</h2>
<?php foreach($ports as $p) if(!$p["used"]){?><div class=port><span><?=$p["port"]?></span><span>⚪</span></div><?php }?>
</div>
<div class=card><button onclick="location.reload()">Refresh</button></div>
</div></body></html>