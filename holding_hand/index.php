<?php
// Initialize SQLite Database
$dbFile = __DIR__ . '/holding_hand.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS parked_ideas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL, -- 'side_idea', 'sudden_thought', 'micro_task', 'custom'
        custom_category TEXT DEFAULT NULL,
        idea_text TEXT NOT NULL,
        pomodoro_min INTEGER DEFAULT 15,
        status TEXT DEFAULT 'parked', -- 'parked', 'in_pomo', 'harvested', 'discarded'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Handle Form Submissions & Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $category        = $_POST['category'] ?? 'side_idea';
        $custom_category = trim($_POST['custom_category'] ?? '');
        $idea_text       = trim($_POST['idea_text'] ?? '');
        $pomodoro_min    = (int)($_POST['pomodoro_min'] ?? 15);

        if (!empty($idea_text)) {
            $stmt = $pdo->prepare("INSERT INTO parked_ideas (category, custom_category, idea_text, pomodoro_min) VALUES (?, ?, ?, ?)");
            $stmt->execute([$category, $custom_category, $idea_text, $pomodoro_min]);
        }
    } elseif ($action === 'update_status') {
        $id     = (int)$_POST['id'];
        $status = $_POST['status'];
        $stmt   = $pdo->prepare("UPDATE parked_ideas SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
    }

    header("Location: index.php");
    exit;
}

