<?php
// Set default base directory (falls back to current script directory if path doesn't exist)
$defaultDir = '/Users/ikbhal/Desktop/php';
$baseDir    = is_dir($defaultDir) ? $defaultDir : __DIR__;
$dbFile     = rtrim($baseDir, '/') . '/app.sqlite';

// Initialize SQLite Database
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Subfolder Helper Function
function getSubfolders($dir) {
    $folders = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir($dir . '/' . $item) && $item[0] !== '.') {
                $folders[] = $item;
            }
        }
    }
    sort($folders);
    return $folders;
}

$alertMessage = '';
$alertType = '';

// Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Create Template Subfolder
    if ($action === 'create_folder') {
        $folderName = trim($_POST['folder_name'] ?? '');

        if (empty($folderName) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $folderName)) {
            $alertMessage = "Invalid folder name! Use letters, numbers, hyphens, or underscores.";
            $alertType = "error";
        } else {
            $targetPath = rtrim($baseDir, '/') . '/' . $folderName;
            if (file_exists($targetPath)) {
                $alertMessage = "Subfolder '$folderName' already exists! Choose a different name.";
                $alertType = "error";
            } else {
                mkdir($targetPath, 0755, true);
                touch($targetPath . '/index.php');
                touch($targetPath . '/readme.md');
                touch($targetPath . '/generate.md');
                $alertMessage = "Created '$folderName' in $baseDir with empty index.php, readme.md, and generate.md!";
                $alertType = "success";
            }
        }
    }
    // Action 2: SQLite Notes CRUD
    elseif ($action === 'add_note') {
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if (!empty($title)) {
            $stmt = $pdo->prepare("INSERT INTO notes (title, content) VALUES (?, ?)");
            $stmt->execute([$title, $content]);
        }
        header("Location: index.php");
        exit;
    } elseif ($action === 'edit_note') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if ($id > 0 && !empty($title)) {
            $stmt = $pdo->prepare("UPDATE notes SET title = ?, content = ? WHERE id = ?");
            $stmt->execute([$title, $content, $id]);
        }
        header("Location: index.php");
        exit;
    } elseif ($action === 'delete_note') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ?");
            $stmt->execute([$id]);
        }
        header("Location: index.php");
        exit;
    }
}

// Fetch Data for Web Display
$notes = $pdo->query("SELECT * FROM notes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$existingFolders = getSubfolders($baseDir);

$editNote = null;
if (isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM notes WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_id']]);
    $editNote = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Web & Template Dashboard</title>
    <style>
        :root {
            --bg: #0d1117;
            --card: #161b22;
            --border: #30363d;
            --text: #c9d1d9;
            --accent: #2ea043;
            --blue: #58a6ff;
            --danger: #f85149;
            --input: #010409;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: var(--bg); color: var(--text); display: flex; justify-content: center; padding: 30px 10px; min-height: 100vh; }
        .wrapper { width: 100%; max-width: 600px; display: flex; flex-direction: column; gap: 20px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; }
        h1, h2 { font-size: 1.1rem; margin-bottom: 12px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }
        .path-badge { font-size: 0.75rem; font-family: monospace; color: #8b949e; background: #0d1117; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border); margin-bottom: 12px; display: inline-block; word-break: break-all; }
        form { display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px; }
        input, textarea { background: var(--input); border: 1px solid var(--border); color: var(--text); padding: 10px; border-radius: 6px; outline: none; }
        textarea { resize: vertical; min-height: 70px; }
        button { background: var(--accent); color: #fff; border: none; padding: 10px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        button:hover { opacity: 0.9; }
        .alert { padding: 10px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 10px; }
        .alert.error { background: rgba(248, 81, 73, 0.15); border: 1px solid var(--danger); color: var(--danger); }
        .alert.success { background: rgba(46, 160, 67, 0.15); border: 1px solid var(--accent); color: var(--accent); }
        ul { list-style: none; }
        .item { border: 1px solid var(--border); padding: 12px 14px; border-radius: 6px; margin-bottom: 8px; background: #0d1117; display: flex; justify-content: space-between; align-items: flex-start; }
        .item-title { font-weight: 600; font-size: 0.95rem; }
        .item-desc { font-size: 0.85rem; color: #8b949e; margin-top: 4px; white-space: pre-line; }
        .actions { display: flex; gap: 8px; font-size: 0.8rem; }
        .actions a { color: var(--blue); text-decoration: none; }
        .btn-del { background: none; border: none; color: #8b949e; cursor: pointer; font-size: 0.8rem; }
        .btn-del:hover { color: var(--danger); }
    </style>
</head>
<body>
<div class="wrapper">

    <!-- Section 1: Subfolder Generator -->
    <div class="card">
        <h1>📁 Subfolder Generator</h1>
        <div class="path-badge">Target: <?= htmlspecialchars($baseDir) ?></div>

        <?php if ($alertMessage): ?>
            <div class="alert <?= $alertType ?>"><?= htmlspecialchars($alertMessage) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="create_folder">
            <input type="text" name="folder_name" placeholder="New folder name (e.g., wins, resourceful)" required>
            <button type="submit">➕ Create Subfolder</button>
        </form>

        <h3 style="font-size: 0.85rem; color: #8b949e; margin: 12px 0 6px;">Existing Folders:</h3>
        <ul>
            <?php if (empty($existingFolders)): ?>
                <li style="color: #8b949e; font-size: 0.85rem;">No subfolders found.</li>
            <?php endif; ?>
            <?php foreach ($existingFolders as $folder): ?>
                <li style="padding: 6px 10px; border: 1px solid var(--border); border-radius: 4px; margin-bottom: 4px; font-size: 0.85rem; background: #0d1117;">📂 <?= htmlspecialchars($folder) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Section 2: SQLite Quick Notes -->
    <div class="card">
        <h2>⚡ SQLite Quick Notes</h2>
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="<?= $editNote ? 'edit_note' : 'add_note' ?>">
            <?php if ($editNote): ?>
                <input type="hidden" name="id" value="<?= $editNote['id'] ?>">
            <?php endif; ?>

            <input type="text" name="title" placeholder="Note Title..." value="<?= htmlspecialchars($editNote['title'] ?? '') ?>" required>
            <textarea name="content" placeholder="Optional details..."><?= htmlspecialchars($editNote['content'] ?? '') ?></textarea>
            
            <button type="submit"><?= $editNote ? '✏️ Save Note' : '➕ Add Note' ?></button>
            <?php if ($editNote): ?>
                <a href="index.php" style="color: #8b949e; text-align: center; font-size: 0.8rem; text-decoration: none; margin-top: 5px;">Cancel Edit</a>
            <?php endif; ?>
        </form>

        <ul>
            <?php if (empty($notes)): ?>
                <li style="text-align: center; color: #8b949e; font-size: 0.85rem; padding: 15px;">No notes found.</li>
            <?php endif; ?>
            <?php foreach ($notes as $note): ?>
                <li class="item">
                    <div>
                        <div class="item-title"><?= htmlspecialchars($note['title']) ?></div>
                        <?php if (!empty($note['content'])): ?>
                            <div class="item-desc"><?= htmlspecialchars($note['content']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="actions">
                        <a href="index.php?edit_id=<?= $note['id'] ?>">Edit</a>
                        <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Delete note?');">
                            <input type="hidden" name="action" value="delete_note">
                            <input type="hidden" name="id" value="<?= $note['id'] ?>">
                            <button type="submit" class="btn-del">Delete</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

</div>
</body>
</html>