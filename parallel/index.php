<?php
declare(strict_types=1);

/* ============================================================
   PARALLEL NOTES
   A minimalist, local-first, dark-mode notes app.
   Single-file PHP + SQLite + vanilla JS application.
   ============================================================ */

/* ============================================================
   DATABASE
   ============================================================ */

$dbPath = __DIR__ . '/notes.db';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

/**
 * Create the schema if it does not already exist.
 */
function initDb(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT 'Untitled',
        note TEXT NOT NULL DEFAULT '',
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        focused_at TEXT NOT NULL,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_project ON history(project_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_projects_position ON projects(position)");
}
initDb($pdo);

/**
 * Seed a friendly first project so the app is never empty on first run.
 */
function seedIfEmpty(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($count === 0) {
        $now = date('c');
        $stmt = $pdo->prepare(
            'INSERT INTO projects (name, note, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'Welcome',
            "Welcome to Parallel Notes.\n\nCreate a new project from the sidebar and start typing — everything autosaves automatically.\n\nUse Parallel view to work on two projects side by side, or Stack view to line them up vertically.",
            0,
            $now,
            $now,
        ]);
    }
}
seedIfEmpty($pdo);

/**
 * Read a single setting value, or return $default if not present.
 */
function getSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : (string)$val;
}

/**
 * Write a setting value (insert or update, portable across SQLite versions).
 */
function setSetting(PDO $pdo, string $key, string $value): void
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE key = ?');
    $check->execute([$key]);
    if ((int)$check->fetchColumn() > 0) {
        $stmt = $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?');
        $stmt->execute([$value, $key]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
    }
}

/* ============================================================
   API
   All AJAX endpoints. Triggered whenever ?action=... is present
   (GET for reads, POST for mutations). Responds with JSON and
   exits immediately — the HTML page is never mixed with API output.
   ============================================================ */

$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        switch ($action) {
            case 'list':
                apiList($pdo);
                break;
            case 'create':
                apiCreate($pdo);
                break;
            case 'rename':
                apiRename($pdo);
                break;
            case 'update_note':
                apiUpdateNote($pdo);
                break;
            case 'delete':
                apiDelete($pdo);
                break;
            case 'focus':
                apiFocus($pdo);
                break;
            case 'set_layout':
                apiSetLayout($pdo);
                break;
            case 'set_open':
                apiSetOpen($pdo);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * GET ?action=list
 * Returns all projects plus persisted UI state (layout, open panes, last active project).
 */
function apiList(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, name, note, position, created_at, updated_at FROM projects ORDER BY position ASC, id ASC'
    );
    $projects = $stmt->fetchAll();

    // Normalize types for JSON (SQLite returns strings for INTEGER columns via PDO in some configs).
    foreach ($projects as &$p) {
        $p['id'] = (int)$p['id'];
        $p['position'] = (int)$p['position'];
    }
    unset($p);

    $layout = getSetting($pdo, 'layout', 'parallel');
    $openRaw = getSetting($pdo, 'open_projects', '[]');
    $lastProjectRaw = getSetting($pdo, 'last_project', null);

    $open = json_decode((string)$openRaw, true);
    if (!is_array($open)) {
        $open = [];
    }
    $open = array_values(array_map('intval', $open));

    echo json_encode([
        'projects' => $projects,
        'layout' => in_array($layout, ['parallel', 'stack'], true) ? $layout : 'parallel',
        'open' => $open,
        'last_project' => $lastProjectRaw !== null ? (int)$lastProjectRaw : null,
    ]);
}

/**
 * POST ?action=create
 * Creates a new project with a default name and empty note.
 */
