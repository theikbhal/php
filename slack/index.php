<?php
/* =========================================================
   SLACK MINI — single-file, SQLite, dark mode
   ========================================================= */

declare(strict_types=1);
session_start();

// --- DB bootstrap ---------------------------------------------------------
$dbFile = __DIR__ . '/slackmini.db';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("PRAGMA foreign_keys = ON");
$db->exec("CREATE TABLE IF NOT EXISTS channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    created_at DATETIME DEFAULT (datetime('now'))
)");
$db->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL,
    author TEXT NOT NULL DEFAULT 'Anonymous',
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT (datetime('now')),
    edited_at DATETIME,
    FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE CASCADE
)");

// --- JSON API -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        $res = ['ok' => false];
        switch ($action) {
            case 'add_channel':
                $name = trim($_POST['name'] ?? '');
                if ($name === '') throw new Exception('Channel name required');
                $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name));
                $name = trim($name, '-');
                if (strlen($name) < 2) throw new Exception('Name too short');
                $stmt = $db->prepare("INSERT INTO channels (name) VALUES (?)");
                $stmt->execute([$name]);
                $res = ['ok' => true, 'id' => (int)$db->lastInsertId(), 'name' => $name];
                break;

            case 'delete_channel':
                $stmt = $db->prepare("DELETE FROM channels WHERE id = ?");
                $stmt->execute([(int)$_POST['id']]);
                $res = ['ok' => true];
                break;

            case 'add_message':
                $content = trim($_POST['content'] ?? '');
                if ($content === '') throw new Exception('Message empty');
                $author = trim($_POST['author'] ?? 'Anonymous') ?: 'Anonymous';
                $stmt = $db->prepare("INSERT INTO messages (channel_id, author, content) VALUES (?,?,?)");
                $stmt->execute([(int)$_POST['channel_id'], $author, $content]);
                $msg = $db->query("SELECT * FROM messages WHERE id = " . (int)$db->lastInsertId())->fetch();
                $res = ['ok' => true, 'message' => $msg];
                break;

            case 'edit_message':
                $content = trim($_POST['content'] ?? '');
                if ($content === '') throw new Exception('Message empty');
                $stmt = $db->prepare("UPDATE messages SET content = ?, edited_at = datetime('now') WHERE id = ?");
                $stmt->execute([$content, (int)$_POST['id']]);
                $res = ['ok' => true];
                break;

            case 'delete_message':
                $stmt = $db->prepare("DELETE FROM messages WHERE id = ?");
                $stmt->execute([(int)$_POST['id']]);
                $res = ['ok' => true];
                break;

            case 'set_user':
                $_SESSION['user'] = trim($_POST['user'] ?? 'Anonymous') ?: 'Anonymous';
                $res = ['ok' => true, 'user' => $_SESSION['user']];
                break;

            default:
                throw new Exception('Unknown action');
        }
        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- HTML render ----------------------------------------------------------
