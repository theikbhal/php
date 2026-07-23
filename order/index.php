<?php
// --- 1. DATABASE SETUP & INITIALIZATION ---
$dbFile = __DIR__ . '/tasks.db';
$pdo = new PDO("sqlite:$dbFile");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    is_ordered INTEGER DEFAULT 0,
    position INTEGER DEFAULT 0,
    completed INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// --- 2. ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $is_ordered = (int)($_POST['is_ordered'] ?? 0);
        if ($title !== '') {
            $stmt = $pdo->prepare("INSERT INTO tasks (title, is_ordered) VALUES (?, ?)");
            $stmt->execute([$title, $is_ordered]);
        }
    } elseif ($action === 'bulk_import') {
        $text = $_POST['bulk_text'] ?? '';
        $lines = explode("\n", str_replace("\r", "", $text));
        $stmt = $pdo->prepare("INSERT INTO tasks (title, is_ordered) VALUES (?, 0)");
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') $stmt->execute([$trimmed]);
        }
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
    } elseif ($action === 'toggle') {
        $stmt = $pdo->prepare("UPDATE tasks SET completed = NOT completed WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
    } elseif ($action === 'reorder') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        $stmt = $pdo->prepare("UPDATE tasks SET position = ? WHERE id = ?");
        foreach ($order as $pos => $id) {
            $stmt->execute([$pos, (int)$id]);
        }
    } elseif ($action === 'move_list') {
        $id = (int)$_POST['id'];
        $to_ordered = (int)$_POST['to_ordered'];
        $stmt = $pdo->prepare("UPDATE tasks SET is_ordered = ? WHERE id = ?");
        $stmt->execute([$to_ordered, $id]);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- 3. EXPORT HANDLERS ---
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    $tasks = $pdo->query("SELECT * FROM tasks ORDER BY is_ordered DESC, position ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    if ($type === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="tasks.json"');
        echo json_encode($tasks, JSON_PRETTY_PRINT);
        exit;
    } elseif ($type === 'md' || $type === 'txt') {
        header('Content-Type: text/plain');
        header("Content-Disposition: attachment; filename=\"tasks.{$type}\"");
        foreach ($tasks as $t) {
            $status = $t['completed'] ? '[x]' : '[ ]';
            $listTag = $t['is_ordered'] ? 'Focus List' : 'Backlog';
            echo "{$status} {$t['title']} ({$listTag})\n";
        }
        exit;
    }
}

