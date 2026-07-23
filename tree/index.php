<?php
// index.php - Dynalist Pro with Collapsibles, Shortcuts, and Command Palette
$dbPath = __DIR__ . '/dynalist.sqlite';
$db = new SQLite3($dbPath);

$db->exec("
    CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER DEFAULT 0,
        content TEXT NOT NULL,
        has_checkbox INTEGER DEFAULT 0,
        completed INTEGER DEFAULT 0,
        collapsed INTEGER DEFAULT 0,
        position INTEGER DEFAULT 0
    )
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $parentId = intval($_POST['parent_id'] ?? 0);
        $content = trim($_POST['content'] ?? 'New Item');
        $stmt = $db->prepare("INSERT INTO items (parent_id, content, position) VALUES (:pid, :content, (SELECT COALESCE(MAX(position),0)+1 FROM items WHERE parent_id = :pid))");
        $stmt->bindValue(':pid', $parentId, SQLITE3_INTEGER);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['status' => 'success', 'id' => $db->lastInsertRowID()]);
        exit;
    }

    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $stmt = $db->prepare("UPDATE items SET content = :content WHERE id = :id");
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'toggle_collapse') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE items SET collapsed = CASE WHEN collapsed = 1 THEN 0 ELSE 1 END WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'toggle_checkbox') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE items SET has_checkbox = CASE WHEN has_checkbox = 1 THEN 0 ELSE 1 END WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'toggle_complete') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE items SET completed = CASE WHEN completed = 1 THEN 0 ELSE 1 END WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'reparent') {
        $id = intval($_POST['id'] ?? 0);
        $newParent = intval($_POST['parent_id'] ?? 0);
        $stmt = $db->prepare("UPDATE items SET parent_id = :pid WHERE id = :id");
        $stmt->bindValue(':pid', $newParent, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM items WHERE id = :id OR parent_id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'search') {
        $q = trim($_POST['query'] ?? '');
        $stmt = $db->prepare("SELECT id, content FROM items WHERE content LIKE :q LIMIT 10");
        $stmt->bindValue(':q', "%$q%", SQLITE3_TEXT);
        $res = $stmt->execute();
        $out = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $out[] = $row; }
        echo json_encode(['status' => 'success', 'results' => $out]);
        exit;
    }
}

// Seed Database
if ($db->querySingle("SELECT COUNT(*) FROM items") === 0) {
    $db->exec("INSERT INTO items (id, parent_id, content, position) VALUES (1, 0, 'Welcome to Dynalist Pro 🚀', 1)");
    $db->exec("INSERT INTO items (id, parent_id, content, position) VALUES (2, 1, 'Press Ctrl+K to search or run commands', 1)");
    $db->exec("INSERT INTO items (id, parent_id, content, position) VALUES (3, 1, 'Use the menu (⋮) to add optional checkboxes or child nodes', 2)");
}

$results = $db->query("SELECT * FROM items ORDER BY parent_id ASC, position ASC");
$items = [];
while ($row = $results->fetchArray(SQLITE3_ASSOC)) { $items[] = $row; }