$channels = $db->query("SELECT * FROM channels ORDER BY name ASC")->fetchAll();
$currentId = isset($_GET['channel']) ? (int)$_GET['channel'] : ($channels[0]['id'] ?? null);
$currentChannel = null;
$messages = [];
if ($currentId) {
    $stmt = $db->prepare("SELECT * FROM channels WHERE id = ?");
    $stmt->execute([$currentId]);
    $currentChannel = $stmt->fetch();
    $stmt = $db->prepare("SELECT * FROM messages WHERE channel_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$currentId]);
    $messages = $stmt->fetchAll();
}
$user = $_SESSION['user'] ?? 'Anonymous';

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function timeAgo(string $dt): string {
    $d = new DateTime($dt);
    $diff = (new DateTime())->getTimestamp() - $d->getTimestamp();
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return $d->format('M j, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Slack Mini<?= $currentChannel ? ' — #' . e($currentChannel['name']) : '' ?></title>
<style>
  :root {
    --bg: #1a1d21;
    --bg-sidebar: #19171d;
    --bg-panel: #222529;
    --bg-hover: #27242c;
    --bg-active: #1164a3;
    --bg-input: #222529;
    --border: #353138;
    --text: #d1d2d3;
    --text-dim: #9a9a9a;
    --text-bright: #ffffff;
    --accent: #1d9bd1;
    --danger: #e01e5a;
    --success: #2bac76;
    --radius: 6px;
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
    overflow: hidden;
  }
  .app { display: grid; grid-template-columns: 260px 1fr; height: 100vh; }

  /* Sidebar */
  .sidebar {
    background: var(--bg-sidebar);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    overflow: hidden;
  }
  .sidebar-header {
    padding: 16px 16px 12px;
    border-bottom: 1px solid var(--border);
  }
  .workspace {
    font-weight: 700; color: var(--text-bright);
    font-size: 16px;
    display: flex; align-items: center; gap: 8px;
  }
  .workspace .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--success); }
  .user-row {
    margin-top: 8px;
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; color: var(--text-dim);
  }
  .user-row .avatar {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, #4a154b, #1d9bd1);
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: 700; font-size: 11px;
  }
  .user-row .edit-user {
    margin-left: auto; cursor: pointer; opacity: 0.6;
  }
  .user-row .edit-user:hover { opacity: 1; }

  .sidebar-section {
    padding: 12px 0 4px;
  }
  .section-title {
    padding: 0 16px 6px;
    font-size: 12px;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .section-title button {
    background: none; border: none; color: var(--text-dim);
    cursor: pointer; font-size: 16px; line-height: 1;
    padding: 0 4px; border-radius: 4px;
  }
  .section-title button:hover { background: var(--bg-hover); color: var(--text-bright); }

  .channel-list {
    list-style: none; margin: 0; padding: 0;
    overflow-y: auto; flex: 1;
  }
  .channel-item {
    display: flex; align-items: center; gap: 6px;
    padding: 4px 16px 4px 22px;
    cursor: pointer;
    color: var(--text-dim);
    font-size: 14px;
    border-left: 3px solid transparent;
    position: relative;
  }
  .channel-item:hover { background: var(--bg-hover); color: var(--text); }
  .channel-item.active {
    background: var(--bg-active);
    color: var(--text-bright);
    border-left-color: var(--accent);
  }
  .channel-item .hash { opacity: 0.7; }
  .channel-item .name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .channel-item .del {
    opacity: 0; background: none; border: none; color: inherit;
    cursor: pointer; padding: 0 4px; font-size: 14px;
  }
  .channel-item:hover .del { opacity: 0.6; }
  .channel-item .del:hover { opacity: 1; color: var(--danger); }

  .search-hint {
    padding: 10px 16px;
    border-top: 1px solid var(--border);
    font-size: 12px; color: var(--text-dim);
    display: flex; align-items: center; gap: 6px;
  }
  kbd {
    background: var(--bg-panel); border: 1px solid var(--border);
    border-radius: 3px; padding: 1px 5px; font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }

  /* Main */
  .main { display: flex; flex-direction: column; overflow: hidden; }
  .topbar {
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 12px;
    background: var(--bg);
  }
  .topbar h1 {
    margin: 0; font-size: 16px; font-weight: 700; color: var(--text-bright);
  }
  .topbar .meta { color: var(--text-dim); font-size: 13px; }

  .messages {
    flex: 1; overflow-y: auto;
    padding: 20px 24px;
    display: flex; flex-direction: column; gap: 4px;
  }
  .empty {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: var(--text-dim); text-align: center; padding: 40px;
  }
  .empty .big { font-size: 48px; margin-bottom: 12px; }
  .empty h2 { color: var(--text-bright); margin: 0 0 8px; font-weight: 600; }

  .msg {
    display: grid;
    grid-template-columns: 36px 1fr auto;
    gap: 12px;
    padding: 8px 8px;
    border-radius: var(--radius);
    position: relative;
  }
  .msg:hover { background: var(--bg-panel); }
  .msg .avatar {
    width: 36px; height: 36px; border-radius: 6px;
    background: linear-gradient(135deg, #4a154b, #1d9bd1);
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: 700; font-size: 14px;
  }
  .msg .body { min-width: 0; }
  .msg .head {
    display: flex; align-items: baseline; gap: 8px;
    margin-bottom: 2px;
  }
  .msg .author { font-weight: 700; color: var(--text-bright); }
  .msg .time { font-size: 11px; color: var(--text-dim); }
  .msg .edited { font-size: 11px; color: var(--text-dim); font-style: italic; }
  .msg .content {
    color: var(--text);
    word-wrap: break-word;
    white-space: pre-wrap;
  }
  .msg .actions {
    opacity: 0;
    display: flex; gap: 2px;
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 2px;
    height: fit-content;
    align-self: flex-start;
    margin-top: 4px;
  }
  .msg:hover .actions { opacity: 1; }
  .msg .actions button {
    background: none; border: none; color: var(--text-dim);
    cursor: pointer; padding: 4px 8px; border-radius: 4px;
    font-size: 13px;
  }
  .msg .actions button:hover { background: var(--bg-hover); color: var(--text-bright); }
  .msg .actions button.danger:hover { color: var(--danger); }

  .msg.editing .content { display: none; }
  .msg .edit-form { display: none; }
  .msg.editing .edit-form { display: block; }
  .edit-form textarea {
    width: 100%; min-height: 60px;
    background: var(--bg-input); color: var(--text);
    border: 1px solid var(--accent); border-radius: var(--radius);
    padding: 8px; font-family: inherit; font-size: 14px;
    resize: vertical;
  }
  .edit-form .edit-actions {
    display: flex; gap: 8px; margin-top: 6px;
    font-size: 12px; color: var(--text-dim);
  }
  .edit-form button {
    background: var(--accent); color: white; border: none;
    padding: 4px 12px; border-radius: 4px; cursor: pointer;
    font-size: 12px;
  }
  .edit-form button.cancel {
    background: transparent; color: var(--text-dim);
  }
  .edit-form button.cancel:hover { color: var(--text-bright); }

  /* Composer */
  .composer {
    padding: 12px 20px 20px;
    border-top: 1px solid var(--border);
  }
  .composer-box {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px;
    transition: border-color 0.15s;
  }
  .composer-box:focus-within { border-color: var(--text-dim); }
  .composer textarea {
    width: 100%; background: transparent; color: var(--text);
    border: none; outline: none; resize: none;
    font-family: inherit; font-size: 14px; line-height: 1.5;
    min-height: 24px; max-height: 200px;
  }
  .composer .hint {
    font-size: 11px; color: var(--text-dim);
    margin-top: 6px; display: flex; justify-content: space-between;
  }

  /* Modal */
  .modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.6);
    display: none;
    align-items: flex-start; justify-content: center;
    padding-top: 15vh; z-index: 100;
    backdrop-filter: blur(2px);
  }
  .modal-backdrop.open { display: flex; }
  .modal {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    width: 520px; max-width: 90vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    overflow: hidden;
  }
  .modal input {
    width: 100%; background: transparent; color: var(--text-bright);
    border: none; outline: none;
    padding: 16px 18px; font-size: 16px;
    border-bottom: 1px solid var(--border);
  }
  .modal .results {
    max-height: 320px; overflow-y: auto;
  }
  .modal .result {
    padding: 10px 18px; cursor: pointer;
    display: flex; align-items: center; gap: 10px;
    color: var(--text);
  }
  .modal .result:hover, .modal .result.selected {
    background: var(--bg-active); color: var(--text-bright);
  }
  .modal .result .hash { opacity: 0.7; }
  .modal .empty-result {
    padding: 24px; text-align: center; color: var(--text-dim);
  }
  .modal .footer {
    padding: 10px 18px; border-top: 1px solid var(--border);
    font-size: 11px; color: var(--text-dim);
    display: flex; gap: 14px;
  }

  /* Toast */
  .toast {
    position: fixed; bottom: 24px; right: 24px;
    background: var(--bg-panel); border: 1px solid var(--border);
    color: var(--text-bright); padding: 10px 16px;
    border-radius: var(--radius);
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
    opacity: 0; transform: translateY(10px);
    transition: all 0.2s;
    z-index: 200;
    pointer-events: none;
  }
  .toast.show { opacity: 1; transform: translateY(0); }
  .toast.error { border-color: var(--danger); }

  /* Scrollbar */
  ::-webkit-scrollbar { width: 8px; height: 8px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #3a3640; border-radius: 4px; }
  ::-webkit-scrollbar-thumb:hover { background: #4a4650; }

  @media (max-width: 700px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { display: none; }
    .sidebar.mobile-open { display: flex; position: fixed; inset: 0; z-index: 50; width: 100%; }
  }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="workspace"><span class="dot"></span> Slack Mini</div>
      <div class="user-row">
        <div class="avatar"><?= e(strtoupper(substr($user, 0, 1))) ?></div>
        <span id="userName"><?= e($user) ?></span>
        <span class="edit-user" id="editUserBtn" title="Change name">✎</span>
      </div>
    </div>

    <div class="sidebar-section">
      <div class="section-title">
        <span>Channels</span>
        <button id="addChannelBtn" title="Add channel">＋</button>
      </div>
      <ul class="channel-list" id="channelList">
        <?php foreach ($channels as $ch): ?>
          <li class="channel-item <?= $ch['id'] == $currentId ? 'active' : '' ?>"
              data-id="<?= $ch['id'] ?>" data-name="<?= e($ch['name']) ?>">
            <span class="hash">#</span>
            <span class="name"><?= e($ch['name']) ?></span>
            <button class="del" title="Delete channel">×</button>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="search-hint">
      <kbd>Ctrl</kbd><kbd>K</kbd> Jump to channel
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <?php if ($currentChannel): ?>
      <div class="topbar">
        <h1># <?= e($currentChannel['name']) ?></h1>
        <span class="meta"><?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?></span>
      </div>

      <div class="messages" id="messages">
        <?php if (count($messages) === 0): ?>
          <div class="empty">
            <div class="big">💬</div>
            <h2>This is the very beginning of <strong>#<?= e($currentChannel['name']) ?></strong></h2>
            <p>This channel is for everything <?= e($currentChannel['name']) ?>. Share files, ideas — start the conversation.</p>
          </div>
        <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <div class="msg" data-id="<?= $m['id'] ?>">
              <div class="avatar"><?= e(strtoupper(substr($m['author'], 0, 1))) ?></div>
              <div class="body">
                <div class="head">
                  <span class="author"><?= e($m['author']) ?></span>
                  <span class="time" title="<?= e($m['created_at']) ?>"><?= timeAgo($m['created_at']) ?></span>
                  <?php if ($m['edited_at']): ?>
                    <span class="edited">(edited)</span>
                  <?php endif; ?>
                </div>
                <div class="content"><?= e($m['content']) ?></div>
                <div class="edit-form">
                  <textarea><?= e($m['content']) ?></textarea>
                  <div class="edit-actions">
                    <button class="save">Save</button>
                    <button class="cancel">Cancel</button>
                    <span style="margin-left:auto">Esc to cancel · Enter to save</span>
                  </div>
                </div>
              </div>
              <div class="actions">
                <button class="edit-btn" title="Edit">✎</button>
                <button class="del-btn danger" title="Delete">🗑</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="composer">
        <div class="composer-box">
          <textarea id="msgInput" placeholder="Message #<?= e($currentChannel['name']) ?>" rows="1"></textarea>
          <div class="hint">
            <span><strong><?= e($user) ?></strong></span>
            <span><kbd>Enter</kbd> send · <kbd>Shift</kbd>+<kbd>Enter</kbd> newline</span>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="empty" style="margin:auto">
        <div class="big">🚀</div>
        <h2>Welcome to Slack Mini</h2>
        <p>Create your first channel to get started.</p>
        <button id="firstChannelBtn" style="margin-top:16px;background:var(--accent);color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600">
          Create channel
        </button>
      </div>
    <?php endif; ?>
  </main>
</div>

<!-- Jump-to modal -->
<div class="modal-backdrop" id="jumpModal">
  <div class="modal">
    <input id="jumpInput" type="text" placeholder="Jump to a channel…" autocomplete="off">
    <div class="results" id="jumpResults"></div>
    <div class="footer">
      <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
      <span><kbd>↵</kbd> open</span>
      <span><kbd>esc</kbd> close</span>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const CURRENT_CHANNEL = <?= $currentId ? (int)$currentId : 'null' ?>;
const CHANNELS = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $channels)) ?>;
const USER = <?= json_encode($user) ?>;

const $ = s => document.querySelector(s);
const $$ = s => document.querySelectorAll(s);

function toast(msg, isError = false) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.toggle('error', isError);
  t.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => t.classList.remove('show'), 2200);
}

