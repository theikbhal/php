<?php
// Base Directory Setup
$defaultDir = '/Users/ikbhal/Desktop/php';
$baseDir    = is_dir($defaultDir) ? $defaultDir : __DIR__;
$dbFile     = rtrim($baseDir, '/') . '/planner.sqlite';

// Initialize SQLite Database
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        details TEXT DEFAULT NULL,
        list_type TEXT DEFAULT 'unorganized', -- 'urgent', 'extra', 'unorganized', 'organized'
        priority TEXT DEFAULT 'none',          -- 'top1', 'top2', 'top3', 'top5', 'top10', 'none'
        timeframe TEXT DEFAULT 'today',        -- 'now', 'today', 'week', 'month'
        status TEXT DEFAULT 'pending',         -- 'pending', 'done'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Handle AJAX / Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $title     = trim($_POST['title'] ?? '');
        $details   = trim($_POST['details'] ?? '');
        $listType  = $_POST['list_type'] ?? 'unorganized';
        $priority  = $_POST['priority'] ?? 'none';
        $timeframe = $_POST['timeframe'] ?? 'today';

        if (!empty($title)) {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO items (title, details, list_type, priority, timeframe) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $details, $listType, $priority, $timeframe]);
            } else {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE items SET title=?, details=?, list_type=?, priority=?, timeframe=? WHERE id=?");
                $stmt->execute([$title, $details, $listType, $priority, $timeframe, $id]);
            }
        }
        header("Location: index.php");
        exit;
    } elseif ($action === 'toggle_status') {
        $id = (int)$_POST['id'];
        $newStatus = $_POST['status'] === 'done' ? 'done' : 'pending';
        $stmt = $pdo->prepare("UPDATE items SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: index.php");
        exit;
    }
}

// Filters & Search
$search    = trim($_GET['q'] ?? '');
$filterList = $_GET['list'] ?? 'all';
$filterTime = $_GET['time'] ?? 'all';

// Build SQL Query
$sql = "SELECT * FROM items WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (title LIKE ? OR details LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterList !== 'all') {
    $sql .= " AND list_type = ?";
    $params[] = $filterList;
}
if ($filterTime !== 'all') {
    $sql .= " AND timeframe = ?";
    $params[] = $filterTime;
}

$sql .= " ORDER BY status ASC, 
    CASE priority 
        WHEN 'top1' THEN 1 
        WHEN 'top2' THEN 2 
        WHEN 'top3' THEN 3 
        WHEN 'top5' THEN 4 
        WHEN 'top10' THEN 5 
        ELSE 6 
    END ASC, id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Quick Stats
$stats = [
    'total'   => count($items),
    'urgent'  => count(array_filter($items, fn($i) => $i['list_type'] === 'urgent' && $i['status'] === 'pending')),
    'pending' => count(array_filter($items, fn($i) => $i['status'] === 'pending')),
    'done'    => count(array_filter($items, fn($i) => $i['status'] === 'done'))
];

