<?php
/**
 * PHP Site Manager — single-file edition
 * Everything (SQLite bootstrap, JSON API, and UI) lives in this one index.php.
 *
 * Run:   php -S 127.0.0.1:8888
 * Open:  http://127.0.0.1:8888
 */

// ============================================================================
// 1. DATABASE
// ============================================================================

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    $pdo = new PDO('sqlite:' . $dataDir . '/sites.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sites (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            path        TEXT NOT NULL,
            port        INTEGER,
            description TEXT DEFAULT '',
            pid         INTEGER,
            created_at  TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");

    return $pdo;
}

// ============================================================================
// 2. CORE LOGIC — ports, process control, folder scanning
// ============================================================================

const PORT_RANGE_START = 8000;
const PORT_RANGE_END   = 8999;

function isPortFree(int $port): bool
{
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
    if ($conn) {
        fclose($conn);
        return false;
    }
    return true;
}

function findFreePort(int $start = PORT_RANGE_START, int $end = PORT_RANGE_END): ?int
{
    for ($p = $start; $p <= $end; $p++) {
        if (isPortFree($p)) {
            return $p;
        }
    }
    return null;
}

function isPidRunning(?int $pid): bool
{
    if (!$pid) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0);
    }
    exec('ps -p ' . intval($pid), $out);
    return count($out) > 1;
}

function getSiteStatus(array $site): string
{
    $pid = $site['pid'] ?? null;
    if ($pid && isPidRunning((int)$pid)) {
        return 'running';
    }
    return 'stopped';
}

function startSite(int $id): array
{
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$id]);
    $site = $stmt->fetch();

    if (!$site) {
        return ['ok' => false, 'error' => 'Site not found'];
    }
    if (getSiteStatus($site) === 'running') {
        return ['ok' => true, 'message' => 'Already running', 'port' => (int)$site['port']];
    }
    if (!is_dir($site['path'])) {
        return ['ok' => false, 'error' => 'Project folder no longer exists: ' . $site['path']];
    }

    $port = (int)($site['port'] ?? 0);
    if ($port <= 0 || !isPortFree($port)) {
        $port = findFreePort();
        if (!$port) {
            return ['ok' => false, 'error' => 'No free port available in range ' . PORT_RANGE_START . '-' . PORT_RANGE_END];
        }
    }

    $path = escapeshellarg($site['path']);
    $cmd  = "nohup php -S 127.0.0.1:{$port} -t {$path} > /dev/null 2>&1 & echo $!";
    $pid  = (int) trim(shell_exec($cmd));

    usleep(300000); // give the server a moment to bind

    if (!$pid || !isPidRunning($pid)) {
        return ['ok' => false, 'error' => 'Failed to start server process'];
    }

    $update = $pdo->prepare('UPDATE sites SET port = ?, pid = ? WHERE id = ?');
    $update->execute([$port, $pid, $id]);

    return ['ok' => true, 'port' => $port, 'pid' => $pid];
}

function stopSite(int $id): array
{
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$id]);
    $site = $stmt->fetch();

    if (!$site) {
        return ['ok' => false, 'error' => 'Site not found'];
    }

    $pid = (int)($site['pid'] ?? 0);
    if ($pid && isPidRunning($pid)) {
        if (function_exists('posix_kill')) {
            posix_kill($pid, 15); // SIGTERM
            usleep(200000);
            if (isPidRunning($pid)) {
                posix_kill($pid, 9); // SIGKILL fallback
            }
        } else {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    }

    $update = $pdo->prepare('UPDATE sites SET pid = NULL WHERE id = ?');
    $update->execute([$id]);

    return ['ok' => true];
}

function scanFolder(string $basePath): array
{
    $results = [];
    if (!is_dir($basePath)) {
        return $results;
    }

    $entries = scandir($basePath);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = rtrim($basePath, '/') . '/' . $entry;
        if (!is_dir($full)) {
            continue;
        }
        $hasIndex    = file_exists($full . '/index.php');
        $hasComposer = file_exists($full . '/composer.json');
        $hasHtaccess = file_exists($full . '/.htaccess');
        if ($hasIndex || $hasComposer || $hasHtaccess) {
            $results[] = [
                'name' => $entry,
                'path' => $full,
                'hint' => $hasIndex ? 'index.php' : ($hasComposer ? 'composer.json' : '.htaccess'),
            ];
        }
    }

    return $results;
}