// Fetch Logged Ideas
$entries = $pdo->query("SELECT * FROM parked_ideas ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Counters
$totalParked = count(array_filter($entries, fn($e) => $e['status'] === 'parked'));
$totalHarvested = count(array_filter($entries, fn($e) => $e['status'] === 'harvested'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holding Hand — Side Idea Park</title>
    <style>
        :root {
            --bg-color: #0f1419;
            --card-bg: #161e27;
            --border-color: #2f3336;
            --text-primary: #e7e9ea;
            --text-secondary: #71767b;
            --accent-blue: #1d9bf0;
            --accent-purple: #7856ff;
            --accent-green: #00ba7c;
            --accent-gold: #ffd700;
            --input-bg: #000000;
        }

        [data-theme="light"] {
            --bg-color: #f7f9f9;
            --card-bg: #ffffff;
            --border-color: #eff3f4;
            --text-primary: #0f1419;
            --text-secondary: #536471;
            --accent-blue: #1d9bf0;
            --accent-purple: #7856ff;
            --accent-green: #00ba7c;
            --accent-gold: #d9a000;
            --input-bg: #f7f9f9;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-primary); display: flex; justify-content: center; min-height: 100vh; padding: 0 10px; transition: background-color 0.2s, color 0.2s; }
        .container { width: 100%; max-width: 600px; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); min-height: 100vh; padding-bottom: 40px; }
        
        header { position: sticky; top: 0; background: rgba(15, 20, 25, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); padding: 16px; z-index: 10; display: flex; justify-content: space-between; align-items: center; }
        [data-theme="light"] header { background: rgba(247, 249, 249, 0.85); }
        header h1 { font-size: 1.15rem; font-weight: 700; }
        
        .theme-toggle { background: none; border: 1px solid var(--border-color); color: var(--text-primary); padding: 6px 12px; border-radius: 20px; cursor: pointer; font-size: 0.8rem; font-weight: 600; }
        
        .stats-bar { background: var(--card-bg); border-bottom: 1px solid var(--border-color); padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; }
        .pill { padding: 4px 10px; border-radius: 12px; font-weight: 700; font-size: 0.75rem; border: 1px solid var(--border-color); }
        .pill.parked { color: var(--accent-gold); background: rgba(255, 215, 0, 0.1); }
        .pill.harvested { color: var(--accent-green); background: rgba(0, 186, 124, 0.1); }
        
        .composer { border-bottom: 1px solid var(--border-color); padding: 16px; background-color: var(--card-bg); }
        .composer select, .composer textarea, .composer input[type="text"], .composer input[type="number"] {
            width: 100%; background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-primary);
            padding: 10px; border-radius: 8px; font-size: 0.95rem; margin-bottom: 10px; outline: none;
        }
        .composer textarea { resize: vertical; min-height: 60px; }
        .composer select:focus, .composer textarea:focus, .composer input:focus { border-color: var(--accent-blue); }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        
        .btn-submit { background-color: var(--accent-blue); color: #fff; border: none; padding: 12px 18px; border-radius: 9999px; font-weight: 700; cursor: pointer; width: 100%; transition: opacity 0.2s; }
        .btn-submit:hover { opacity: 0.9; }
        
        .feed { list-style: none; }
        .tweet-card { border-bottom: 1px solid var(--border-color); padding: 16px; display: flex; gap: 12px; transition: background 0.2s; }
        .tweet-card:hover { background-color: rgba(255, 255, 255, 0.02); }
        [data-theme="light"] .tweet-card:hover { background-color: rgba(0, 0, 0, 0.01); }
        
        .avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; flex-shrink: 0; }
        .avatar.parked { background: rgba(255, 215, 0, 0.15); color: var(--accent-gold); }
        .avatar.harvested { background: rgba(0, 186, 124, 0.15); color: var(--accent-green); }
        .avatar.discarded { background: rgba(113, 118, 123, 0.15); color: var(--text-secondary); }
        
        .tweet-content { flex: 1; }
        .tweet-header { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; font-size: 0.85rem; flex-wrap: wrap; }
        
        .badge { font-weight: 700; text-transform: uppercase; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; }
        .badge.side_idea { background: rgba(120, 86, 255, 0.2); color: var(--accent-purple); }
        .badge.sudden_thought { background: rgba(255, 215, 0, 0.2); color: var(--accent-gold); }
        .badge.micro_task { background: rgba(29, 155, 240, 0.2); color: var(--accent-blue); }
        .badge.custom { background: rgba(113, 118, 123, 0.2); color: var(--text-secondary); }
        
        .timestamp { color: var(--text-secondary); margin-left: auto; font-size: 0.75rem; }
        .idea-text { font-size: 0.95rem; line-height: 1.4; color: var(--text-primary); margin-bottom: 10px; white-space: pre-line; }
        
        .actions-row { display: flex; gap: 8px; align-items: center; }
        .action-btn { background: none; border: 1px solid var(--border-color); color: var(--text-secondary); padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; cursor: pointer; font-weight: 600; }
        .action-btn:hover { border-color: var(--text-primary); color: var(--text-primary); }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>🤝 Holding Hand</h1>
        <button class="theme-toggle" onclick="toggleTheme()" id="themeBtn">🌙 Dark</button>
    </header>

    <div class="stats-bar">
        <div>
            <strong>Parked Buffer</strong>
            <span style="color: var(--text-secondary); margin-left: 6px;">Dont context switch!</span>
        </div>
        <div style="display: flex; gap: 6px;">
            <span class="pill parked"><?= $totalParked ?> Parked</span>
            <span class="pill harvested"><?= $totalHarvested ?> Done</span>
        </div>
    </div>

    <div class="composer">
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="create">
            <div class="grid-2">
                <select name="category" id="category" onchange="toggleCustomInput(this.value)" required>
                    <option value="side_idea">💡 Sudden Side Idea</option>
                    <option value="sudden_thought">🧠 Intrusive Thought</option>
                    <option value="micro_task">⏱️ 15-Min Pomo Task</option>
                    <option value="custom">⚙️ Custom Tag...</option>
                </select>

                <input type="number" name="pomodoro_min" value="15" min="5" max="120" placeholder="Target Pomo (Mins)">
            </div>

            <input type="text" name="custom_category" id="custom_category" placeholder="Specify Custom Tag" style="display: none;">

            <textarea name="idea_text" placeholder="Park your thought here in 5 seconds... get back to work!" required></textarea>

            <button type="submit" class="btn-submit">🤝 Park Idea & Stay Focused</button>
        </form>
    </div>

    <ul class="feed">
        <?php if (empty($entries)): ?>
            <li class="tweet-card">
                <div class="tweet-content" style="text-align: center; color: var(--text-secondary); padding: 20px 0;">
                    Your buffer is empty. Focused working!
                </div>
            </li>
        <?php endif; ?>

        <?php foreach ($entries as $item): ?>
            <li class="tweet-card">
                <div class="avatar <?= htmlspecialchars($item['status']) ?>">
                    <?= $item['status'] === 'harvested' ? '✓' : ($item['status'] === 'discarded' ? '✕' : '🤝') ?>
                </div>
                <div class="tweet-content">
                    <div class="tweet-header">
                        <span class="badge <?= htmlspecialchars($item['category']) ?>">
                            <?= $item['category'] === 'custom' && !empty($item['custom_category']) ? htmlspecialchars($item['custom_category']) : htmlspecialchars(str_replace('_', ' ', $item['category'])) ?>
                        </span>
                        <span style="color: var(--text-secondary); font-size: 0.75rem;">⏱️ Pomo: <?= (int)$item['pomodoro_min'] ?>m</span>
                        <span class="timestamp"><?= date('M d, H:i', strtotime($item['created_at'])) ?></span>
                    </div>

                    <div class="idea-text"><?= nl2br(htmlspecialchars($item['idea_text'])) ?></div>

                    <div class="actions-row">
                        <?php if ($item['status'] !== 'harvested'): ?>
                            <form method="POST" action="index.php" style="display:inline;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <input type="hidden" name="status" value="harvested">
                                <button type="submit" class="action-btn" style="color: var(--accent-green);">✓ Do in 15m Pomo</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($item['status'] !== 'discarded'): ?>
                            <form method="POST" action="index.php" style="display:inline;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <input type="hidden" name="status" value="discarded">
                                <button type="submit" class="action-btn">✕ Discard</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
function toggleCustomInput(val) {
    const customInput = document.getElementById('custom_category');
    if (val === 'custom') {
        customInput.style.display = 'block';
    } else {
        customInput.style.display = 'none';
        customInput.value = '';
    }
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    setTheme(newTheme);
}

function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('holding_hand_theme', theme);
    const themeBtn = document.getElementById('themeBtn');
    themeBtn.textContent = theme === 'light' ? '☀️ Light' : '🌙 Dark';
}

(function() {
    const savedTheme = localStorage.getItem('holding_hand_theme') || 'dark';
    setTheme(savedTheme);
})();
</script>

</body>
</html>