// --- 4. DATA FETCHING ---
$orderedTasks = $pdo->query("SELECT * FROM tasks WHERE is_ordered = 1 ORDER BY position ASC, id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$unorderedTasks = $pdo->query("SELECT * FROM tasks WHERE is_ordered = 0 ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$completedCount = $pdo->query("SELECT COUNT(*) FROM tasks WHERE completed = 1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FocusFlow — Minimalist Gamified Task Engine</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        body { background-color: #09090b; color: #f4f4f5; font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="min-h-screen p-4 sm:p-8 flex flex-col justify-between max-w-4xl mx-auto">

    <!-- Header & Gamification Score -->
    <header class="flex justify-between items-center border-b border-zinc-800 pb-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                ⚡ FocusFlow
            </h1>
            <p class="text-xs text-zinc-400">Low-friction, high-dopamine execution space.</p>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 rounded-lg px-4 py-2 flex items-center gap-3">
            <span class="text-xl">🔥</span>
            <div>
                <div class="text-xs text-zinc-400 uppercase font-bold tracking-wider">Completed</div>
                <div class="text-lg font-black text-amber-400" id="score-counter"><?= $completedCount ?></div>
            </div>
        </div>
    </header>

    <main class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- ORDERED FOCUS LIST (MAX 10) -->
        <section class="bg-zinc-900/50 border border-zinc-800 rounded-xl p-4 flex flex-col">
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-semibold text-amber-400 text-sm tracking-wide uppercase flex items-center gap-2">
                    🎯 Focus Top 10 (Ordered)
                </h2>
                <span class="text-xs px-2 py-0.5 rounded bg-amber-400/10 text-amber-400 font-mono"><?= count($orderedTasks) ?>/10</span>
            </div>

            <?php if (count($orderedTasks) < 10): ?>
            <form method="POST" class="mb-4">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="is_ordered" value="1">
                <input type="text" name="title" placeholder="+ Add high-priority task..." required
                       class="w-full bg-zinc-950 border border-zinc-800 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-amber-400">
            </form>
            <?php else: ?>
                <div class="text-xs text-amber-500/80 mb-3 italic">⚠️ Focus list full (Max 10). Finish one to add more!</div>
            <?php endif; ?>

            <ul id="ordered-list" class="space-y-2 flex-grow">
                <?php foreach ($orderedTasks as $t): ?>
                <li data-id="<?= $t['id'] ?>" class="p-3 bg-zinc-950 border border-zinc-800 rounded-lg flex items-center justify-between group cursor-grab active:cursor-grabbing">
                    <div class="flex items-center gap-3 overflow-hidden">
                        <span class="text-zinc-600 font-mono text-xs">⋮⋮</span>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" onclick="triggerCelebration(event, <?= $t['completed'] ? 'false' : 'true' ?>)" 
                                    class="w-5 h-5 rounded border <?= $t['completed'] ? 'bg-amber-400 border-amber-400 text-black' : 'border-zinc-700' ?> flex items-center justify-center text-xs font-bold">
                                <?= $t['completed'] ? '✓' : '' ?>
                            </button>
                        </form>
                        <span class="text-sm truncate <?= $t['completed'] ? 'line-through text-zinc-500' : 'text-zinc-200' ?>"><?= htmlspecialchars($t['title']) ?></span>
                    </div>
                    <div class="flex items-center gap-1 opacity-80 group-hover:opacity-100 transition">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="move_list">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="to_ordered" value="0">
                            <button title="Demote to Backlog" class="text-xs text-zinc-500 hover:text-zinc-300 p-1">⬇️</button>
                        </form>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button class="text-xs text-zinc-600 hover:text-red-400 p-1">✕</button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- UNORDERED BACKLOG LIST (MAX 10 DISPLAYED) -->
        <section class="bg-zinc-900/50 border border-zinc-800 rounded-xl p-4 flex flex-col">
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-semibold text-zinc-400 text-sm tracking-wide uppercase flex items-center gap-2">
                    📥 Backlog Dump (Unordered)
                </h2>
                <span class="text-xs px-2 py-0.5 rounded bg-zinc-800 text-zinc-400 font-mono"><?= count($unorderedTasks) ?></span>
            </div>

            <form method="POST" class="mb-4">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="is_ordered" value="0">
                <input type="text" name="title" placeholder="+ Quick brain dump item..." required
                       class="w-full bg-zinc-950 border border-zinc-800 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-zinc-500">
            </form>

            <ul class="space-y-2 flex-grow">
                <?php foreach ($unorderedTasks as $t): ?>
                <li class="p-3 bg-zinc-950 border border-zinc-800/80 rounded-lg flex items-center justify-between group">
                    <div class="flex items-center gap-3 overflow-hidden">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" onclick="triggerCelebration(event, <?= $t['completed'] ? 'false' : 'true' ?>)"
                                    class="w-5 h-5 rounded border <?= $t['completed'] ? 'bg-zinc-500 border-zinc-500 text-black' : 'border-zinc-700' ?> flex items-center justify-center text-xs font-bold">
                                <?= $t['completed'] ? '✓' : '' ?>
                            </button>
                        </form>
                        <span class="text-sm truncate <?= $t['completed'] ? 'line-through text-zinc-600' : 'text-zinc-400' ?>"><?= htmlspecialchars($t['title']) ?></span>
                    </div>
                    <div class="flex items-center gap-1 opacity-80 group-hover:opacity-100 transition">
                        <?php if (count($orderedTasks) < 10): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="move_list">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="to_ordered" value="1">
                            <button title="Promote to Focus List" class="text-xs text-zinc-500 hover:text-amber-400 p-1">⬆️</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button class="text-xs text-zinc-600 hover:text-red-400 p-1">✕</button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>

            <!-- Bulk Import Collapse/Panel -->
            <details class="mt-4 border-t border-zinc-800 pt-3 text-xs text-zinc-500">
                <summary class="cursor-pointer hover:text-zinc-300">⚡ Bulk Line Import</summary>
                <form method="POST" class="mt-2 space-y-2">
                    <input type="hidden" name="action" value="bulk_import">
                    <textarea name="bulk_text" rows="3" placeholder="Paste multiple lines here..." class="w-full bg-zinc-950 border border-zinc-800 rounded p-2 text-zinc-200 text-xs focus:outline-none"></textarea>
                    <button type="submit" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-200 px-3 py-1 rounded text-xs font-medium">Import to Backlog</button>
                </form>
            </details>
        </section>

    </main>

    <!-- Footer Controls & Exports -->
    <footer class="mt-8 border-t border-zinc-800 pt-4 flex flex-wrap items-center justify-between text-xs text-zinc-500 gap-4">
        <div>Single-File SQLite Engine</div>
        <div class="flex gap-3">
            <span>Export:</span>
            <a href="?export=json" class="hover:text-amber-400 underline">JSON</a>
            <a href="?export=md" class="hover:text-amber-400 underline">Markdown</a>
            <a href="?export=txt" class="hover:text-amber-400 underline">Text</a>
        </div>
    </footer>

    <script>
        // Drag and drop reordering for Focus List
        const el = document.getElementById('ordered-list');
        if (el) {
            Sortable.create(el, {
                animation: 150,
                ghostClass: 'opacity-30',
                onEnd: function () {
                    const order = Array.from(el.children).map(li => li.getAttribute('data-id'));
                    const formData = new FormData();
                    formData.append('action', 'reorder');
                    formData.append('order', JSON.stringify(order));
                    fetch(window.location.href, { method: 'POST', body: formData });
                }
            });
        }

        // Gamified Celebration Effect
        function triggerCelebration(event, status) {
            if (status) {
                confetti({
                    particleCount: 50,
                    spread: 60,
                    origin: { y: 0.7 },
                    colors: ['#fbbf24', '#f59e0b', '#d97706']
                });
            }
        }
    </script>
</body>
</html>