async function api(action, data = {}) {
  const fd = new FormData();
  fd.append('action', action);
  for (const k in data) fd.append(k, data[k]);
  const r = await fetch('.', { method: 'POST', body: fd });
  const j = await r.json();
  if (!j.ok) throw new Error(j.error || 'Request failed');
  return j;
}

// --- Channel list ---
$('#channelList').addEventListener('click', async (e) => {
  const li = e.target.closest('.channel-item');
  if (!li) return;
  if (e.target.classList.contains('del')) {
    e.stopPropagation();
    const name = li.dataset.name;
    if (!confirm(`Delete #${name}? All messages will be lost.`)) return;
    try {
      await api('delete_channel', { id: li.dataset.id });
      if (CURRENT_CHANNEL == li.dataset.id) {
        location.href = '?';
      } else {
        li.remove();
        toast('Channel deleted');
      }
    } catch (err) { toast(err.message, true); }
    return;
  }
  if (li.dataset.id != CURRENT_CHANNEL) {
    location.href = '?channel=' + li.dataset.id;
  }
});

// --- Add channel ---
async function addChannel() {
  const name = prompt('Channel name (letters, numbers, -, _):');
  if (!name) return;
  try {
    const r = await api('add_channel', { name });
    location.href = '?channel=' + r.id;
  } catch (err) { toast(err.message, true); }
}
$('#addChannelBtn')?.addEventListener('click', addChannel);
$('#firstChannelBtn')?.addEventListener('click', addChannel);

