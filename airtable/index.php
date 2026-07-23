<?php
/**
 * Mini Airtable - Single PHP File
 * SQLite, dark mode, mobile-first
 * Bases -> Tables -> Records -> Comments
 */

// -------------------- CONFIG --------------------
define('DB_FILE', __DIR__ . '/airtable.db');
define('DEBUG', true);

// -------------------- DATABASE SETUP --------------------
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        createTables($pdo);
    }
    return $pdo;
}

function createTables($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS tables (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            base_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (base_id) REFERENCES bases(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            table_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'text',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            table_id INTEGER NOT NULL,
            data TEXT NOT NULL, -- JSON object: field_name -> value
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER NOT NULL,
            author TEXT NOT NULL DEFAULT 'Anonymous',
            content TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE
        );
    ");
}

// -------------------- HELPERS --------------------
function jsonResponse($data, $status = 200) {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function getBase($id) {
    $stmt = db()->prepare('SELECT * FROM bases WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getTable($id) {
    $stmt = db()->prepare('SELECT * FROM tables WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getFields($tableId) {
    $stmt = db()->prepare('SELECT * FROM fields WHERE table_id = ? ORDER BY id');
    $stmt->execute([$tableId]);
    return $stmt->fetchAll();
}

function getRecords($tableId) {
    $stmt = db()->prepare('SELECT * FROM records WHERE table_id = ? ORDER BY id DESC');
    $stmt->execute([$tableId]);
    return $stmt->fetchAll();
}

function getComments($recordId) {
    $stmt = db()->prepare('SELECT * FROM comments WHERE record_id = ? ORDER BY created_at DESC');
    $stmt->execute([$recordId]);
    return $stmt->fetchAll();
}

function decodeData($data) {
    return json_decode($data, true) ?: [];
}

function encodeData($arr) {
    return json_encode($arr);
}

// -------------------- ROUTING --------------------
$action = isset($_GET['action']) ? $_GET['action'] : 'list_bases';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    $pdo = db();

    // Create Base
    if ($postAction === 'create_base' && isset($_POST['name'])) {
        $name = trim($_POST['name']);
        if ($name) {
            $stmt = $pdo->prepare('INSERT INTO bases (name) VALUES (?)');
            $stmt->execute([$name]);
        }
        redirect('?action=list_bases');
    }

    // Delete Base
    if ($postAction === 'delete_base' && $id) {
        $stmt = $pdo->prepare('DELETE FROM bases WHERE id = ?');
        $stmt->execute([$id]);
        redirect('?action=list_bases');
    }

    // Create Table
    if ($postAction === 'create_table' && isset($_POST['base_id'], $_POST['name'])) {
        $baseId = (int)$_POST['base_id'];
        $name = trim($_POST['name']);
        if ($name && getBase($baseId)) {
            $stmt = $pdo->prepare('INSERT INTO tables (base_id, name) VALUES (?, ?)');
            $stmt->execute([$baseId, $name]);
            $tableId = $pdo->lastInsertId();
            // Handle fields (dynamic)
            $fieldNames = $_POST['field_name'] ?? [];
            $fieldTypes = $_POST['field_type'] ?? [];
            foreach ($fieldNames as $i => $fname) {
                $fname = trim($fname);
                if ($fname) {
                    $type = isset($fieldTypes[$i]) ? $fieldTypes[$i] : 'text';
                    $stmt2 = $pdo->prepare('INSERT INTO fields (table_id, name, type) VALUES (?, ?, ?)');
                    $stmt2->execute([$tableId, $fname, $type]);
                }
            }
        }
        redirect('?action=view_base&id=' . $baseId);
    }

    // Delete Table
    if ($postAction === 'delete_table' && $id) {
        $table = getTable($id);
        if ($table) {
            $baseId = $table['base_id'];
            $stmt = $pdo->prepare('DELETE FROM tables WHERE id = ?');
            $stmt->execute([$id]);
            redirect('?action=view_base&id=' . $baseId);
        }
        redirect('?action=list_bases');
    }

    // Add Field to Table
    if ($postAction === 'add_field' && isset($_POST['table_id'], $_POST['field_name'])) {
        $tableId = (int)$_POST['table_id'];
        $name = trim($_POST['field_name']);
        $type = $_POST['field_type'] ?? 'text';
        if ($name && getTable($tableId)) {
            $stmt = $pdo->prepare('INSERT INTO fields (table_id, name, type) VALUES (?, ?, ?)');
            $stmt->execute([$tableId, $name, $type]);
        }
        redirect('?action=view_table&id=' . $tableId);
    }

    // Delete Field
    if ($postAction === 'delete_field' && isset($_POST['field_id'])) {
        $fieldId = (int)$_POST['field_id'];
        $stmt = $pdo->prepare('SELECT table_id FROM fields WHERE id = ?');
        $stmt->execute([$fieldId]);
        $field = $stmt->fetch();
        if ($field) {
            $tableId = $field['table_id'];
            $stmt = $pdo->prepare('DELETE FROM fields WHERE id = ?');
            $stmt->execute([$fieldId]);
            redirect('?action=view_table&id=' . $tableId);
        }
        redirect('?action=list_bases');
    }

    // Create Record
    if ($postAction === 'create_record' && isset($_POST['table_id'])) {
        $tableId = (int)$_POST['table_id'];
        $table = getTable($tableId);
        if ($table) {
            $fields = getFields($tableId);
            $data = [];
            foreach ($fields as $field) {
                $key = 'field_' . $field['id'];
                $value = isset($_POST[$key]) ? trim($_POST[$key]) : '';
                $data[$field['name']] = $value;
            }
            $stmt = $pdo->prepare('INSERT INTO records (table_id, data) VALUES (?, ?)');
            $stmt->execute([$tableId, encodeData($data)]);
        }
        redirect('?action=view_table&id=' . $tableId);
    }

    // Update Record (via inline edit)
    if ($postAction === 'update_record' && isset($_POST['record_id'])) {
        $recordId = (int)$_POST['record_id'];
        $stmt = $pdo->prepare('SELECT table_id, data FROM records WHERE id = ?');
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();
        if ($record) {
            $tableId = $record['table_id'];
            $fields = getFields($tableId);
            $data = decodeData($record['data']);
            foreach ($fields as $field) {
                $key = 'field_' . $field['id'];
                if (isset($_POST[$key])) {
                    $data[$field['name']] = trim($_POST[$key]);
                }
            }
            $stmt = $pdo->prepare('UPDATE records SET data = ? WHERE id = ?');
            $stmt->execute([encodeData($data), $recordId]);
            redirect('?action=view_table&id=' . $tableId);
        }
        redirect('?action=list_bases');
    }

    // Delete Record
    if ($postAction === 'delete_record' && isset($_POST['record_id'])) {
        $recordId = (int)$_POST['record_id'];
        $stmt = $pdo->prepare('SELECT table_id FROM records WHERE id = ?');
        $stmt->execute([$recordId]);
        $record = $stmt->fetch();
        if ($record) {
            $tableId = $record['table_id'];
            $stmt = $pdo->prepare('DELETE FROM records WHERE id = ?');
            $stmt->execute([$recordId]);
            redirect('?action=view_table&id=' . $tableId);
        }
        redirect('?action=list_bases');
    }

    // Add Comment
    if ($postAction === 'add_comment' && isset($_POST['record_id'], $_POST['content'])) {
        $recordId = (int)$_POST['record_id'];
        $author = trim($_POST['author'] ?? 'Anonymous');
        $content = trim($_POST['content']);
        if ($content) {
            $stmt = $pdo->prepare('INSERT INTO comments (record_id, author, content) VALUES (?, ?, ?)');
            $stmt->execute([$recordId, $author, $content]);
        }
        redirect('?action=view_record&id=' . $recordId);
    }

    // Redirect to home if unknown
    redirect('?action=list_bases');
}

// -------------------- GET RENDERING --------------------
$pdo = db();

function renderHeader($title) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> · Mini Airtable</title>
    <style>
        /* ----- CSS Variables (Dark Mode) ----- */
        :root {
            --bg: #121212;
            --bg-card: #1e1e1e;
            --bg-input: #2a2a2a;
            --text: #e0e0e0;
            --text-muted: #aaa;
            --border: #333;
            --primary: #6c63ff;
            --primary-hover: #5a52d5;
            --danger: #ff5e5e;
            --danger-hover: #e04444;
            --radius: 8px;
            --shadow: 0 2px 8px rgba(0,0,0,0.5);
            --font: system-ui, -apple-system, sans-serif;
            --max-width: 900px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font);
            line-height: 1.6;
            padding: 16px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            width: 100%;
            max-width: var(--max-width);
        }
        a { color: var(--primary); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Cards */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
        }

        /* Forms */
        input, textarea, select {
            background: var(--bg-input);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 8px 12px;
            font-size: 1rem;
            width: 100%;
            max-width: 100%;
            font-family: var(--font);
        }
        input:focus, textarea:focus, select:focus {
            outline: 1px solid var(--primary);
        }
        textarea { min-height: 60px; resize: vertical; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 4px; font-weight: 500; }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-end;
        }
        .form-row .form-group { flex: 1; min-width: 120px; }
        .form-row .form-group:last-child { flex: 0 0 auto; }

        .btn {
            display: inline-block;
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.2s;
            font-weight: 500;
            text-align: center;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: var(--danger-hover); }
        .btn-sm { padding: 4px 10px; font-size: 0.85rem; }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-outline:hover { background: var(--bg-input); }

        /* Lists */
        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .list-item:last-child { border-bottom: none; }
        .list-item .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .list-item .actions form { display: inline; }

        /* Table (records) */
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }
        th, td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            word-break: break-word;
        }
        th { background: var(--bg-input); font-weight: 600; }
        tr:hover { background: rgba(255,255,255,0.03); }
        .record-actions { display: flex; gap: 4px; flex-wrap: wrap; }

        /* Inline edit form inside table */
        .inline-form { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
        .inline-form input, .inline-form select { width: auto; min-width: 80px; flex: 1; }
        .inline-form .btn { flex: 0 0 auto; }

        /* Comments */
        .comment {
            background: var(--bg-input);
            border-radius: var(--radius);
            padding: 8px 12px;
            margin-bottom: 8px;
        }
        .comment .meta { font-size: 0.8rem; color: var(--text-muted); }

        /* Utilities */
        .text-muted { color: var(--text-muted); }
        .mt-1 { margin-top: 8px; }
        .mt-2 { margin-top: 16px; }
        .mb-1 { margin-bottom: 8px; }
        .flex { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .gap-1 { gap: 4px; }
        .text-center { text-align: center; }

        /* Mobile first: already small screens */
        @media (min-width: 640px) {
            body { padding: 24px; }
            .card { padding: 24px; }
        }
        @media (min-width: 1024px) {
            .container { max-width: 1024px; }
        }
    </style>
</head>
<body>
<div class="container">
    <header style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
        <h1><a href="?action=list_bases" style="color: var(--text); text-decoration: none;">📊 Mini Airtable</a></h1>
        <div>
            <a href="?action=list_bases" class="btn btn-sm btn-outline">Bases</a>
        </div>
    </header>
    <?php
}

function renderFooter() {
    ?>
</div>
</body>
</html>
    <?php
}

// ----- ROUTE HANDLERS -----

// 1. List Bases
if ($action === 'list_bases') {
    renderHeader('Bases');
    $bases = $pdo->query('SELECT * FROM bases ORDER BY created_at DESC')->fetchAll();
    ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">All Bases</span>
        </div>
        <form method="POST" class="form-row">
            <input type="hidden" name="action" value="create_base">
            <div class="form-group" style="flex:2;">
                <input type="text" name="name" placeholder="New base name..." required>
            </div>
            <div class="form-group">
                <button type="submit" class="btn">Create</button>
            </div>
        </form>
        <?php if (empty($bases)): ?>
            <p class="text-muted">No bases yet. Create one above.</p>
        <?php else: ?>
            <div>
                <?php foreach ($bases as $base): ?>
                    <div class="list-item">
                        <a href="?action=view_base&id=<?= $base['id'] ?>" style="font-weight:500;"><?= h($base['name']) ?></a>
                        <div class="actions">
                            <span class="text-muted" style="font-size:0.8rem;"><?= $base['created_at'] ?></span>
                            <form method="POST" onsubmit="return confirm('Delete base and all tables/records?')">
                                <input type="hidden" name="action" value="delete_base">
                                <input type="hidden" name="id" value="<?= $base['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    renderFooter();
    exit;
}

// 2. View Base (list tables)
if ($action === 'view_base' && $id) {
    $base = getBase($id);
    if (!$base) { redirect('?action=list_bases'); }
    renderHeader('Base: ' . $base['name']);
    $tables = $pdo->prepare('SELECT * FROM tables WHERE base_id = ? ORDER BY created_at DESC');
    $tables->execute([$id]);
    $tables = $tables->fetchAll();
    ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">📁 <?= h($base['name']) ?></span>
            <a href="?action=list_bases" class="btn btn-sm btn-outline">← Back</a>
        </div>
        <form method="POST" class="card" style="background:var(--bg-input);">
            <input type="hidden" name="action" value="create_table">
            <input type="hidden" name="base_id" value="<?= $id ?>">
            <div class="form-group">
                <label>Table Name</label>
                <input type="text" name="name" placeholder="e.g. Projects" required>
            </div>
            <div id="fieldList">
                <div class="form-row">
                    <div class="form-group"><input type="text" name="field_name[]" placeholder="Field name" required></div>
                    <div class="form-group">
                        <select name="field_type[]">
                            <option value="text">Text</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                        </select>
                    </div>
                    <div class="form-group"><button type="button" class="btn btn-sm btn-outline" onclick="addField()">+</button></div>
                </div>
            </div>
            <button type="submit" class="btn">Create Table</button>
        </form>

        <script>
            function addField() {
                const container = document.getElementById('fieldList');
                const row = document.createElement('div');
                row.className = 'form-row';
                row.innerHTML = `
                    <div class="form-group"><input type="text" name="field_name[]" placeholder="Field name" required></div>
                    <div class="form-group">
                        <select name="field_type[]">
                            <option value="text">Text</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                        </select>
                    </div>
                    <div class="form-group"><button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">✕</button></div>
                `;
                container.appendChild(row);
            }
        </script>

        <?php if (empty($tables)): ?>
            <p class="text-muted mt-1">No tables yet.</p>
        <?php else: ?>
            <div>
                <?php foreach ($tables as $table): ?>
                    <div class="list-item">
                        <a href="?action=view_table&id=<?= $table['id'] ?>"><?= h($table['name']) ?></a>
                        <div class="actions">
                            <span class="text-muted" style="font-size:0.8rem;"><?= $table['created_at'] ?></span>
                            <form method="POST" onsubmit="return confirm('Delete table and all data?')">
                                <input type="hidden" name="action" value="delete_table">
                                <input type="hidden" name="id" value="<?= $table['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    renderFooter();
    exit;
}

// 3. View Table (records + fields)
if ($action === 'view_table' && $id) {
    $table = getTable($id);
    if (!$table) { redirect('?action=list_bases'); }
    $base = getBase($table['base_id']);
    renderHeader('Table: ' . $table['name']);
    $fields = getFields($id);
    $records = getRecords($id);
    ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">📋 <?= h($table['name']) ?></span>
            <div>
                <a href="?action=view_base&id=<?= $base['id'] ?>" class="btn btn-sm btn-outline">← Back to Base</a>
            </div>
        </div>

        <!-- Add Field -->
        <form method="POST" class="form-row" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="add_field">
            <input type="hidden" name="table_id" value="<?= $id ?>">
            <div class="form-group"><input type="text" name="field_name" placeholder="New field name" required></div>
            <div class="form-group">
                <select name="field_type">
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                </select>
            </div>
            <div class="form-group"><button type="submit" class="btn">Add Field</button></div>
        </form>

        <!-- List Fields -->
        <?php if (empty($fields)): ?>
            <p class="text-muted">No fields defined. Add one above.</p>
        <?php else: ?>
            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
                <?php foreach ($fields as $f): ?>
                    <span style="background:var(--bg-input); padding:4px 12px; border-radius:20px; display:inline-flex; gap:8px; align-items:center;">
                        <?= h($f['name']) ?> (<?= h($f['type']) ?>)
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete field?')">
                            <input type="hidden" name="action" value="delete_field">
                            <input type="hidden" name="field_id" value="<?= $f['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="padding:0 6px; font-size:0.7rem;">✕</button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Add Record Form -->
        <div class="card" style="background:var(--bg-input);">
            <h4>Add Record</h4>
            <form method="POST" class="form-row">
                <input type="hidden" name="action" value="create_record">
                <input type="hidden" name="table_id" value="<?= $id ?>">
                <?php foreach ($fields as $field): ?>
                    <div class="form-group" style="flex:1; min-width:120px;">
                        <label><?= h($field['name']) ?></label>
                        <?php if ($field['type'] === 'text'): ?>
                            <input type="text" name="field_<?= $field['id'] ?>" placeholder="<?= h($field['name']) ?>">
                        <?php elseif ($field['type'] === 'number'): ?>
                            <input type="number" name="field_<?= $field['id'] ?>" step="any">
                        <?php elseif ($field['type'] === 'date'): ?>
                            <input type="date" name="field_<?= $field['id'] ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="form-group" style="flex:0;">
                    <button type="submit" class="btn">Add Record</button>
                </div>
            </form>
        </div>

        <!-- List Records -->
        <?php if (empty($records)): ?>
            <p class="text-muted mt-1">No records yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <?php foreach ($fields as $f): ?>
                                <th><?= h($f['name']) ?></th>
                            <?php endforeach; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $rec): 
                            $data = decodeData($rec['data']);
                        ?>
                        <tr>
                            <td><?= $rec['id'] ?></td>
                            <?php foreach ($fields as $f): ?>
                                <td><?= h($data[$f['name']] ?? '') ?></td>
                            <?php endforeach; ?>
                            <td>
                                <div class="record-actions">
                                    <a href="?action=view_record&id=<?= $rec['id'] ?>" class="btn btn-sm btn-outline">View</a>
                                    <form method="POST" onsubmit="return confirm('Delete record?')">
                                        <input type="hidden" name="action" value="delete_record">
                                        <input type="hidden" name="record_id" value="<?= $rec['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
    renderFooter();
    exit;
}