function renderTree($items, $parentId = 0) {
    $filtered = array_filter($items, fn($i) => $i['parent_id'] == $parentId);
    if (empty($filtered)) return;
    echo '<ul>';
    foreach ($filtered as $item) {
        $children = array_filter($items, fn($i) => $i['parent_id'] == $item['id']);
        $hasChildren = !empty($children);
        $isCollapsed = $item['collapsed'] == 1;
        $arrow = $hasChildren ? ($isCollapsed ? '▶' : '▼') : '•';
        $doneClass = $item['completed'] ? 'completed' : '';
        
        echo "<li id='node-{$item['id']}' data-id='{$item['id']}' data-parent='{$item['parent_id']}'>";
        echo "<div class='node-row' draggable='true' data-id='{$item['id']}'>";
        
        // Collapse/Expand Arrow or Bullet
        echo "<span class='toggle-arrow " . ($hasChildren ? 'has-children' : '') . "' data-id='{$item['id']}'>{$arrow}</span>";
        
        // Optional Checkbox
        if ($item['has_checkbox']) {
            $checked = $item['completed'] ? 'checked' : '';
            echo "<input type='checkbox' class='toggle-check' data-id='{$item['id']}' {$checked} />";
        }

        // Editable Content
        echo "<span class='content {$doneClass}' contenteditable='true' data-id='{$item['id']}'>" . htmlspecialchars($item['content']) . "</span>";
        
        // Action Menu Button
        echo "<div class='menu-wrap'>";
        echo "<button class='btn-menu' data-id='{$item['id']}'>⋮</button>";
        echo "<div class='dropdown' id='dropdown-{$item['id']}'>";
        echo "<button class='action-add-child' data-id='{$item['id']}'>+ Add Child (Shift+Enter)</button>";
        echo "<button class='action-add-check' data-id='{$item['id']}'>" . ($item['has_checkbox'] ? 'Remove Checkbox' : 'Add Checkbox') . "</button>";
        echo "<button class='action-del' data-id='{$item['id']}'>Delete (Ctrl+Shift+D)</button>";
        echo "</div>";
        echo "</div>";

        echo "</div>";

        // Render sub-tree if not collapsed
        if (!$isCollapsed) {
            renderTree($items, $item['id']);
        }
        echo "</li>";
    }
    echo '</ul>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynalist Pro</title>
    <style>
        :root { --bg: #121214; --card-bg: #1e1e24; --text: #e1e1e6; --accent: #7c3aed; --muted: #a1a1aa; --line: #27272a; }
        body { margin: 0; padding: 1.5rem; font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); display: flex; justify-content: center; }
        .container { width: 100%; max-width: 720px; background: var(--card-bg); border-radius: 12px; padding: 1.5rem; border: 1px solid var(--line); }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); padding-bottom: 0.75rem; margin-bottom: 1rem; }
        h1 { font-size: 1.25rem; margin: 0; }
        .kbd-hint { font-size: 0.8rem; background: var(--line); padding: 0.2rem 0.5rem; border-radius: 4px; color: var(--muted); }
        ul { list-style: none; padding-left: 1.2rem; margin: 0; border-left: 1px solid var(--line); }
        .container > #tree > ul { padding-left: 0; border-left: none; }
        li { margin: 0.3rem 0; }
        .node-row { display: flex; align-items: center; gap: 0.4rem; padding: 0.2rem; border-radius: 4px; }
        .node-row:hover { background: rgba(255,255,255,0.02); }
        .node-row.drag-over { background: var(--line); border: 1px dashed var(--accent); }
        .toggle-arrow { cursor: pointer; width: 18px; text-align: center; color: var(--muted); user-select: none; }
        .toggle-arrow.has-children { color: var(--accent); font-weight: bold; }
        .content { outline: none; flex-grow: 1; padding: 0.2rem 0.4rem; border-radius: 4px; }
        .content:focus { background: #27272a; }
        .completed { text-decoration: line-through; color: var(--muted); }
        
        /* Dropdown Menu */
        .menu-wrap { position: relative; }
        .btn-menu { border: none; background: none; color: var(--muted); cursor: pointer; opacity: 0.2; }
        .node-row:hover .btn-menu { opacity: 1; }
        .dropdown { display: none; position: absolute; right: 0; top: 100%; background: #27272a; border: 1px solid var(--line); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.5); z-index: 10; width: 180px; }
        .dropdown.show { display: block; }
        .dropdown button { width: 100%; text-align: left; background: none; border: none; color: var(--text); padding: 0.5rem; font-size: 0.8rem; cursor: pointer; }
        .dropdown button:hover { background: var(--accent); }

        /* Command Palette Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); align-items: flex-start; justify-content: center; padding-top: 5rem; z-index: 100; }
        .modal-content { background: var(--card-bg); border: 1px solid var(--line); padding: 1rem; border-radius: 8px; width: 100%; max-width: 500px; }
        .search-input { width: 100%; background: #121214; border: 1px solid var(--line); color: var(--text); padding: 0.75rem; border-radius: 6px; outline: none; box-sizing: border-box; }
        .search-results { margin-top: 0.5rem; max-height: 250px; overflow-y: auto; }
        .search-item { padding: 0.5rem; border-bottom: 1px solid var(--line); cursor: pointer; font-size: 0.9rem; }
        .search-item:hover { background: var(--accent); }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>Dynalist Pro</h1>
        <div>
            <span class="kbd-hint">Press Ctrl + K to Search</span>
        </div>
    </header>

    <div id="tree"><?php renderTree($items, 0); ?></div>
</div>

<!-- Command Palette Modal -->
<div class="modal" id="cmdModal">
    <div class="modal-content">
        <input type="text" class="search-input" id="cmdSearch" placeholder="Type to search nodes or jump..." autofocus />
        <div class="search-results" id="searchResults"></div>
    </div>
</div>

<script>
const api = (action, data) => {
    const fd = new FormData();
    fd.append('action', action);
    for (let k in data) fd.append(k, data[k]);
    return fetch('index.php', { method: 'POST', body: fd }).then(r => r.json());
};

// Global Keyboard Shortcuts
document.addEventListener('keydown', e => {
    // Command Palette (Ctrl+K)
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        const modal = document.getElementById('cmdModal');
        modal.style.display = modal.style.display === 'flex' ? 'none' : 'flex';
        if (modal.style.display === 'flex') document.getElementById('cmdSearch').focus();
    }

    // Node-level shortcuts
    if (e.target.classList.contains('content')) {
        const currentLi = e.target.closest('li');
        const currentId = currentLi.dataset.id;
        const parentId = currentLi.dataset.parent;

        // Shift + Enter -> Append Child Node
        if (e.key === 'Enter' && e.shiftKey) {
            e.preventDefault();
            api('create', { parent_id: currentId, content: '' }).then(() => location.reload());
        }
        // Enter -> Create Sibling Node
        else if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            api('create', { parent_id: parentId, content: '' }).then(() => location.reload());
        }

        // Tab -> Indent
        if (e.key === 'Tab' && !e.shiftKey) {
            e.preventDefault();
            const prevLi = currentLi.previousElementSibling;
            if (prevLi) api('reparent', { id: currentId, parent_id: prevLi.dataset.id }).then(() => location.reload());
        }

        // Shift + Tab -> Outdent
        if (e.key === 'Tab' && e.shiftKey) {
            e.preventDefault();
            const parentLi = currentLi.parentElement.closest('li');
            const grandParentId = parentLi ? parentLi.dataset.parent : 0;
            api('reparent', { id: currentId, parent_id: grandParentId }).then(() => location.reload());
        }

        // Ctrl + Shift + D -> Delete Node
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'd') {
            e.preventDefault();
            api('delete', { id: currentId }).then(() => currentLi.remove());
        }
    }
});

// Search Execution
document.getElementById('cmdSearch').addEventListener('input', e => {
    const q = e.target.value.trim();
    if (!q) { document.getElementById('searchResults').innerHTML = ''; return; }
    api('search', { query: q }).then(res => {
        const container = document.getElementById('searchResults');
        container.innerHTML = res.results.map(r => `
            <div class="search-item" onclick="jumpToNode(${r.id})">${r.content}</div>
        `).join('');
    });
});

function jumpToNode(id) {
    document.getElementById('cmdModal').style.display = 'none';
    const el = document.getElementById(`node-${id}`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const text = el.querySelector('.content');
        text.focus();
    }
}

// Click Actions
document.addEventListener('click', e => {
    // Close drop-downs when clicking outside
    if (!e.target.classList.contains('btn-menu')) {
        document.querySelectorAll('.dropdown').forEach(d => d.classList.remove('show'));
    }

    // Toggle Collapse
    if (e.target.classList.contains('toggle-arrow') && e.target.classList.contains('has-children')) {
        api('toggle_collapse', { id: e.target.dataset.id }).then(() => location.reload());
    }

    // Action Menu Toggle
    if (e.target.classList.contains('btn-menu')) {
        const dropdown = document.getElementById(`dropdown-${e.target.dataset.id}`);
        dropdown.classList.toggle('show');
    }

    // Menu Actions
    if (e.target.classList.contains('action-add-child')) {
        api('create', { parent_id: e.target.dataset.id, content: '' }).then(() => location.reload());
    }
    if (e.target.classList.contains('action-add-check')) {
        api('toggle_checkbox', { id: e.target.dataset.id }).then(() => location.reload());
    }
    if (e.target.classList.contains('action-del')) {
        api('delete', { id: e.target.dataset.id }).then(() => location.reload());
    }
});

// Checkbox Completion
document.addEventListener('change', e => {
    if (e.target.classList.contains('toggle-check')) {
        api('toggle_complete', { id: e.target.dataset.id }).then(() => {
            const content = e.target.parentElement.querySelector('.content');
            content.classList.toggle('completed', e.target.checked);
        });
    }
});

// Auto Save Content
document.addEventListener('focusout', e => {
    if (e.target.classList.contains('content')) {
        api('update', { id: e.target.dataset.id, content: e.target.innerText });
    }
});

// Drag and Drop (Any Node to Any Node)
let draggedId = null;

document.addEventListener('dragstart', e => {
    const row = e.target.closest('.node-row');
    if (row) {
        draggedId = row.dataset.id;
        e.dataTransfer.setData('text/plain', draggedId);
    }
});

document.addEventListener('dragover', e => {
    e.preventDefault();
    const row = e.target.closest('.node-row');
    if (row && row.dataset.id !== draggedId) {
        row.classList.add('drag-over');
    }
});

document.addEventListener('dragleave', e => {
    const row = e.target.closest('.node-row');
    if (row) row.classList.remove('drag-over');
});

document.addEventListener('drop', e => {
    e.preventDefault();
    document.querySelectorAll('.node-row').forEach(r => r.classList.remove('drag-over'));
    const targetRow = e.target.closest('.node-row');
    if (targetRow && draggedId && targetRow.dataset.id !== draggedId) {
        api('reparent', { id: draggedId, parent_id: targetRow.dataset.id }).then(() => location.reload());
    }
});
</script>
</body>
</html>