// --- Messages ---
const msgInput = $('#msgInput');
if (msgInput) {
  msgInput.addEventListener('input', () => {
    msgInput.style.height = 'auto';
    msgInput.style.height = Math.min(msgInput.scrollHeight, 200) + 'px';
  });
  msgInput.addEventListener('keydown', async (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      const content = msgInput.value.trim();
      if (!content) return;
      try {
        const r = await api('add_message', { channel_id: CURRENT_CHANNEL, content, author: USER });
        // Remove empty state if present
        const empty = document.querySelector('.empty');
        if (empty) empty.remove();
        // Append message
        const m = r.message;
        const div = document.createElement('div');
        div.className = 'msg';
        div.dataset.id = m.id;
        div.innerHTML = `
          <div class="avatar">${escapeHtml(m.author[0].toUpperCase())}</div>
          <div class="body">
            <div class="head">
              <span class="author">${escapeHtml(m.author)}</span>
              <span class="time" title="${escapeHtml(m.created_at)}">just now</span>
            </div>
            <div class="content">${escapeHtml(m.content)}</div>
            <div class="edit-form">
              <textarea>${escapeHtml(m.content)}</textarea>
              <div class="edit-actions">
                <button class="save">Save</button>
                <button class="cancel">Cancel</button>
                <span style="margin-left:auto">Esc to cancel · Enter to save</span>
              </div>
            </div>
          </div>
          <div class="actions">
            <button class="edit-btn" title="Edit">✎</button>
            <button class="del-btn danger" title="Delete">🗑</button>
          </div>`;
        $('#messages').appendChild(div);
        msgInput.value = '';
        msgInput.style.height = 'auto';
        $('#messages').scrollTop = $('#messages').scrollHeight;
      } catch (err) { toast(err.message, true); }
    }
  });
  // Auto-scroll on load
  const msgs = $('#messages');
  if (msgs) msgs.scrollTop = msgs.scrollHeight;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// --- Edit / delete messages (delegated) ---