function apiCreate(PDO $pdo): void
{
    $now = date('c');
    $maxPos = $pdo->query('SELECT COALESCE(MAX(position), -1) FROM projects')->fetchColumn();
    $nextPos = (int)$maxPos + 1;
    $name = 'Untitled';

    $stmt = $pdo->prepare(
        'INSERT INTO projects (name, note, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, '', $nextPos, $now, $now]);
    $id = (int)$pdo->lastInsertId();

    echo json_encode([
        'id' => $id,
        'name' => $name,
        'note' => '',
        'position' => $nextPos,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * POST ?action=rename  (id, name)
 */
function apiRename(PDO $pdo): void
{
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid project id']);
        return;
    }

    if ($name === '') {
        $name = 'Untitled';
    }
    $name = mb_substr($name, 0, 200);

    $now = date('c');
    $stmt = $pdo->prepare('UPDATE projects SET name = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$name, $now, $id]);

    echo json_encode(['ok' => true, 'name' => $name, 'updated_at' => $now]);
}

/**
 * POST ?action=update_note  (id, note)
 * The autosave endpoint.
 */
function apiUpdateNote(PDO $pdo): void
{
    $id = (int)($_POST['id'] ?? 0);
    $note = (string)($_POST['note'] ?? '');

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid project id']);
        return;
    }

    $now = date('c');
    $stmt = $pdo->prepare('UPDATE projects SET note = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$note, $now, $id]);

    echo json_encode(['ok' => true, 'updated_at' => $now]);
}

/**
 * POST ?action=delete  (id)
 */
function apiDelete(PDO $pdo): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid project id']);
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ?');
    $stmt->execute([$id]);

    // Explicit cleanup, in addition to ON DELETE CASCADE, for maximum portability.
    $stmt2 = $pdo->prepare('DELETE FROM history WHERE project_id = ?');
    $stmt2->execute([$id]);

    echo json_encode(['ok' => true]);
}

/**
 * POST ?action=focus  (id)
 * Records a focus-history event and remembers the last active project.
 */
function apiFocus(PDO $pdo): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid project id']);
        return;
    }

    $now = date('c');
    $stmt = $pdo->prepare('INSERT INTO history (project_id, focused_at) VALUES (?, ?)');
    $stmt->execute([$id, $now]);

    setSetting($pdo, 'last_project', (string)$id);

    echo json_encode(['ok' => true]);
}

/**
 * POST ?action=set_layout  (layout: parallel|stack)
 */
function apiSetLayout(PDO $pdo): void
{
    $layout = (string)($_POST['layout'] ?? 'parallel');
    if (!in_array($layout, ['parallel', 'stack'], true)) {
        $layout = 'parallel';
    }
    setSetting($pdo, 'layout', $layout);
    echo json_encode(['ok' => true]);
}

/**
 * POST ?action=set_open  (open: JSON array of project ids)
 * Persists which project panes are currently open in the workspace.
 */
function apiSetOpen(PDO $pdo): void
{
    $raw = (string)($_POST['open'] ?? '[]');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $ids = array_values(array_unique(array_map('intval', $decoded)));
    setSetting($pdo, 'open_projects', json_encode($ids));
    echo json_encode(['ok' => true]);
}

/* ============================================================
   From this point on, no ?action was requested — render the page.
   ============================================================ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="theme-color" content="#0a0a0b">
<title>Parallel Notes</title>
<style>
/* ============================================================
   CSS
   Dark mode only. Minimal, spacious, rounded, no gradients,
   no heavy shadows. Mobile-first: base rules target small
   screens, desktop layout kicks in via min-width media query.
   ============================================================ */

:root {
    --bg: #0a0a0b;
    --sidebar-bg: #111113;
    --card-bg: #17171a;
    --card-bg-hover: #1c1c20;
    --border: #26262a;
    --text: #e8e8ea;
    --text-dim: #8a8a90;
    --text-faint: #5c5c62;
    --accent: #4f8cff;
    --accent-dim: rgba(79, 140, 255, 0.14);
    --danger: #ff5f5f;
    --success: #4fd68c;
    --radius: 10px;
    --radius-sm: 6px;
    --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

* { box-sizing: border-box; }

html, body {
    height: 100%;
    margin: 0;
    padding: 0;
}

body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    font-size: 15px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    overflow: hidden;
}

button, input, textarea {
    font-family: inherit;
    font-size: inherit;
    color: inherit;
}

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 8px; }
::-webkit-scrollbar-thumb:hover { background: var(--text-faint); }