// ============================================================================
// 3. JSON API — handled right here in index.php via ?action=...
//    Any request with an "action" param is treated as an API call and
//    returns JSON instead of the HTML page.
// ============================================================================

if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $pdo    = getDB();
    $action = $_REQUEST['action'];

    try {
        switch ($action) {

            case 'list': {
                $q = trim($_GET['q'] ?? '');
                if ($q !== '') {
                    $stmt = $pdo->prepare("SELECT * FROM sites WHERE name LIKE ? OR path LIKE ? OR description LIKE ? ORDER BY name COLLATE NOCASE");
                    $like = '%' . $q . '%';
                    $stmt->execute([$like, $like, $like]);
                } else {
                    $stmt = $pdo->query("SELECT * FROM sites ORDER BY name COLLATE NOCASE");
                }
                $sites = $stmt->fetchAll();
                foreach ($sites as &$s) {
                    $s['status'] = getSiteStatus($s);
                }
                echo json_encode(['ok' => true, 'sites' => $sites]);
                break;
            }

            case 'add': {
                $name = trim($_POST['name'] ?? '');
                $path = trim($_POST['path'] ?? '');
                $port = trim($_POST['port'] ?? '');
                $desc = trim($_POST['description'] ?? '');

                if ($name === '' || $path === '') {
                    echo json_encode(['ok' => false, 'error' => 'Name and folder path are required']);
                    break;
                }
                if (!is_dir($path)) {
                    echo json_encode(['ok' => false, 'error' => 'That folder does not exist on this machine']);
                    break;
                }

                $port = $port !== '' ? (int)$port : findFreePort();

                $stmt = $pdo->prepare('INSERT INTO sites (name, path, port, description) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $path, $port, $desc]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
                break;
            }

            case 'edit': {
                $id   = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $path = trim($_POST['path'] ?? '');
                $port = trim($_POST['port'] ?? '');
                $desc = trim($_POST['description'] ?? '');

                if (!$id || $name === '' || $path === '') {
                    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
                    break;
                }
                if (!is_dir($path)) {
                    echo json_encode(['ok' => false, 'error' => 'That folder does not exist on this machine']);
                    break;
                }

                $port = $port !== '' ? (int)$port : null;
                $stmt = $pdo->prepare('UPDATE sites SET name = ?, path = ?, port = ?, description = ? WHERE id = ?');
                $stmt->execute([$name, $path, $port, $desc, $id]);
                echo json_encode(['ok' => true]);
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    echo json_encode(['ok' => false, 'error' => 'Missing id']);
                    break;
                }
                stopSite($id);
                $stmt = $pdo->prepare('DELETE FROM sites WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['ok' => true]);
                break;
            }

            case 'start': {
                $id = (int)($_POST['id'] ?? 0);
                echo json_encode(startSite($id));
                break;
            }

            case 'stop': {
                $id = (int)($_POST['id'] ?? 0);
                echo json_encode(stopSite($id));
                break;
            }

            case 'status': {
                $id   = (int)($_REQUEST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
                $stmt->execute([$id]);
                $site = $stmt->fetch();
                if (!$site) {
                    echo json_encode(['ok' => false, 'error' => 'Site not found']);
                    break;
                }
                echo json_encode(['ok' => true, 'status' => getSiteStatus($site), 'port' => (int)$site['port']]);
                break;
            }

            case 'scan': {
                $folder = trim($_REQUEST['folder'] ?? '');
                if ($folder === '' || !is_dir($folder)) {
                    echo json_encode(['ok' => false, 'error' => 'Enter a valid folder path on this machine']);
                    break;
                }
                echo json_encode(['ok' => true, 'results' => scanFolder($folder)]);
                break;
            }

            case 'find_port': {
                $port = findFreePort();
                echo json_encode($port ? ['ok' => true, 'port' => $port] : ['ok' => false, 'error' => 'No free port found']);
                break;
            }

            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }

    exit; // stop here for API requests — don't fall through to HTML below
}

// ============================================================================
// 4. HTML PAGE (only reached when no ?action= is present)
// ============================================================================
getDB(); // make sure schema exists before first render
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PHP Site Manager</title>
<link rel="icon" href="data:,">
<style>
:root{
  --bg:        #12110f;
  --bg-alt:    #1a1815;
  --card:      #1d1b17;
  --card-hi:   #242119;
  --border:    #322e26;
  --text:      #eae6dd;
  --text-dim:  #9a9284;
  --text-mute: #6b6459;
  --amber:     #e3a23c;
  --amber-dim: #a97a30;
  --green:     #6fbf7a;
  --red:       #d9695f;
  --mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  --sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --radius: 6px;
}
*{ box-sizing:border-box; }
html,body{ margin:0; padding:0; background:var(--bg); color:var(--text); font-family:var(--sans); font-size:14px; line-height:1.5; }
::selection{ background:var(--amber); color:#12110f; }
a{ color:var(--amber); text-decoration:none; }

.wrap{ max-width:980px; margin:0 auto; padding:32px 20px 80px; }

header.top{ display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:28px; border-bottom:1px solid var(--border); padding-bottom:18px; }
header.top .brand{ display:flex; align-items:center; gap:10px; }
header.top .brand .dot{ width:9px; height:9px; border-radius:50%; background:var(--amber); box-shadow:0 0 8px var(--amber); }
header.top h1{ font-size:17px; font-weight:600; margin:0; letter-spacing:0.2px; }
header.top .sub{ font-family:var(--mono); font-size:11.5px; color:var(--text-mute); margin-top:2px; }
header.top .stats{ font-family:var(--mono); font-size:12px; color:var(--text-dim); text-align:right; }
header.top .stats b{ color:var(--green); font-weight:600; }

.toolbar{ display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
.search{ flex:1; min-width:200px; position:relative; }
.search input{ width:100%; padding-left:32px; }
.search .icon{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-mute); pointer-events:none; }

input[type=text], input[type=number], textarea{
  background:var(--bg-alt); border:1px solid var(--border); color:var(--text);
  border-radius:var(--radius); padding:9px 12px; font-family:var(--sans); font-size:13.5px;
  outline:none; transition:border-color .15s;
}
input[type=text]:focus, input[type=number]:focus, textarea:focus{ border-color:var(--amber-dim); }
input.mono, textarea.mono{ font-family:var(--mono); font-size:13px; }
input::placeholder, textarea::placeholder{ color:var(--text-mute); }

.btn{
  display:inline-flex; align-items:center; gap:6px; background:var(--bg-alt); border:1px solid var(--border);
  color:var(--text); padding:9px 14px; border-radius:var(--radius); font-size:13px; font-weight:500;
  cursor:pointer; font-family:var(--sans); white-space:nowrap; transition:border-color .15s, background .15s, transform .05s;
}
.btn:hover{ border-color:var(--amber-dim); background:var(--card-hi); }
.btn:active{ transform:translateY(1px); }
.btn-primary{ background:var(--amber); border-color:var(--amber); color:#1a1409; font-weight:600; }
.btn-primary:hover{ background:#eeb055; border-color:#eeb055; }
.btn-ghost{ background:transparent; }
.btn-danger{ color:var(--red); }
.btn-danger:hover{ border-color:var(--red); }
.btn-sm{ padding:6px 10px; font-size:12px; }
.btn:disabled{ opacity:.45; cursor:not-allowed; }

.grid{ display:flex; flex-direction:column; gap:10px; }
.card{
  background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:16px 18px;
  display:grid; grid-template-columns:1fr auto; gap:14px; align-items:center; transition:border-color .15s;
}
.card:hover{ border-color:#453f31; }
.card .main{ min-width:0; }
.card .title-row{ display:flex; align-items:center; gap:10px; margin-bottom:5px; }
.status-dot{ width:8px; height:8px; border-radius:50%; flex:none; background:var(--text-mute); }
.status-dot.running{ background:var(--green); box-shadow:0 0 6px var(--green); }
.status-dot.stopped{ background:var(--text-mute); }
.card h3{ font-size:14.5px; font-weight:600; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.badge{ font-family:var(--mono); font-size:11px; padding:2px 7px; border-radius:4px; background:var(--bg-alt); border:1px solid var(--border); color:var(--text-dim); flex:none; }
.badge.port{ color:var(--amber); border-color:var(--amber-dim); }
.card .path{ font-family:var(--mono); font-size:12px; color:var(--text-mute); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:4px; }
.card .desc{ font-size:12.5px; color:var(--text-dim); overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; }
.card .actions{ display:flex; gap:6px; flex:none; }

.empty{ text-align:center; padding:60px 20px; color:var(--text-mute); border:1px dashed var(--border); border-radius:var(--radius); }
.empty .big{ font-family:var(--mono); font-size:28px; color:var(--text-mute); margin-bottom:10px; }

.overlay{ position:fixed; inset:0; background:rgba(10,9,7,.72); display:none; align-items:flex-start; justify-content:center; padding:60px 16px; z-index:50; overflow-y:auto; }
.overlay.open{ display:flex; }
.modal{ background:var(--card); border:1px solid var(--border); border-radius:8px; width:100%; max-width:480px; padding:22px; }
.modal h2{ font-size:15px; margin:0 0 16px; display:flex; align-items:center; justify-content:space-between; }
.modal h2 .close{ cursor:pointer; color:var(--text-mute); font-family:var(--mono); font-size:16px; }
.modal h2 .close:hover{ color:var(--text); }

.field{ margin-bottom:14px; }
.field label{ display:block; font-size:12px; color:var(--text-dim); margin-bottom:6px; }
.field input, .field textarea{ width:100%; }
.field .hint{ font-size:11px; color:var(--text-mute); margin-top:5px; }
.field-row{ display:flex; gap:10px; }
.field-row .field{ flex:1; }

.modal-actions{ display:flex; justify-content:flex-end; gap:8px; margin-top:18px; padding-top:16px; border-top:1px solid var(--border); }

.scan-results{ max-height:200px; overflow-y:auto; border:1px solid var(--border); border-radius:var(--radius); margin-top:8px; }
.scan-item{ display:flex; justify-content:space-between; align-items:center; padding:8px 10px; font-size:12.5px; border-bottom:1px solid var(--border); }
.scan-item:last-child{ border-bottom:none; }
.scan-item .name{ font-weight:600; }
.scan-item .p{ font-family:var(--mono); color:var(--text-mute); font-size:11px; }

#toast{
  position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px);
  background:var(--card-hi); border:1px solid var(--border); color:var(--text); padding:10px 16px;
  border-radius:var(--radius); font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; z-index:100;
}
#toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
#toast.err{ border-color:var(--red); color:var(--red); }
#toast.ok{ border-color:var(--green); color:var(--green); }

footer.foot{ margin-top:40px; text-align:center; color:var(--text-mute); font-size:11.5px; font-family:var(--mono); }

@media (max-width: 640px){
  .card{ grid-template-columns:1fr; }
  .card .actions{ justify-content:flex-start; }
  header.top{ flex-direction:column; align-items:flex-start; }
  header.top .stats{ text-align:left; }
}
</style>
</head>
<body>

<div class="wrap">

  <header class="top">
    <div>
      <div class="brand">
        <span class="dot"></span>
        <h1>PHP Site Manager</h1>
      </div>
      <div class="sub">local projects · sqlite · php -S</div>
    </div>
    <div class="stats" id="stats">— running / — total</div>
  </header>

  <div class="toolbar">
    <div class="search">
      <span class="icon">⌕</span>
      <input type="text" id="search" placeholder="Search by name, path, or description…">
    </div>
    <button class="btn" id="btnScan">Scan folder</button>
    <button class="btn btn-primary" id="btnAdd">+ Add site</button>
  </div>

  <div class="grid" id="grid">
    <div class="empty">Loading…</div>
  </div>

  <footer class="foot">served from <?= htmlspecialchars(__DIR__) ?></footer>
</div>

<!-- Add / Edit modal -->
<div class="overlay" id="siteOverlay">
  <div class="modal">
    <h2><span id="modalTitle">Add site</span><span class="close" data-close>✕</span></h2>
    <form id="siteForm">
      <div class="field">
        <label for="f_name">Name</label>
        <input type="text" id="f_name" placeholder="My project" required>
      </div>
      <div class="field">
        <label for="f_path">Folder path</label>
        <input type="text" class="mono" id="f_path" placeholder="/home/user/projects/my-project" required>
        <div class="hint">Absolute path to the project's document root on this machine.</div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="f_port">Port</label>
          <input type="number" class="mono" id="f_port" placeholder="auto" min="1" max="65535">
        </div>
        <div class="field" style="flex:none; align-self:flex-end;">
          <button type="button" class="btn btn-sm" id="btnFindPort">Find free port</button>
        </div>
      </div>
      <div class="field">
        <label for="f_desc">Description</label>
        <textarea id="f_desc" rows="2" placeholder="What is this project? (optional)"></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save site</button>
      </div>
    </form>
  </div>
</div>

<!-- Scan folder modal -->
<div class="overlay" id="scanOverlay">
  <div class="modal">
    <h2>Scan folder<span class="close" data-close>✕</span></h2>
    <form id="scanForm">
      <div class="field">
        <label for="f_folder">Base folder</label>
        <input type="text" class="mono" id="f_folder" placeholder="/home/user/projects" required>
        <div class="hint">Looks for subfolders containing <code>index.php</code>, <code>composer.json</code>, or <code>.htaccess</code>.</div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close>Close</button>
        <button type="submit" class="btn btn-primary">Scan</button>
      </div>
    </form>
    <div class="scan-results" id="scanResults"></div>
  </div>
</div>

<div id="toast"></div>

<script>
/* app.js logic, inlined — talks back to this same index.php via ?action=... */

const $  = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];

let editingId = null;
let pollTimer = null;

document.addEventListener('DOMContentLoaded', () => {
  loadSites();
  bindUI();
  pollTimer = setInterval(() => loadSites($('#search').value.trim(), true), 5000);
});

function bindUI() {
  $('#search').addEventListener('input', debounce(() => loadSites($('#search').value.trim()), 250));

  $('#btnAdd').addEventListener('click', () => openSiteModal());
  $('#btnScan').addEventListener('click', () => openScanModal());

  $$('.overlay').forEach(ov => {
    ov.addEventListener('click', (e) => { if (e.target === ov) closeModals(); });
  });
  $$('[data-close]').forEach(el => el.addEventListener('click', closeModals));

  $('#siteForm').addEventListener('submit', onSaveSite);
  $('#btnFindPort').addEventListener('click', onFindPort);
  $('#scanForm').addEventListener('submit', onScanFolder);
}

function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

/* ---------------- API helpers (self: index.php?action=...) ---------------- */

async function api(action, params = {}, method = 'GET') {
  let res;
  if (method === 'GET') {
    const qs = new URLSearchParams({ action, ...params }).toString();
    res = await fetch(`index.php?${qs}`);
  } else {
    const body = new URLSearchParams({ action, ...params });
    res = await fetch('index.php', { method: 'POST', body });
  }
  return res.json();
}

/* ---------------- Rendering ---------------- */

async function loadSites(q = '', silent = false) {
  const data = await api('list', q ? { q } : {});
  if (!data.ok) return;
  renderSites(data.sites);
  updateStats(data.sites);
}

function updateStats(sites) {
  const running = sites.filter(s => s.status === 'running').length;
  $('#stats').innerHTML = `<b>${running}</b> running &nbsp;/&nbsp; ${sites.length} total`;
}

function renderSites(sites) {
  const grid = $('#grid');
  if (!sites.length) {
    grid.innerHTML = `
      <div class="empty">
        <div class="big">∅</div>
        No sites tracked yet.<br>Add one, or scan a folder to auto-detect projects.
      </div>`;
    return;
  }

  grid.innerHTML = sites.map(s => {
    const running = s.status === 'running';
    const url = `http://127.0.0.1:${s.port}/`;
    return `
    <div class="card" data-id="${s.id}">
      <div class="main">
        <div class="title-row">
          <span class="status-dot ${s.status}" title="${s.status}"></span>
          <h3>${escapeHtml(s.name)}</h3>
          <span class="badge port">:${s.port ?? '—'}</span>
          <span class="badge">${s.status}</span>
        </div>
        <div class="path">${escapeHtml(s.path)}</div>
        ${s.description ? `<div class="desc">${escapeHtml(s.description)}</div>` : ''}
      </div>
      <div class="actions">
        ${running
          ? `<a class="btn btn-sm" href="${url}" target="_blank" rel="noopener">Open</a>
             <button class="btn btn-sm" data-act="stop" data-id="${s.id}">Stop</button>`
          : `<button class="btn btn-sm btn-primary" data-act="start" data-id="${s.id}">Start</button>`
        }
        <button class="btn btn-sm btn-ghost" data-act="edit" data-id="${s.id}">Edit</button>
        <button class="btn btn-sm btn-ghost btn-danger" data-act="delete" data-id="${s.id}">Delete</button>
      </div>
    </div>`;
  }).join('');

  $$('.actions [data-act]', grid).forEach(btn => {
    btn.addEventListener('click', onCardAction);
  });
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

/* ---------------- Card actions ---------------- */

async function onCardAction(e) {
  const btn = e.currentTarget;
  const id = btn.dataset.id;
  const act = btn.dataset.act;

  if (act === 'edit') return openSiteModal(id);

  if (act === 'delete') {
    if (!confirm('Delete this site from tracking? (Files on disk are not touched.)')) return;
    btn.disabled = true;
    const r = await api('delete', { id }, 'POST');
    toast(r.ok ? 'Site removed' : (r.error || 'Failed to delete'), r.ok ? 'ok' : 'err');
    return loadSites($('#search').value.trim());
  }

  if (act === 'start') {
    btn.disabled = true; btn.textContent = 'Starting…';
    const r = await api('start', { id }, 'POST');
    toast(r.ok ? `Running on port ${r.port}` : (r.error || 'Failed to start'), r.ok ? 'ok' : 'err');
    return loadSites($('#search').value.trim());
  }

  if (act === 'stop') {
    btn.disabled = true; btn.textContent = 'Stopping…';
    const r = await api('stop', { id }, 'POST');
    toast(r.ok ? 'Stopped' : (r.error || 'Failed to stop'), r.ok ? 'ok' : 'err');
    return loadSites($('#search').value.trim());
  }
}

/* ---------------- Add / Edit modal ---------------- */

function openSiteModal(id = null) {
  editingId = id;
  const form = $('#siteForm');
  form.reset();

  if (id) {
    $('#modalTitle').textContent = 'Edit site';
    api('list').then(data => {
      const site = data.sites.find(s => String(s.id) === String(id));
      if (!site) return;
      $('#f_name').value = site.name;
      $('#f_path').value = site.path;
      $('#f_port').value = site.port ?? '';
      $('#f_desc').value = site.description ?? '';
    });
  } else {
    $('#modalTitle').textContent = 'Add site';
  }

  $('#siteOverlay').classList.add('open');
  $('#f_name').focus();
}

async function onSaveSite(e) {
  e.preventDefault();
  const params = {
    name: $('#f_name').value.trim(),
    path: $('#f_path').value.trim(),
    port: $('#f_port').value.trim(),
    description: $('#f_desc').value.trim(),
  };
  if (editingId) params.id = editingId;

  const r = await api(editingId ? 'edit' : 'add', params, 'POST');
  if (!r.ok) return toast(r.error || 'Failed to save', 'err');

  toast(editingId ? 'Site updated' : 'Site added', 'ok');
  closeModals();
  loadSites($('#search').value.trim());
}

async function onFindPort() {
  const r = await api('find_port');
  if (r.ok) { $('#f_port').value = r.port; toast(`Free port: ${r.port}`, 'ok'); }
  else toast(r.error || 'No free port found', 'err');
}

/* ---------------- Scan modal ---------------- */

function openScanModal() {
  $('#scanResults').innerHTML = '';
  $('#scanOverlay').classList.add('open');
  $('#f_folder').focus();
}

async function onScanFolder(e) {
  e.preventDefault();
  const folder = $('#f_folder').value.trim();
  const box = $('#scanResults');
  box.innerHTML = '<div class="scan-item">Scanning…</div>';

  const r = await api('scan', { folder });
  if (!r.ok) {
    box.innerHTML = `<div class="scan-item">${escapeHtml(r.error || 'Scan failed')}</div>`;
    return;
  }
  if (!r.results.length) {
    box.innerHTML = '<div class="scan-item">No PHP projects found in that folder.</div>';
    return;
  }

  box.innerHTML = r.results.map((p, i) => `
    <div class="scan-item">
      <div>
        <div class="name">${escapeHtml(p.name)}</div>
        <div class="p">${escapeHtml(p.path)} · ${escapeHtml(p.hint)}</div>
      </div>
      <button class="btn btn-sm" data-idx="${i}">Add</button>
    </div>
  `).join('');

  $$('.scan-item button', box).forEach((btn, i) => {
    btn.addEventListener('click', async () => {
      btn.disabled = true; btn.textContent = 'Adding…';
      const proj = r.results[i];
      const res = await api('add', { name: proj.name, path: proj.path, port: '', description: 'Auto-detected via folder scan' }, 'POST');
      if (res.ok) {
        btn.textContent = 'Added ✓';
        toast(`Added ${proj.name}`, 'ok');
        loadSites($('#search').value.trim());
      } else {
        btn.textContent = 'Retry';
        btn.disabled = false;
        toast(res.error || 'Failed to add', 'err');
      }
    });
  });
}

/* ---------------- Misc ---------------- */

function closeModals() {
  $$('.overlay').forEach(ov => ov.classList.remove('open'));
  editingId = null;
}

let toastTimer;
function toast(msg, kind = '') {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'show' + (kind ? ' ' + kind : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}
</script>
</body>
</html>