document.addEventListener('click', async (e) => {
  const msg = e.target.closest('.msg');
  if (!msg) return;
  const id = msg.dataset.id;

  if (e.target.classList.contains('edit-btn')) {
    msg.classList.add('editing');
    const ta = msg.querySelector('.edit-form textarea');
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);
  }
  if (e.target.classList.contains('cancel')) {
    msg.classList.remove('editing');
    msg.querySelector('.edit-form textarea').value = msg.querySelector('.content').textContent;
  }
  if (e.target.classList.contains('save')) {
    const ta = msg.querySelector('.edit-form textarea');
    const newContent = ta.value.trim();
    if (!newContent) return;
    try {
      await api('edit_message', { id, content: newContent });
      msg.querySelector('.content').textContent = newContent;
      msg.classList.remove('editing');
      if (!msg.querySelector('.edited')) {
        const ed = document.createElement('span');
        ed.className = 'edited';
        ed.textContent = '(edited)';
        msg.querySelector('.head').appendChild(ed);
      }
      toast('Message updated');
    } catch (err) { toast(err.message, true); }
  }
  if (e.target.classList.contains('del-btn')) {
    if (!confirm('Delete this message?')) return;
    try {
      await api('delete_message', { id });
      msg.remove();
      toast('Message deleted');
    } catch (err) { toast(err.message, true); }
  }
});