// 4. View Record (details + comments)
if ($action === 'view_record' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM records WHERE id = ?');
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if (!$record) { redirect('?action=list_bases'); }
    $table = getTable($record['table_id']);
    if (!$table) { redirect('?action=list_bases'); }
    $base = getBase($table['base_id']);
    $fields = getFields($table['id']);
    $data = decodeData($record['data']);
    $comments = getComments($id);

    renderHeader('Record #' . $record['id']);
    ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">📄 Record #<?= $record['id'] ?> · <?= h($table['name']) ?></span>
            <div>
                <a href="?action=view_table&id=<?= $table['id'] ?>" class="btn btn-sm btn-outline">← Back to Table</a>
            </div>
        </div>

        <!-- Display/Edit Record -->
        <form method="POST" class="form-row">
            <input type="hidden" name="action" value="update_record">
            <input type="hidden" name="record_id" value="<?= $record['id'] ?>">
            <?php foreach ($fields as $field): ?>
                <div class="form-group" style="flex:1; min-width:120px;">
                    <label><?= h($field['name']) ?></label>
                    <?php $val = $data[$field['name']] ?? ''; ?>
                    <?php if ($field['type'] === 'text'): ?>
                        <input type="text" name="field_<?= $field['id'] ?>" value="<?= h($val) ?>">
                    <?php elseif ($field['type'] === 'number'): ?>
                        <input type="number" name="field_<?= $field['id'] ?>" value="<?= h($val) ?>" step="any">
                    <?php elseif ($field['type'] === 'date'): ?>
                        <input type="date" name="field_<?= $field['id'] ?>" value="<?= h($val) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="form-group" style="flex:0;">
                <button type="submit" class="btn">Update</button>
            </div>
        </form>

        <!-- Comments -->
        <div style="margin-top:24px;">
            <h4>💬 Comments</h4>
            <?php if (empty($comments)): ?>
                <p class="text-muted">No comments yet.</p>
            <?php else: ?>
                <?php foreach ($comments as $c): ?>
                    <div class="comment">
                        <div class="meta"><strong><?= h($c['author']) ?></strong> · <?= $c['created_at'] ?></div>
                        <div><?= nl2br(h($c['content'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="POST" class="card" style="background:var(--bg-input);">
                <input type="hidden" name="action" value="add_comment">
                <input type="hidden" name="record_id" value="<?= $record['id'] ?>">
                <div class="form-group">
                    <label>Your name</label>
                    <input type="text" name="author" placeholder="Anonymous" value="Anonymous">
                </div>
                <div class="form-group">
                    <label>Comment</label>
                    <textarea name="content" required></textarea>
                </div>
                <button type="submit" class="btn">Add Comment</button>
            </form>
        </div>
    </div>
    <?php
    renderFooter();
    exit;
}

// Fallback: go home
redirect('?action=list_bases');