// Edit Item Fetch
$editItem = null;
if (isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_id']]);
    $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡ Execution Dashboard</title>
    <!-- Canvas Confetti for Gamified Celebrations -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        :root {
            --bg: #0b0e14;
            --card: #151b23;
            --border: #2e3640;
            --text: #e6edf3;
            --muted: #8b949e;
            --accent: #1d9bf0; /* Twitter/X Blue */
            --urgent: #f85149;
            --top1: #e3b341;
            --success: #2ea043;
            --input: #0d1117;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        
        /* Dark Mode with Subtle Grid Pattern Background */
        body {
            background-color: var(--bg);
            background-image: radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 20px 20px;
            color: var(--text);
            display: flex;
            justify-content: center;
            padding: 20px 10px;
            min-height: 100vh;
        }

        .container { width: 100%; max-width: 720px; display: flex; flex-direction: column; gap: 16px; }

        /* Header & Gamified Action Bar */
        .header { display: flex; justify-content: space-between; align-items: center; background: var(--card); border: 1px solid var(--border); padding: 16px; border-radius: 12px; }
        .title-group h1 { font-size: 1.25rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .subtitle { font-size: 0.8rem; color: var(--muted); margin-top: 4px; }
        
        .btn-pick { background: linear-gradient(135deg, #f85149, #e3b341); color: #fff; border: none; padding: 8px 14px; border-radius: 20px; font-weight: bold; cursor: pointer; font-size: 0.85rem; transition: transform 0.2s, opacity 0.2s; display: flex; align-items: center; gap: 6px; }
        .btn-pick:hover { transform: scale(1.04); opacity: 0.95; }

        /* Stats Bar */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 10px; text-align: center; }
        .stat-num { font-size: 1.1rem; font-weight: bold; }
        .stat-lbl { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; margin-top: 2px; }

        /* Form Card */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
        form { display: flex; flex-direction: column; gap: 10px; }
        
        .input-group { display: flex; gap: 8px; flex-wrap: wrap; }
        input[type="text"], textarea, select {
            background: var(--input); border: 1px solid var(--border); color: var(--text); padding: 10px 12px; border-radius: 8px; outline: none; font-size: 0.9rem; width: 100%;
        }
        input[type="text"]:focus, textarea:focus, select:focus { border-color: var(--accent); }
        .select-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }

        button.btn-submit { background: var(--accent); color: #fff; border: none; padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: opacity 0.2s; }
        button.btn-submit:hover { opacity: 0.9; }

        /* Navigation / Filters */
        .nav-tabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px; border-bottom: 1px solid var(--border); }
        .tab { background: transparent; border: none; color: var(--muted); padding: 8px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .tab:hover, .tab.active { color: var(--text); background: #21262d; }
        .tab.active { border-bottom: 2px solid var(--accent); border-radius: 6px 6px 0 0; }

        /* Search Bar */
        .search-box { margin: 10px 0; }

        /* Task List Items (Twitter Style) */
        .item-list { display: flex; flex-direction: column; gap: 8px; list-style: none; }
        .item-card { background: var(--card); border: 1px solid var(--border); padding: 14px; border-radius: 10px; display: flex; gap: 12px; align-items: flex-start; transition: border-color 0.2s; }
        .item-card:hover { border-color: #484f58; }
        .item-card.done { opacity: 0.55; }
        .item-card.done .item-title { text-decoration: line-through; }

        .checkbox-custom { width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--muted); cursor: pointer; display: flex; align-items: center; justify-content: center; margin-top: 2px; flex-shrink: 0; }
        .item-card.done .checkbox-custom { background: var(--success); border-color: var(--success); }
        .item-card.done .checkbox-custom::after { content: '✓'; color: #fff; font-size: 0.75rem; font-weight: bold; }

        .item-content { flex: 1; }
        .item-title { font-weight: 600; font-size: 0.95rem; word-break: break-word; }
        .item-details { font-size: 0.85rem; color: var(--muted); margin-top: 4px; white-space: pre-line; }
        
        .badge-row { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
        .badge { font-size: 0.7rem; padding: 2px 8px; border-radius: 12px; font-weight: bold; text-transform: uppercase; }
        .badge-urgent { background: rgba(248, 81, 73, 0.2); color: var(--urgent); border: 1px solid var(--urgent); }
        .badge-extra { background: rgba(88, 166, 255, 0.15); color: #58a6ff; }
        .badge-top1 { background: rgba(227, 179, 65, 0.2); color: var(--top1); border: 1px solid var(--top1); }
        .badge-top { background: rgba(139, 148, 158, 0.2); color: var(--muted); }
        .badge-time { background: #21262d; color: var(--muted); }

        .item-actions { display: flex; gap: 8px; }
        .btn-icon { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 0.8rem; text-decoration: none; }
        .btn-icon:hover { color: var(--text); }
        .btn-icon.del:hover { color: var(--urgent); }

        /* Random Urgent Picker Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; padding: 20px; z-index: 99; }
        .modal-card { background: var(--card); border: 2px solid var(--top1); padding: 24px; border-radius: 16px; width: 100%; max-width: 450px; text-align: center; }
        .modal-card h2 { font-size: 1.2rem; color: var(--top1); margin-bottom: 12px; }
        .modal-card p { font-size: 1.1rem; font-weight: bold; margin: 16px 0; color: var(--text); }
    </style>
</head>
<body>

<div class="container">

    <!-- Header & Gamified Picker -->
    <div class="header">
        <div class="title-group">
            <h1>⚡ Task & Execution Hub</h1>
            <div class="subtitle">Organized • Priority Ranked • Date Assigned</div>
        </div>
        <button class="btn-pick" onclick="pickUrgentTask()">🎯 Pick Urgent Task</button>
    </div>

    <!-- Quick Stats Bar -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num" style="color: var(--urgent);"><?= $stats['urgent'] ?></div>
            <div class="stat-lbl">Urgent</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $stats['pending'] ?></div>
            <div class="stat-lbl">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-num" style="color: var(--success);"><?= $stats['done'] ?></div>
            <div class="stat-lbl">Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $stats['total'] ?></div>
            <div class="stat-lbl">Total</div>
        </div>
    </div>

    <!-- Add/Edit Form Card -->
    <div class="card">
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="<?= $editItem ? 'edit' : 'add' ?>">
            <?php if ($editItem): ?>
                <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
            <?php endif; ?>

            <input type="text" name="title" placeholder="What needs to be done?" value="<?= htmlspecialchars($editItem['title'] ?? '') ?>" required>
            <textarea name="details" placeholder="Add extra notes or links (optional)..." rows="2"><?= htmlspecialchars($editItem['details'] ?? '') ?></textarea>

            <div class="select-row">
                <!-- List Categorization -->
                <select name="list_type">
                    <option value="urgent" <?= ($editItem['list_type'] ?? '') === 'urgent' ? 'selected' : '' ?>>🚨 Urgent List</option>
                    <option value="unorganized" <?= ($editItem['list_type'] ?? '') === 'unorganized' ? 'selected' : '' ?>>📥 Unorganized</option>
                    <option value="organized" <?= ($editItem['list_type'] ?? '') === 'organized' ? 'selected' : '' ?>>📋 Organized List</option>
                    <option value="extra" <?= ($editItem['list_type'] ?? '') === 'extra' ? 'selected' : '' ?>>💡 Extra List</option>
                </select>

                <!-- Priority Ranking -->
                <select name="priority">
                    <option value="none" <?= ($editItem['priority'] ?? '') === 'none' ? 'selected' : '' ?>>Priority: None</option>
                    <option value="top1" <?= ($editItem['priority'] ?? '') === 'top1' ? 'selected' : '' ?>>⭐ Top 1 Pick</option>
                    <option value="top2" <?= ($editItem['priority'] ?? '') === 'top2' ? 'selected' : '' ?>>🥈 Top 2 Mark</option>
                    <option value="top3" <?= ($editItem['priority'] ?? '') === 'top3' ? 'selected' : '' ?>>🥉 Top 3</option>
                    <option value="top5" <?= ($editItem['priority'] ?? '') === 'top5' ? 'selected' : '' ?>>🔹 Top 5</option>
                    <option value="top10" <?= ($editItem['priority'] ?? '') === 'top10' ? 'selected' : '' ?>>▫️ Top 10 Mark</option>
                </select>

                <!-- Timeframe Assignment -->
                <select name="timeframe">
                    <option value="now" <?= ($editItem['timeframe'] ?? '') === 'now' ? 'selected' : '' ?>>🔥 Now</option>
                    <option value="today" <?= ($editItem['timeframe'] ?? '') === 'today' ? 'selected' : '' ?>>📅 Today</option>
                    <option value="week" <?= ($editItem['timeframe'] ?? '') === 'week' ? 'selected' : '' ?>>📆 This Week</option>
                    <option value="month" <?= ($editItem['timeframe'] ?? '') === 'month' ? 'selected' : '' ?>>🗓️ This Month</option>
                </select>
            </div>

            <button type="submit" class="btn-submit"><?= $editItem ? '✏️ Save Task Changes' : '➕ Add to Execution List' ?></button>
            <?php if ($editItem): ?>
                <a href="index.php" style="color: var(--muted); text-align: center; font-size: 0.8rem; text-decoration: none;">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Navigation Tabs & Search -->
    <div>
        <div class="nav-tabs">
            <a href="index.php" class="tab <?= $filterList === 'all' ? 'active' : '' ?>">All Items</a>
            <a href="index.php?list=urgent" class="tab <?= $filterList === 'urgent' ? 'active' : '' ?>">🚨 Urgent</a>
            <a href="index.php?list=organized" class="tab <?= $filterList === 'organized' ? 'active' : '' ?>">📋 Organized</a>
            <a href="index.php?list=unorganized" class="tab <?= $filterList === 'unorganized' ? 'active' : '' ?>">📥 Unorganized</a>
            <a href="index.php?list=extra" class="tab <?= $filterList === 'extra' ? 'active' : '' ?>">💡 Extra List</a>
        </div>

        <form method="GET" action="index.php" class="search-box">
            <?php if ($filterList !== 'all'): ?><input type="hidden" name="list" value="<?= htmlspecialchars($filterList) ?>"><?php endif; ?>
            <input type="text" name="q" placeholder="🔍 Search tasks, notes..." value="<?= htmlspecialchars($search) ?>">
        </form>
    </div>

    <!-- Task List -->
    <ul class="item-list">
        <?php if (empty($items)): ?>
            <li style="text-align: center; color: var(--muted); font-size: 0.9rem; padding: 20px;">No matching tasks or notes found.</li>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <li class="item-card <?= $item['status'] === 'done' ? 'done' : '' ?>" id="item-<?= $item['id'] ?>">
                <div class="checkbox-custom" onclick="toggleTask(<?= $item['id'] ?>, '<?= $item['status'] === 'done' ? 'pending' : 'done' ?>')"></div>
                
                <div class="item-content">
                    <div class="item-title"><?= htmlspecialchars($item['title']) ?></div>
                    <?php if (!empty($item['details'])): ?>
                        <div class="item-details"><?= htmlspecialchars($item['details']) ?></div>
                    <?php endif; ?>

                    <div class="badge-row">
                        <!-- List Type Badge -->
                        <?php if ($item['list_type'] === 'urgent'): ?>
                            <span class="badge badge-urgent">🚨 Urgent</span>
                        <?php elseif ($item['list_type'] === 'extra'): ?>
                            <span class="badge badge-extra">💡 Extra</span>
                        <?php endif; ?>

                        <!-- Priority Badge -->
                        <?php if ($item['priority'] === 'top1'): ?>
                            <span class="badge badge-top1">⭐ Top 1 Pick</span>
                        <?php elseif ($item['priority'] !== 'none'): ?>
                            <span class="badge badge-top"><?= strtoupper($item['priority']) ?></span>
                        <?php endif; ?>

                        <!-- Timeframe Badge -->
                        <span class="badge badge-time">⏰ <?= ucfirst($item['timeframe']) ?></span>
                    </div>
                </div>

                <div class="item-actions">
                    <a href="index.php?edit_id=<?= $item['id'] ?>" class="btn-icon">Edit</a>
                    <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Delete item?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <button type="submit" class="btn-icon del">Delete</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

</div>

<!-- Gamified Picker Modal -->
<div class="modal-overlay" id="pickModal">
    <div class="modal-card">
        <h2>🎯 Your Focus Goal</h2>
        <p id="pickedTaskTitle">Selecting best urgent task...</p>
        <button class="btn-submit" onclick="closeModal()" style="width: 100%;">Let's Do It! 🔥</button>
    </div>
</div>

<script>
// Gamified Status Toggle with Confetti on Completion
function toggleTask(id, newStatus) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    formData.append('status', newStatus);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (newStatus === 'done') {
                confetti({ particleCount: 80, spread: 60, origin: { y: 0.7 } });
            }
            window.location.reload();
        }
    });
}

// Gamified Urgent Task Picker
const pendingUrgentTasks = <?= json_encode(array_values(array_filter($items, fn($i) => $i['status'] === 'pending' && ($i['list_type'] === 'urgent' || $i['priority'] === 'top1')))) ?>;

function pickUrgentTask() {
    const modal = document.getElementById('pickModal');
    const titleEl = document.getElementById('pickedTaskTitle');
    
    if (pendingUrgentTasks.length === 0) {
        titleEl.textContent = "🎉 No pending urgent tasks right now! Great job!";
    } else {
        const randomPick = pendingUrgentTasks[Math.floor(Math.random() * pendingUrgentTasks.length)];
        titleEl.textContent = "👉 " + randomPick.title;
        confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
    }
    
    modal.style.display = 'flex';
}

function closeModal() {
    document.getElementById('pickModal').style.display = 'none';
}
</script>

</body>
</html>