// Enter to save edit, Esc to cancel
document.addEventListener('keydown', (e) => {
  const editing = document.querySelector('.msg.editing');
  if (!editing) return;
  if (e.key === 'Escape') {
    editing.querySelector('.cancel').click();
  } else if (e.key === 'Enter' && !e.shiftKey && document.activeElement.tagName === 'TEXTAREA' && editing.contains(document.activeElement)) {
    e.preventDefault();
    editing.querySelector('.save').click();
  }
});

// --- Jump modal (Ctrl+K) ---
const jumpModal = $('#jumpModal');
const jumpInput = $('#jumpInput');
const jumpResults = $('#jumpResults');
let jumpSelected = 0;

function openJump() {
  jumpModal.classList.add('open');
  jumpInput.value = '';
  renderJump('');
  setTimeout(() => jumpInput.focus(), 50);
}
function closeJump() { jumpModal.classList.remove('open'); }

function renderJump(q) {
  q = q.toLowerCase().trim();
  const filtered = CHANNELS.filter(c => !q || c.name.toLowerCase().includes(q));
  jumpSelected = 0;
  if (filtered.length === 0) {
    jumpResults.innerHTML = `<div class="empty-result">No channels match "${escapeHtml(q)}"</div>`;
    return;
  }
  jumpResults.innerHTML = filtered.map((c, i) =>
    `<div class="result ${i === 0 ? 'selected' : ''}" data-id="${c.id}">
       <span class="hash">#</span>
       <span>${escapeHtml(c.name)}</span>
     </div>`
  ).join('');
}

jumpInput.addEventListener('input', () => renderJump(jumpInput.value));
jumpInput.addEventListener('keydown', (e) => {
  const items = jumpResults.querySelectorAll('.result');
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    jumpSelected = Math.min(jumpSelected + 1, items.length - 1);
    items.forEach((it, i) => it.classList.toggle('selected', i === jumpSelected));
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    jumpSelected = Math.max(jumpSelected - 1, 0);
    items.forEach((it, i) => it.classList.toggle('selected', i === jumpSelected));
  } else if (e.key === 'Enter') {
    e.preventDefault();
    const sel = items[jumpSelected];
    if (sel) location.href = '?channel=' + sel.dataset.id;
  } else if (e.key === 'Escape') {
    closeJump();
  }
});
jumpResults.addEventListener('click', (e) => {
  const r = e.target.closest('.result');
  if (r) location.href = '?channel=' + r.dataset.id;
});
jumpModal.addEventListener('click', (e) => {
  if (e.target === jumpModal) closeJump();
});

document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    if (jumpModal.classList.contains('open')) closeJump();
    else openJump();
  }
  if (e.key === 'Escape' && jumpModal.classList.contains('open')) {
    closeJump();
  }
});

// --- Edit user ---
$('#editUserBtn')?.addEventListener('click', async () => {
  const name = prompt('Your display name:', USER);
  if (name === null) return;
  try {
    const r = await api('set_user', { user: name });
    $('#userName').textContent = r.user;
    toast('Name updated');
  } catch (err) { toast(err.message, true); }
});
</script>
</body>
</html>