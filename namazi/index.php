<?php
/**
 * Connect Namaz — Minimal Dark Tracker
 * Single file. PHP + SQLite. Mobile-first.
 * Tracks: 5 daily prayers, streaks, connections (imam/parents/elders/madrasa/regular namazi), notes.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Riyadh'); // change if needed

// ============ BOOTSTRAP ============
$dbFile = __DIR__ . '/connect_namaz.sqlite';
$isNew  = !file_exists($dbFile);
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
} catch (Throwable $e) {
    http_response_code(500);
    die('DB error: ' . htmlspecialchars($e->getMessage()));
}

if ($isNew) {
    $db->exec("
        CREATE TABLE prayers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL UNIQUE,
            fajr    INTEGER NOT NULL DEFAULT 0,
            dhuhr   INTEGER NOT NULL DEFAULT 0,
            asr     INTEGER NOT NULL DEFAULT 0,
            maghrib INTEGER NOT NULL DEFAULT 0,
            isha    INTEGER NOT NULL DEFAULT 0,
            note    TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL,
            name TEXT NOT NULL,
            role TEXT NOT NULL,
            contact TEXT,
            type TEXT NOT NULL,
            note TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX idx_prayers_date ON prayers(date);
        CREATE INDEX idx_conn_date ON connections(date);
    ");
}

// ============ HELPERS ============
$today   = date('Y-m-d');
$prayers = ['fajr','dhuhr','asr','maghrib','isha'];
$roles   = ['imam'=>'Imam','parent'=>'Parent','elder'=>'Elder','madrasa'=>'Madrasa Student','namazi'=>'Regular Namazi'];
$types   = ['met'=>'Met','talked'=>'Talked','visited'=>'Visited'];

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $to): void { header('Location: ' . $to); exit; }

session_start();
$flash = $_SESSION['_flash'] ?? null;
unset($_SESSION['_flash']);

function flash(string $msg, string $type='ok'): void { $_SESSION['_flash'] = compact('msg','type'); }

function get_prayer_row(PDO $db, string $date): ?array {
    $s = $db->prepare('SELECT * FROM prayers WHERE date=?');
    $s->execute([$date]);
    $r = $s->fetch();
    return $r ?: null;
}

function upsert_prayer(PDO $db, string $date, array $flags, ?string $note): void {
    $sql = 'INSERT INTO prayers(date,fajr,dhuhr,asr,maghrib,isha,note)
            VALUES(?,?,?,?,?,?,?)
            ON CONFLICT(date) DO UPDATE SET
                fajr=excluded.fajr,
                dhuhr=excluded.dhuhr,
                asr=excluded.asr,
                maghrib=excluded.maghrib,
                isha=excluded.isha,
                note=excluded.note';
    $s = $db->prepare($sql);
    $s->execute([
        $date,
        $flags['fajr'],
        $flags['dhuhr'],
        $flags['asr'],
        $flags['maghrib'],
        $flags['isha'],
        $note,
    ]);
}

function count_done(array $row): int {
    return (int)$row['fajr'] + (int)$row['dhuhr'] + (int)$row['asr']
         + (int)$row['maghrib'] + (int)$row['isha'];
}

function calc_streak(PDO $db): array {
    $rows = $db->query('SELECT date,fajr,dhuhr,asr,maghrib,isha FROM prayers ORDER BY date DESC')->fetchAll();
    $streak = 0;
    $check = new DateTime();
    $first = true;
    foreach ($rows as $r) {
        $rd = new DateTime($r['date']);
        if (count_done($r) !== 5) {
            if ($first && $rd->format('Y-m-d') === $check->format('Y-m-d')) {
                // today not complete yet, keep checking yesterday
                $check->modify('-1 day');
                continue;
            }
            break;
        }
        if ($rd->format('Y-m-d') !== $check->format('Y-m-d')) break;
        $streak++;
        $check->modify('-1 day');
        $first = false;
    }
    // best streak overall
    $best = 0; $cur = 0;
    foreach (array_reverse($rows) as $r) {
        if (count_done($r) === 5) { $cur++; if ($cur > $best) $best = $cur; }
        else $cur = 0;
    }
    return ['current' => $streak, 'best' => $best];
}

function month_stats(PDO $db, string $ym): array {
    $s = $db->prepare("SELECT date,fajr,dhuhr,asr,maghrib,isha FROM prayers WHERE date LIKE ?");
    $s->execute([$ym.'-%']);
    $map = [];
    foreach ($s->fetchAll() as $r) $map[$r['date']] = count_done($r);
    return $map;
}

function total_stats(PDO $db): array {
    $r = $db->query('SELECT COUNT(*) d, SUM(fajr+dhuhr+asr+maghrib+isha) p FROM prayers')->fetch();
    $perfect = (int)$db->query('SELECT COUNT(*) FROM prayers WHERE (fajr+dhuhr+asr+maghrib+isha)=5')->fetchColumn();
    $conn    = (int)$db->query('SELECT COUNT(*) FROM connections')->fetchColumn();
    return [
        'days_tracked' => (int)$r['d'],
        'prayers_done' => (int)$r['p'],
        'perfect_days' => $perfect,
        'connections'  => $conn,
    ];
}

// ============ ROUTER ============
$view   = $_GET['view']   ?? 'home';
$action = $_GET['action'] ?? null;

// ---- ACTIONS ----
if ($action === 'save_today' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? $today;
    $flags = [];
    foreach ($prayers as $p) $flags[$p] = isset($_POST[$p]) ? 1 : 0;
    $note = trim($_POST['note'] ?? '');
    upsert_prayer($db, $date, $flags, $note ?: null);
    flash('Saved. Barakallahu feek 🤲');
    redirect('?view=home');
}

if ($action === 'add_connection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $date    = $_POST['date']    ?? $today;
    $name    = trim($_POST['name'] ?? '');
    $role    = $_POST['role']    ?? 'namazi';
    $contact = trim($_POST['contact'] ?? '');
    $type    = $_POST['type']    ?? 'talked';
    $note    = trim($_POST['note'] ?? '');
    if ($name === '') { flash('Name is required','err'); redirect('?view=connections'); }
    $s = $db->prepare('INSERT INTO connections(date,name,role,contact,type,note) VALUES(?,?,?,?,?,?)');
    $s->execute([$date,$name,$role,$contact ?: null,$type,$note ?: null]);
    flash('Connection logged 🤝');
    redirect('?view=connections');
}

if ($action === 'del_conn' && isset($_GET['id'])) {
    $s = $db->prepare('DELETE FROM connections WHERE id=?');
    $s->execute([(int)$_GET['id']]);
    flash('Removed');
    redirect('?view=connections');
}

if ($action === 'export') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="connect-namaz-'.date('Ymd').'.json"');
    echo json_encode([
        'prayers'     => $db->query('SELECT * FROM prayers ORDER BY date')->fetchAll(),
        'connections' => $db->query('SELECT * FROM connections ORDER BY date DESC')->fetchAll(),
        'exported'    => date('c'),
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============ DATA FOR VIEWS ============
$todayRow     = get_prayer_row($db, $today);
$streak       = calc_streak($db);
$stats        = total_stats($db);
$currentMonth = date('Y-m');

// ============ RENDER ============
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0a0a0a">
<title>Connect Namaz</title>
<style>
  :root{
    --bg:#0a0a0a; --surface:#141414; --surface2:#1c1c1c; --line:#262626;
    --text:#e7e7e7; --muted:#8a8a8a; --accent:#10b981; --accent2:#f59e0b;
    --err:#ef4444; --ok:#10b981;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);
    font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,sans-serif;
    -webkit-font-smoothing:antialiased}
  a{color:var(--accent);text-decoration:none}
  .wrap{max-width:560px;margin:0 auto;padding:16px 14px 120px}
  header{display:flex;align-items:center;justify-content:space-between;padding:8px 0 18px}
  header h1{font-size:18px;margin:0;letter-spacing:.3px}
  header h1 span{color:var(--accent)}
  .pill{font-size:11px;padding:4px 10px;border:1px solid var(--line);border-radius:999px;color:var(--muted)}
  .card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px;margin:12px 0}
  .card h2{margin:0 0 10px;font-size:14px;color:var(--muted);font-weight:600;letter-spacing:.6px;text-transform:uppercase}
  .row{display:flex;gap:10px;flex-wrap:wrap}
  .stat{flex:1;min-width:110px;background:var(--surface2);border:1px solid var(--line);border-radius:12px;padding:12px}
  .stat b{display:block;font-size:22px;color:var(--text)}
  .stat small{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px}
  .prayer-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .prayer{display:flex;align-items:center;justify-content:space-between;background:var(--surface2);
    border:1px solid var(--line);border-radius:12px;padding:14px;cursor:pointer;user-select:none;transition:.15s}
  .prayer.done{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.4)}
  .prayer .nm{font-weight:600}
  .prayer .tm{font-size:11px;color:var(--muted)}
  .prayer .ck{width:22px;height:22px;border-radius:50%;border:2px solid var(--line);display:inline-flex;align-items:center;justify-content:center;font-size:13px}
  .prayer.done .ck{background:var(--accent);border-color:var(--accent);color:#000}
  .prayer.full{grid-column:1/-1}
  input[type=text],input[type=tel],textarea,select{
    width:100%;background:var(--surface2);border:1px solid var(--line);color:var(--text);
    border-radius:10px;padding:11px 12px;font:inherit;outline:none}
  textarea{min-height:70px;resize:vertical}
  input:focus,textarea:focus,select:focus{border-color:var(--accent)}
  label{display:block;font-size:12px;color:var(--muted);margin:10px 0 6px;text-transform:uppercase;letter-spacing:.5px}
  button,.btn{background:var(--accent);color:#000;border:0;border-radius:10px;padding:12px 16px;
    font-weight:600;cursor:pointer;font:inherit;width:100%;transition:.15s}
  button:hover{filter:brightness(1.1)}
  .btn-ghost{background:transparent;color:var(--text);border:1px solid var(--line)}
  .btn-row{display:flex;gap:8px;margin-top:10px}
  .btn-row button,.btn-row .btn{flex:1}
  nav.tabbar{position:fixed;bottom:0;left:0;right:0;background:rgba(10,10,10,.95);backdrop-filter:blur(10px);
    border-top:1px solid var(--line);display:flex;justify-content:space-around;padding:8px 4px calc(8px + env(safe-area-inset-bottom));z-index:50}
  nav.tabbar a{flex:1;text-align:center;color:var(--muted);font-size:11px;padding:6px 2px;border-radius:10px}
  nav.tabbar a.active{color:var(--accent)}
  nav.tabbar a .ic{font-size:18px;display:block;margin-bottom:2px}
  .flash{padding:10px 12px;border-radius:10px;margin:8px 0;font-size:13px}
  .flash.ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
  .flash.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
  .cal{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
  .cal .dh{text-align:center;font-size:10px;color:var(--muted);padding:4px 0;text-transform:uppercase}
  .cal .d{aspect-ratio:1;border-radius:8px;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--muted);position:relative}
  .cal .d.lvl-1{background:rgba(16,185,129,.15);color:#a7f3d0}
  .cal .d.lvl-2{background:rgba(16,185,129,.35);color:#ecfdf5}
  .cal .d.lvl-3{background:rgba(16,185,129,.6);color:#022c22;font-weight:700}
  .cal .d.today{outline:2px solid var(--accent)}
  .cal .d.empty{background:transparent}
  .list{display:flex;flex-direction:column;gap:8px}
  .item{background:var(--surface2);border:1px solid var(--line);border-radius:12px;padding:12px;display:flex;gap:10px;align-items:flex-start}
  .item .av{width:36px;height:36px;border-radius:50%;background:var(--surface);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--accent);flex-shrink:0}
  .item .meta{flex:1;min-width:0}
  .item .meta b{display:block}
  .item .meta small{color:var(--muted);font-size:11px}
  .item .x{color:var(--muted);font-size:18px;padding:2px 6px}
  .tag{display:inline-block;font-size:10px;padding:2px 8px;border-radius:999px;background:var(--surface);border:1px solid var(--line);color:var(--muted);margin-right:4px;text-transform:uppercase;letter-spacing:.4px}
  .tag.accent{color:var(--accent);border-color:rgba(16,185,129,.3)}
  .celebrate{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:100;background:rgba(0,0,0,.7);backdrop-filter:blur(6px)}
  .celebrate.show{display:flex;animation:fade .3s}
  .celebrate .box{background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:28px 24px;text-align:center;max-width:320px}
  .celebrate h3{margin:6px 0;font-size:22px}
  .celebrate p{color:var(--muted);margin:6px 0 18px}
  .celebrate .emoji{font-size:44px}
  @keyframes fade{from{opacity:0}to{opacity:1}}
  .progress{height:6px;background:var(--surface2);border-radius:99px;overflow:hidden;margin-top:8px}
  .progress > i{display:block;height:100%;background:var(--accent);width:0;transition:.4s}
  .muted{color:var(--muted);font-size:13px}
  .section-title{display:flex;justify-content:space-between;align-items:center;margin:18px 0 6px}
  .section-title h3{margin:0;font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px}
  @media (min-width:640px){
    .prayer-grid{grid-template-columns:1fr 1fr 1fr}
    .prayer.full{grid-column:auto}
  }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Connect<span>Namaz</span></h1>
    <span class="pill"><?= h(date('D, j M')) ?></span>
  </header>

  <?php if ($flash): ?>
    <div class="flash <?= $flash['type']==='err'?'err':'ok' ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

<?php if ($view === 'home'):
  $done = $todayRow ? count_done($todayRow) : 0;
  $pct  = (int)round($done/5*100);
?>
  <div class="card">
    <h2>Today · <?= h($today) ?></h2>
    <form method="post" action="?action=save_today">
      <input type="hidden" name="date" value="<?= h($today) ?>">
      <div class="prayer-grid">
        <?php
        $times = ['fajr'=>'Before sunrise','dhuhr'=>'Midday','asr'=>'Afternoon','maghrib'=>'Sunset','isha'=>'Night'];
        foreach ($prayers as $i => $p):
          $checked = $todayRow && $todayRow[$p];
        ?>
          <label class="prayer <?= $checked?'done':'' ?> <?= $i===4?'full':'' ?>" onclick="this.classList.toggle('done');this.querySelector('input').checked=this.classList.contains('done');this.querySelector('.ck').textContent=this.classList.contains('done')?'✓':''">
            <input type="checkbox" name="<?= $p ?>" value="1" <?= $checked?'checked':'' ?> style="display:none">
            <div>
              <div class="nm"><?= ucfirst($p) ?></div>
              <div class="tm"><?= $times[$p] ?></div>
            </div>
            <span class="ck"><?= $checked?'✓':'' ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="progress"><i style="width:<?= $pct ?>%"></i></div>
      <div class="muted" style="margin-top:6px"><?= $done ?>/5 · <?= $pct ?>% today</div>

      <label>Daily note (one per day)</label>
      <textarea name="note" placeholder="Reflection, intention, gratitude…"><?= h($todayRow['note'] ?? '') ?></textarea>

      <div class="btn-row">
        <button type="submit">Save</button>
      </div>
    </form>
  </div>

  <div class="row">
    <div class="stat"><b>🔥 <?= $streak['current'] ?></b><small>Current streak</small></div>
    <div class="stat"><b>🏆 <?= $streak['best'] ?></b><small>Best streak</small></div>
    <div class="stat"><b>🕌 <?= $stats['perfect_days'] ?></b><small>Perfect days</small></div>
  </div>

  <?php
    $recentConn = $db->query('SELECT * FROM connections ORDER BY date DESC, id DESC LIMIT 3')->fetchAll();
    if ($recentConn):
  ?>
  <div class="section-title"><h3>Recent connections</h3><a href="?view=connections">See all →</a></div>
  <div class="list">
    <?php foreach ($recentConn as $c): ?>
      <div class="item">
        <div class="av"><?= h(mb_substr($c['name'],0,1)) ?></div>
        <div class="meta">
          <b><?= h($c['name']) ?> <span class="tag accent"><?= h($roles[$c['role']] ?? $c['role']) ?></span></b>
          <small><?= h($c['date']) ?> · <?= h($types[$c['type']] ?? $c['type']) ?>
            <?= $c['contact']?' · '.h($c['contact']):'' ?></small>
          <?php if ($c['note']): ?><div class="muted" style="margin-top:4px"><?= h($c['note']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'calendar'):
  $map = month_stats($db, $currentMonth);
  $first = new DateTime($currentMonth.'-01');
  $last  = new DateTime($first->format('Y-m-t'));
  $startDow = (int)$first->format('w');
  $daysInMonth = (int)$last->format('j');
?>
  <div class="card">
    <h2>Calendar · <?= h(date('F Y', $first->getTimestamp())) ?></h2>
    <div class="cal">
      <?php foreach (['S','M','T','W','T','F','S'] as $dh): ?><div class="dh"><?= $dh ?></div><?php endforeach; ?>
      <?php for ($i=0;$i<$startDow;$i++): ?><div class="d empty"></div><?php endfor; ?>
      <?php for ($d=1;$d<=$daysInMonth;$d++):
        $date = sprintf('%s-%02d',$currentMonth,$d);
        $v = $map[$date] ?? null;
        $lvl = $v===null ? '' : 'lvl-'.(int)ceil($v/2);
        $isToday = $date === $today;
      ?>
        <div class="d <?= $lvl ?> <?= $isToday?'today':'' ?>" title="<?= h($date) ?> · <?= $v ?? 0 ?>/5"><?= $d ?></div>
      <?php endfor; ?>
    </div>
    <div class="muted" style="margin-top:10px;font-size:11px">
      Shade = prayers completed that day (darker = more). Ring = today.
    </div>
  </div>

  <?php
    $mDone=0;$mPerfect=0;$mDays=0;
    foreach ($map as $v){ $mDays++; $mDone+=$v; if($v===5)$mPerfect++; }
  ?>
  <div class="row">
    <div class="stat"><b><?= $mDays ?></b><small>Days logged</small></div>
    <div class="stat"><b><?= $mDone ?></b><small>Prayers this month</small></div>
    <div class="stat"><b><?= $mPerfect ?></b><small>Perfect days</small></div>
  </div>

<?php elseif ($view === 'connections'):
  $all = $db->query('SELECT * FROM connections ORDER BY date DESC, id DESC')->fetchAll();
?>
  <div class="card">
    <h2>Log a connection</h2>
    <form method="post" action="?action=add_connection">
      <label>Name</label>
      <input type="text" name="name" required placeholder="e.g. Imam Yusuf / Ummi">

      <label>Role</label>
      <select name="role">
        <?php foreach ($roles as $k=>$v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?>
      </select>

      <label>Contact (optional)</label>
      <input type="tel" name="contact" placeholder="phone / username">

      <label>How did you connect?</label>
      <select name="type">
        <?php foreach ($types as $k=>$v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?>
      </select>

      <label>Date</label>
      <input type="text" name="date" value="<?= h($today) ?>" placeholder="YYYY-MM-DD">

      <label>Note</label>
      <textarea name="note" placeholder="Topic, dua, advice…"></textarea>

      <div class="btn-row"><button type="submit">Save connection</button></div>
    </form>
  </div>

  <div class="section-title"><h3>All (<?= count($all) ?>)</h3></div>
  <?php if (!$all): ?>
    <div class="card muted">No connections yet. Start by logging one above.</div>
  <?php else: ?>
    <div class="list">
      <?php foreach ($all as $c): ?>
        <div class="item">
          <div class="av"><?= h(mb_substr($c['name'],0,1)) ?></div>
          <div class="meta">
            <b><?= h($c['name']) ?>
              <span class="tag accent"><?= h($roles[$c['role']] ?? $c['role']) ?></span>
              <span class="tag"><?= h($types[$c['type']] ?? $c['type']) ?></span>
            </b>
            <small><?= h($c['date']) ?> <?= $c['contact']?' · '.h($c['contact']):'' ?></small>
            <?php if ($c['note']): ?><div class="muted" style="margin-top:4px"><?= h($c['note']) ?></div><?php endif; ?>
          </div>
          <a class="x" href="?view=connections&action=del_conn&id=<?= (int)$c['id'] ?>" onclick="return confirm('Delete?')">✕</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'report'): ?>
  <div class="card">
    <h2>Lifetime</h2>
    <div class="row">
      <div class="stat"><b><?= $stats['days_tracked'] ?></b><small>Days tracked</small></div>
      <div class="stat"><b><?= $stats['prayers_done'] ?></b><small>Prayers done</small></div>
      <div class="stat"><b><?= $stats['perfect_days'] ?></b><small>Perfect days</small></div>
      <div class="stat"><b><?= $stats['connections'] ?></b><small>Connections</small></div>
    </div>
  </div>

  <div class="card">
    <h2>Streaks</h2>
    <div class="row">
      <div class="stat"><b>🔥 <?= $streak['current'] ?></b><small>Current</small></div>
      <div class="stat"><b>🏆 <?= $streak['best'] ?></b><small>Best ever</small></div>
    </div>
    <div class="muted" style="margin-top:10px;font-size:12px">
      Milestones to aim for: 7 · 21 · 40 · 100 · 365.
    </div>
  </div>

  <div class="card">
    <h2>Per-prayer completion</h2>
    <?php
      $totals = $db->query('SELECT SUM(fajr) f,SUM(dhuhr) d,SUM(asr) a,SUM(maghrib) m,SUM(isha) i FROM prayers')->fetch();
      $den = max(1,$stats['days_tracked']);
      $keys = ['f','d','a','m','i'];
      foreach ($prayers as $i => $p):
        $v = (int)$totals[$keys[$i]];
        $pct = (int)round($v/$den*100);
    ?>
      <div style="margin:8px 0">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span><?= ucfirst($p) ?></span><span class="muted"><?= $v ?>/<?= $den ?> · <?= $pct ?>%</span>
        </div>
        <div class="progress"><i style="width:<?= $pct ?>%"></i></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>Connections by role</h2>
    <?php foreach ($roles as $k=>$v):
      $n = $db->prepare('SELECT COUNT(*) FROM connections WHERE role=?'); $n->execute([$k]); $n=$n->fetchColumn();
    ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);font-size:13px">
        <span><?= h($v) ?></span><span class="muted"><?= $n ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="btn-row">
    <a class="btn btn-ghost" href="?action=export" style="text-align:center;text-decoration:none">⬇ Export JSON</a>
  </div>

<?php elseif ($view === 'why'): ?>
  <div class="card">
    <h2>Why track Namaz?</h2>
    <p class="muted">Prayer is the second pillar of Islam — the first thing we will be asked about on the Day of Judgement. Tracking it is not about perfection; it is about <b>awareness, consistency, and returning when we slip</b>.</p>
    <ul class="muted" style="padding-left:18px">
      <li>Makes the invisible visible — you cannot improve what you do not measure.</li>
      <li>Builds the habit loop: cue → prayer → reward (streak, peace).</li>
      <li>Turns a private act into a connected life — with your imam, parents, elders, students, and righteous friends.</li>
      <li>Gives you data to reflect on, not guilt to carry.</li>
    </ul>
  </div>
  <div class="card">
    <h2>Why "Connect"?</h2>
    <p class="muted">Salah connects you to Allah. This app adds a second layer: <b>connecting you to the people who carry the deen with you</b>. Visit an elder. Call a parent. Sit with the imam after Fajr. Check on a madrasa student. These ties are sadaqah, they are silaturrahim, and they keep you on the path when motivation fades.</p>
  </div>
  <div class="card">
    <h2>How to use</h2>
    <ol class="muted" style="padding-left:18px">
      <li>Open the app 5 times a day. Tap each prayer when done.</li>
      <li>Write one short note — a dua, a reflection, a reminder.</li>
      <li>At least once a week, log a connection: met / talked / visited.</li>
      <li>Review the calendar each week. Protect the streak, but never despair — restart the same day.</li>
    </ol>
  </div>
<?php endif; ?>
</div>

<nav class="tabbar">
  <a href="?view=home" class="<?= $view==='home'?'active':'' ?>"><span class="ic">🕌</span>Today</a>
  <a href="?view=calendar" class="<?= $view==='calendar'?'active':'' ?>"><span class="ic">📅</span>Calendar</a>
  <a href="?view=connections" class="<?= $view==='connections'?'active':'' ?>"><span class="ic">🤝</span>Connect</a>
  <a href="?view=report" class="<?= $view==='report'?'active':'' ?>"><span class="ic">📊</span>Report</a>
  <a href="?view=why" class="<?= $view==='why'?'active':'' ?>"><span class="ic">💡</span>Why</a>
</nav>

<?php
$milestones = [3,7,14,21,30,40,60,100,200,365];
$showCelebrate = $todayRow && count_done($todayRow)===5 && in_array($streak['current'], $milestones, true);
if ($showCelebrate):
  $m = $streak['current'];
  $msg = $m>=365 ? "A full year of Salah. You are among the steadfast."
       : ($m>=100 ? "100 days connected. This is now who you are."
       : ($m>=40   ? "40 days — the age of prophethood. Your heart is changing."
       : ($m>=30   ? "30 days. The habit is forming."
       : "$m days. Keep going.")));
?>
<div class="celebrate show" id="cel">
  <div class="box">
    <div class="emoji">🌙</div>
    <h3><?= $m ?>-day streak</h3>
    <p><?= h($msg) ?></p>
    <button onclick="document.getElementById('cel').classList.remove('show')">Alhamdulillah</button>
  </div>
</div>
<?php endif; ?>

</body>
</html>