/* ---------- App shell ---------- */

#app {
    display: flex;
    height: 100vh;
    position: relative;
    overflow: hidden;
}

/* ---------- Mobile menu button ---------- */

.mobile-menu-btn {
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 60;
    width: 40px;
    height: 40px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.mobile-menu-btn:hover { background: var(--card-bg-hover); }

/* ---------- Sidebar ---------- */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: 250px;
    max-width: 85vw;
    background: var(--sidebar-bg);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    z-index: 50;
    transform: translateX(-100%);
    transition: transform 0.2s ease;
}

.sidebar.open { transform: translateX(0); }

.sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 40;
    display: none;
}

.sidebar-overlay.visible { display: block; }

.sidebar-header {
    padding: 20px 16px 12px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    border-bottom: 1px solid var(--border);
}

.app-title {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 4px;
    padding-left: 44px; /* clears mobile menu button */
    color: var(--text);
    letter-spacing: 0.2px;
}

.btn-new {
    width: 100%;
    padding: 10px 12px;
    background: var(--accent);
    color: #ffffff;
    border: none;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: opacity 0.15s ease;
}

.btn-new:hover { opacity: 0.88; }
.btn-new:active { opacity: 0.75; }

.search-wrap {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.search-wrap input {
    width: 100%;
    padding: 9px 12px;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    outline: none;
}

.search-wrap input:focus { border-color: var(--accent); }
.search-wrap input::placeholder { color: var(--text-faint); }

.project-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}

.project-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 10px;
    margin-bottom: 4px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    border: 1px solid transparent;
}

.project-row:hover { background: var(--card-bg); }

.project-row.active {
    background: var(--accent-dim);
    border-color: var(--accent);
}

.project-row-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    flex: 1;
}

.project-name {
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text);
}

.project-meta {
    font-size: 11.5px;
    color: var(--text-faint);
}

.title-edit-input {
    width: 100%;
    background: var(--card-bg);
    border: 1px solid var(--accent);
    border-radius: var(--radius-sm);
    padding: 3px 6px;
    outline: none;
}

.project-delete {
    background: transparent;
    border: none;
    color: var(--text-faint);
    font-size: 16px;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: var(--radius-sm);
    line-height: 1;
    flex-shrink: 0;
}

.project-delete:hover { color: var(--danger); background: rgba(255, 95, 95, 0.1); }

.sidebar-empty {
    padding: 24px 12px;
    text-align: center;
    color: var(--text-faint);
    font-size: 13px;
}

/* ---------- Main content ---------- */

.main-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    height: 100vh;
    min-width: 0;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px 14px 60px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    flex-wrap: wrap;
}

.view-toggle {
    display: flex;
    gap: 4px;
    background: var(--card-bg);
    padding: 3px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
}

.view-btn {
    background: transparent;
    border: none;
    color: var(--text-dim);
    padding: 6px 14px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
}

.view-btn.active {
    background: var(--accent);
    color: #ffffff;
}

.view-btn:not(.active):hover { color: var(--text); }

.save-status {
    font-size: 12.5px;
    color: var(--text-faint);
    min-width: 90px;
    text-align: right;
}

.save-status.saving { color: var(--text-dim); }
.save-status.saved { color: var(--success); }
.save-status.error { color: var(--danger); }

/* ---------- Workspace ---------- */

.workspace {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    align-content: start;
}

.workspace-empty {
    grid-column: 1 / -1;
    text-align: center;
    color: var(--text-faint);
    padding: 60px 20px;
    font-size: 14px;
}

.project-pane {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    display: flex;
    flex-direction: column;
    min-height: 360px;
    overflow: hidden;
}

.pane-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}

.pane-title {
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
    cursor: text;
}

.pane-header-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.pane-updated {
    font-size: 11.5px;
    color: var(--text-faint);
    white-space: nowrap;
}

.pane-close {
    background: transparent;
    border: none;
    color: var(--text-faint);
    font-size: 16px;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    line-height: 1;
}

.pane-close:hover { color: var(--danger); background: rgba(255, 95, 95, 0.1); }

.pane-textarea {
    flex: 1;
    width: 100%;
    min-height: 260px;
    background: transparent;
    border: none;
    outline: none;
    resize: none;
    color: var(--text);
    padding: 16px;
    line-height: 1.6;
}

.pane-textarea::placeholder { color: var(--text-faint); }

.title-edit-input.pane-title-input {
    font-size: 14px;
    font-weight: 600;
}

/* ---------- Desktop layout ---------- */

@media (min-width: 769px) {
    .mobile-menu-btn { display: none; }
    .sidebar-overlay { display: none !important; }

    .sidebar {
        position: static;
        transform: none;
        max-width: none;
        flex-shrink: 0;
        height: 100vh;
    }

    .app-title { padding-left: 0; }

    .toolbar { padding-left: 24px; }

    .workspace.layout-parallel {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .workspace.layout-stack {
        grid-template-columns: minmax(0, 900px);
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .toolbar { padding-left: 56px; }
    .save-status { text-align: left; min-width: 0; }
}
</style>
</head>
<body>
<!-- ============================================================
     HTML
     ============================================================ -->
<div id="app">

    <button id="mobileMenuBtn" class="mobile-menu-btn" aria-label="Toggle sidebar">&#9776;</button>

    <aside id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <h1 class="app-title">Parallel Notes</h1>
            <button id="newProjectBtn" class="btn-new" type="button">+ New Project</button>
        </div>
        <div class="search-wrap">
            <input type="text" id="searchInput" placeholder="Search projects..." autocomplete="off" spellcheck="false">
        </div>
        <div id="projectList" class="project-list"></div>
    </aside>

    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <main class="main-content">
        <div class="toolbar">
            <div class="view-toggle">
                <button class="view-btn" data-view="parallel" type="button">Parallel</button>
                <button class="view-btn" data-view="stack" type="button">Stack</button>
            </div>
            <div id="saveStatus" class="save-status"></div>
        </div>
        <div id="workspace" class="workspace"></div>
    </main>

</div>

<script>
/* ============================================================
   JAVASCRIPT
   ============================================================ */
(function () {
    'use strict';

    /* ---------------- DOM references ---------------- */
    var sidebarEl = document.getElementById('sidebar');
    var sidebarOverlayEl = document.getElementById('sidebarOverlay');
    var mobileMenuBtn = document.getElementById('mobileMenuBtn');
    var newProjectBtn = document.getElementById('newProjectBtn');
    var searchInput = document.getElementById('searchInput');
    var projectListEl = document.getElementById('projectList');
    var workspaceEl = document.getElementById('workspace');
    var saveStatusEl = document.getElementById('saveStatus');
    var viewButtons = document.querySelectorAll('.view-btn');

    var FORM_HEADERS = { 'Content-Type': 'application/x-www-form-urlencoded' };
    var MAX_OPEN_PANES = 6;

    /* ---------------- App state ---------------- */
    var state = {
        projects: [],      // all projects loaded from server
        open: [],           // ordered array of open project ids
        layout: 'parallel', // 'parallel' | 'stack'
        activeId: null,     // currently highlighted / focused project id
        searchTerm: ''
    };

    var noteDebounceTimers = {};
    var searchDebounceTimer = null;
    var saveStatusResetTimer = null;

    /* ============================================================
       UTILITIES
       ============================================================ */

    function relativeTime(iso) {
        if (!iso) return '';
        var then = new Date(iso).getTime();
        if (isNaN(then)) return '';
        var now = Date.now();
        var diffSec = Math.floor((now - then) / 1000);
        if (diffSec < 5) return 'just now';
        if (diffSec < 60) return diffSec + 's ago';
        var diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) return diffMin + 'm ago';
        var diffHr = Math.floor(diffMin / 60);
        if (diffHr < 24) return diffHr + 'h ago';
        var diffDay = Math.floor(diffHr / 24);
        if (diffDay < 7) return diffDay + 'd ago';
        var d = new Date(iso);
        return d.toLocaleDateString();
    }

    function findProject(id) {
        for (var i = 0; i < state.projects.length; i++) {
            if (state.projects[i].id === id) return state.projects[i];
        }
        return null;
    }

    function closeSidebarOnMobile() {
        sidebarEl.classList.remove('open');
        sidebarOverlayEl.classList.remove('visible');
    }

    /* ============================================================
       API CALLS
       ============================================================ */

    function apiList() {
        return fetch('?action=list').then(function (r) { return r.json(); });
    }

    function apiCreate() {
        return fetch('?action=create', { method: 'POST' }).then(function (r) { return r.json(); });
    }

    function apiRename(id, name) {
        return fetch('?action=rename', {
            method: 'POST',
            headers: FORM_HEADERS,
            body: 'id=' + encodeURIComponent(id) + '&name=' + encodeURIComponent(name)
        }).then(function (r) { return r.json(); });
    }

    function apiUpdateNote(id, note) {
        return fetch('?action=update_note', {
            method: 'POST',
            headers: FORM_HEADERS,
            body: 'id=' + encodeURIComponent(id) + '&note=' + encodeURIComponent(note)
        }).then(function (r) { return r.json(); });
    }

    function apiDelete(id) {
        return fetch('?action=delete', {
            method: 'POST',
            headers: FORM_HEADERS,
            body: 'id=' + encodeURIComponent(id)
        }).then(function (r) { return r.json(); });
    }

    function apiFocus(id) {
        return fetch('?action=focus', {
            method: 'POST',
            headers: FORM_HEADERS,
            body: 'id=' + encodeURIComponent(id)
        }).then(function (r) { return r.json(); });
    }

    function apiSetLayout(layout) {
        return fetch('?action=set_layout', {
            method: 'POST',
            headers: FORM_HEADERS,
            body: 'layout=' + encodeURIComponent(layout)
        }).then(function (r) { return r.json(); });
    }

    function apiSetOpen(openArr) {
        return fetch('?action=set_open', {
            method: 'POST',
            headers: FORM_HEADERS,
            body: 'open=' + encodeURIComponent(JSON.stringify(openArr))
        }).then(function (r) { return r.json(); });
    }

    /* ============================================================
       SAVE STATUS (toolbar indicator)
       ============================================================ */

    function setSaveStatus(status) {
        clearTimeout(saveStatusResetTimer);
        if (status === 'saving') {
            saveStatusEl.textContent = 'Saving...';
            saveStatusEl.className = 'save-status saving';
        } else if (status === 'saved') {
            saveStatusEl.textContent = 'Saved \u2713';
            saveStatusEl.className = 'save-status saved';
            saveStatusResetTimer = setTimeout(function () {
                saveStatusEl.textContent = '';
                saveStatusEl.className = 'save-status';
            }, 2000);
        } else if (status === 'error') {
            saveStatusEl.textContent = 'Error saving';
            saveStatusEl.className = 'save-status error';
        }
    }

    function updateMetaDisplays(id, updatedAt) {
        var paneUpdated = workspaceEl.querySelector('.project-pane[data-id="' + id + '"] .pane-updated');
        if (paneUpdated) paneUpdated.textContent = 'Updated ' + relativeTime(updatedAt);

        var rowMeta = projectListEl.querySelector('.project-row[data-id="' + id + '"] .project-meta');
        if (rowMeta) rowMeta.textContent = relativeTime(updatedAt);
    }

    /* ============================================================
       RENDERING — SIDEBAR
       ============================================================ */

    function renderSidebar() {
        projectListEl.innerHTML = '';

        var term = state.searchTerm.trim().toLowerCase();
        var filtered = state.projects.filter(function (p) {
            if (!term) return true;
            var name = (p.name || '').toLowerCase();
            var note = (p.note || '').toLowerCase();
            return name.indexOf(term) !== -1 || note.indexOf(term) !== -1;
        });

        if (state.projects.length === 0) {
            var emptyAll = document.createElement('div');
            emptyAll.className = 'sidebar-empty';
            emptyAll.textContent = 'No projects yet. Create one to get started.';
            projectListEl.appendChild(emptyAll);
            return;
        }

        if (filtered.length === 0) {
            var emptySearch = document.createElement('div');
            emptySearch.className = 'sidebar-empty';
            emptySearch.textContent = 'No matches found.';
            projectListEl.appendChild(emptySearch);
            return;
        }

        var frag = document.createDocumentFragment();

        filtered.forEach(function (project) {
            var row = document.createElement('div');
            row.className = 'project-row' + (project.id === state.activeId ? ' active' : '');
            row.dataset.id = String(project.id);

            var main = document.createElement('div');
            main.className = 'project-row-main';

            var nameSpan = document.createElement('span');
            nameSpan.className = 'project-name';
            nameSpan.dataset.id = String(project.id);
            nameSpan.textContent = project.name;

            var metaSpan = document.createElement('span');
            metaSpan.className = 'project-meta';
            metaSpan.textContent = relativeTime(project.updated_at);

            main.appendChild(nameSpan);
            main.appendChild(metaSpan);

            var delBtn = document.createElement('button');
            delBtn.className = 'project-delete';
            delBtn.type = 'button';
            delBtn.dataset.id = String(project.id);
            delBtn.title = 'Delete project';
            delBtn.textContent = '\u00D7';

            row.appendChild(main);
            row.appendChild(delBtn);
            frag.appendChild(row);
        });

        projectListEl.appendChild(frag);
    }

    /* ============================================================
       RENDERING — WORKSPACE
       ============================================================ */

    function renderWorkspace() {
        // Drop any open ids that no longer exist (e.g. deleted elsewhere).
        state.open = state.open.filter(function (id) { return !!findProject(id); });

        workspaceEl.className = 'workspace ' + (state.layout === 'parallel' ? 'layout-parallel' : 'layout-stack');
        workspaceEl.innerHTML = '';

        if (state.open.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'workspace-empty';
            empty.textContent = state.projects.length === 0
                ? 'Create your first project to get started.'
                : 'Select a project from the sidebar to open it here.';
            workspaceEl.appendChild(empty);
            return;
        }

        state.open.forEach(function (id) {
            var project = findProject(id);
            if (!project) return;

            var pane = document.createElement('div');
            pane.className = 'project-pane';
            pane.dataset.id = String(project.id);

            var header = document.createElement('div');
            header.className = 'pane-header';

            var title = document.createElement('span');
            title.className = 'pane-title';
            title.dataset.id = String(project.id);
            title.title = 'Double-click to rename';
            title.textContent = project.name;

            var right = document.createElement('div');
            right.className = 'pane-header-right';

            var updated = document.createElement('span');
            updated.className = 'pane-updated';
            updated.textContent = 'Updated ' + relativeTime(project.updated_at);

            var closeBtn = document.createElement('button');
            closeBtn.className = 'pane-close';
            closeBtn.type = 'button';
            closeBtn.dataset.id = String(project.id);
            closeBtn.title = 'Close pane';
            closeBtn.textContent = '\u00D7';

            right.appendChild(updated);
            right.appendChild(closeBtn);
            header.appendChild(title);
            header.appendChild(right);

            var textarea = document.createElement('textarea');
            textarea.className = 'pane-textarea';
            textarea.dataset.id = String(project.id);
            textarea.placeholder = 'Start typing...';
            textarea.value = project.note || '';

            pane.appendChild(header);
            pane.appendChild(textarea);
            workspaceEl.appendChild(pane);
        });
    }

    /* ============================================================
       INLINE RENAME (sidebar row or pane title)
       ============================================================ */

    function editableTitle(spanEl, id, currentName, extraClass) {
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'title-edit-input' + (extraClass ? ' ' + extraClass : '');
        input.value = currentName;
        input.maxLength = 200;
        input.spellcheck = false;

        spanEl.replaceWith(input);
        input.focus();
        input.select();

        var done = false;

        function commit(save) {
            if (done) return;
            done = true;
            if (save) {
                var newName = input.value.trim() || 'Untitled';
                doRename(id, newName);
            } else {
                renderSidebar();
                renderWorkspace();
            }
        }

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                commit(true);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                commit(false);
            }
        });

        input.addEventListener('blur', function () {
            commit(true);
        });
    }

    /* ============================================================
       ACTIONS
       ============================================================ */

    function openProject(id) {
        state.activeId = id;

        if (state.open.indexOf(id) === -1) {
            state.open.push(id);
            if (state.open.length > MAX_OPEN_PANES) {
                state.open.shift();
            }
            persistOpen();
        }

        persistFocus(id);
        renderSidebar();
        renderWorkspace();
        closeSidebarOnMobile();

        var pane = workspaceEl.querySelector('.project-pane[data-id="' + id + '"]');
        if (pane && typeof pane.scrollIntoView === 'function') {
            pane.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function closeProject(id) {
        state.open = state.open.filter(function (x) { return x !== id; });
        persistOpen();
        renderWorkspace();
    }

    function createProject() {
        apiCreate().then(function (data) {
            state.projects.push(data);
            state.activeId = data.id;
            state.open.push(data.id);
            if (state.open.length > MAX_OPEN_PANES) {
                state.open.shift();
            }
            persistOpen();
            persistFocus(data.id);
            renderSidebar();
            renderWorkspace();
            closeSidebarOnMobile();

            var titleEl = workspaceEl.querySelector('.pane-title[data-id="' + data.id + '"]');
            if (titleEl) {
                editableTitle(titleEl, data.id, data.name, 'pane-title-input');
            }
        }).catch(function (err) {
            console.error('Failed to create project', err);
        });
    }

    function doRename(id, name) {
        apiRename(id, name).then(function (data) {
            var project = findProject(id);
            if (project) {
                project.name = data.name;
                project.updated_at = data.updated_at;
            }
            renderSidebar();
            renderWorkspace();
        }).catch(function (err) {
            console.error('Failed to rename project', err);
            renderSidebar();
            renderWorkspace();
        });
    }

    function deleteProject(id) {
        var project = findProject(id);
        var label = project ? project.name : 'this project';
        if (!window.confirm('Delete "' + label + '"? This cannot be undone.')) {
            return;
        }

        apiDelete(id).then(function () {
            state.projects = state.projects.filter(function (p) { return p.id !== id; });
            state.open = state.open.filter(function (x) { return x !== id; });
            if (state.activeId === id) {
                state.activeId = state.open.length ? state.open[state.open.length - 1] : null;
            }
            persistOpen();
            renderSidebar();
            renderWorkspace();
        }).catch(function (err) {
            console.error('Failed to delete project', err);
        });
    }

    function handleNoteInput(textareaEl) {
        var id = parseInt(textareaEl.dataset.id, 10);
        var note = textareaEl.value;

        var project = findProject(id);
        if (project) project.note = note;

        setSaveStatus('saving');

        clearTimeout(noteDebounceTimers[id]);
        noteDebounceTimers[id] = setTimeout(function () {
            saveNote(id, note);
        }, 700);
    }

    function saveNote(id, note) {
        apiUpdateNote(id, note).then(function (data) {
            var project = findProject(id);
            if (project) project.updated_at = data.updated_at;
            updateMetaDisplays(id, data.updated_at);
            setSaveStatus('saved');
        }).catch(function (err) {
            console.error('Failed to save note', err);
            setSaveStatus('error');
        });
    }

    function persistOpen() {
        apiSetOpen(state.open).catch(function (err) {
            console.error('Failed to persist open panes', err);
        });
    }

    function persistFocus(id) {
        apiFocus(id).catch(function (err) {
            console.error('Failed to record focus history', err);
        });
    }

    function setLayout(layout) {
        state.layout = layout;
        viewButtons.forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.view === layout);
        });
        renderWorkspace();
        apiSetLayout(layout).catch(function (err) {
            console.error('Failed to persist layout', err);
        });
    }

    /* ============================================================
       EVENT DELEGATION
       ============================================================ */

    projectListEl.addEventListener('click', function (e) {
        if (e.target.tagName === 'INPUT') return;

        var delBtn = e.target.closest('.project-delete');
        if (delBtn) {
            e.stopPropagation();
            deleteProject(parseInt(delBtn.dataset.id, 10));
            return;
        }

        var row = e.target.closest('.project-row');
        if (row) {
            openProject(parseInt(row.dataset.id, 10));
        }
    });

    projectListEl.addEventListener('dblclick', function (e) {
        var nameSpan = e.target.closest('.project-name');
        if (nameSpan) {
            var id = parseInt(nameSpan.dataset.id, 10);
            var project = findProject(id);
            if (project) editableTitle(nameSpan, id, project.name);
        }
    });

    workspaceEl.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('pane-textarea')) {
            handleNoteInput(e.target);
        }
    });

    workspaceEl.addEventListener('click', function (e) {
        var closeBtn = e.target.closest('.pane-close');
        if (closeBtn) {
            closeProject(parseInt(closeBtn.dataset.id, 10));
        }
    });

    workspaceEl.addEventListener('dblclick', function (e) {
        var title = e.target.closest('.pane-title');
        if (title) {
            var id = parseInt(title.dataset.id, 10);
            var project = findProject(id);
            if (project) editableTitle(title, id, project.name, 'pane-title-input');
        }
    });

    newProjectBtn.addEventListener('click', function () {
        createProject();
    });

    searchInput.addEventListener('input', function (e) {
        var val = e.target.value;
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(function () {
            state.searchTerm = val;
            renderSidebar();
        }, 150);
    });

    viewButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setLayout(btn.dataset.view);
        });
    });

    mobileMenuBtn.addEventListener('click', function () {
        sidebarEl.classList.toggle('open');
        sidebarOverlayEl.classList.toggle('visible');
    });

    sidebarOverlayEl.addEventListener('click', function () {
        closeSidebarOnMobile();
    });

    /* ============================================================
       INIT
       ============================================================ */

    function init() {
        apiList().then(function (data) {
            state.projects = data.projects || [];
            state.layout = data.layout === 'stack' ? 'stack' : 'parallel';

            var openFromServer = Array.isArray(data.open) ? data.open : [];
            openFromServer = openFromServer.filter(function (id) {
                return state.projects.some(function (p) { return p.id === id; });
            });

            if (openFromServer.length > 0) {
                state.open = openFromServer;
            } else if (data.last_project && state.projects.some(function (p) { return p.id === data.last_project; })) {
                state.open = [data.last_project];
            } else if (state.projects.length > 0) {
                state.open = [state.projects[0].id];
            } else {
                state.open = [];
            }

            state.activeId = data.last_project && state.projects.some(function (p) { return p.id === data.last_project; })
                ? data.last_project
                : (state.open.length ? state.open[state.open.length - 1] : null);

            viewButtons.forEach(function (btn) {
                btn.classList.toggle('active', btn.dataset.view === state.layout);
            });

            renderSidebar();
            renderWorkspace();
        }).catch(function (err) {
            console.error('Failed to load projects', err);
            workspaceEl.innerHTML = '';
            var errDiv = document.createElement('div');
            errDiv.className = 'workspace-empty';
            errDiv.textContent = 'Failed to load projects. Please refresh the page.';
            workspaceEl.appendChild(errDiv);
        });
    }

    init();
})();
</script>
</body>
</html>