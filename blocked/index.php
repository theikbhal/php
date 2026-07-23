<?php
// Initialize SQLite Database
$dbFile = __DIR__ . '/unblock_tracker.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS block_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        block_type TEXT NOT NULL, -- 'cashflow', 'technical', 'skill', 'energy', 'time', 'custom'
        custom_type TEXT DEFAULT NULL,
        block_description TEXT NOT NULL,
        why_hope TEXT DEFAULT NULL,
        recovery_action TEXT DEFAULT NULL,
        is_resolved INTEGER DEFAULT 0,
        xp_gained INTEGER DEFAULT 10,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_resolve') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE block_logs SET is_resolved = CASE WHEN is_resolved = 1 THEN 0 ELSE 1 END WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: index.php");
        exit;
    }

    $block_type        = $_POST['block_type'] ?? 'technical';
    $custom_type       = trim($_POST['custom_type'] ?? '');
    $block_description = trim($_POST['block_description'] ?? '');
    $why_hope          = trim($_POST['why_hope'] ?? '');
    $recovery_action   = trim($_POST['recovery_action'] ?? '');

    if (!empty($block_description)) {
        // Gamified XP calculation
        $xp = 10; // Base XP for logging and analyzing
        if (!empty($why_hope)) $xp += 15;
        if (!empty($recovery_action)) $xp += 25;

        $stmt = $pdo->prepare("INSERT INTO block_logs (block_type, custom_type, block_description, why_hope, recovery_action, xp_gained) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$block_type, $custom_type, $block_description, $why_hope, $recovery_action, $xp]);
    }
    header("Location: index.php");
    exit;
}

// Fetch Timeline Entries
$entries = $pdo->query("SELECT * FROM block_logs ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Gamification Metrics
$totalXp = 0;
$resolvedCount = 0;
foreach ($entries as $e) {
    $totalXp += $e['xp_gained'];
    if ($e['is_resolved']) {
        $totalXp += 50; // Bonus XP for resolving
        $resolvedCount++;
    }
}
$level = floor($totalXp / 100) + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unblocked — Resilience & Resourcefulness Engine</title>
    <style>
        :root {
            --bg-color: #0f1419;
            --card-bg: #161e27;
            --border-color: #2f3336;
            --text-primary: #e7e9ea;
            --text-secondary: #71767b;
            --accent-blue: #1d9bf0;
            --accent-red: #f4212e;
            --accent-green: #00ba7c;
            --accent-purple: #7856ff;
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
            --accent-red: #f4212e;
            --accent-green: #00ba7c;
            --accent-purple: #7856ff;
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
        
        .gamify-bar { background: var(--card-bg); border-bottom: 1px solid var(--border-color); padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; }
        .level-badge { background: linear-gradient(135deg, var(--accent-purple), var(--accent-blue)); color: #fff; padding: 4px 10px; border-radius: 12px; font-weight: 800; font-size: 0.75rem; }
        
        .composer { border-bottom: 1px solid var(--border-color); padding: 16px; background-color: var(--card-bg); }
        .composer select, .composer textarea, .composer input[type="text"] {
            width: 100%; background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-primary);
            padding: 10px; border-radius: 8px; font-size: 0.95rem; margin-bottom: 10px; outline: none;
        }
        .composer textarea { resize: vertical; min-height: 50px; }
        .composer select:focus, .composer textarea:focus, .composer input:focus { border-color: var(--accent-purple); }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        
        .btn-submit { background-color: var(--accent-purple); color: #fff; border: none; padding: 12px 18px; border-radius: 9999px; font-weight: 700; cursor: pointer; width: 100%; transition: opacity 0.2s; }
        .btn-submit:hover { opacity: 0.9; }
        
        .feed { list-style: none; }
        .tweet-card { border-bottom: 1px solid var(--border-color); padding: 16px; display: flex; gap: 12px; transition: background 0.2s; }
        .tweet-card:hover { background-color: rgba(255, 255, 255, 0.02); }
        [data-theme="light"] .tweet-card:hover { background-color: rgba(0, 0, 0, 0.01); }
        
        .avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; flex-shrink: 0; }
        .avatar.blocked { background: rgba(244, 33, 46, 0.15); color: var(--accent-red); }
        .avatar.resolved { background: rgba(0, 186, 124, 0.15); color: var(--accent-green); }
        
        .tweet-content { flex: 1; }
        .tweet-header { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; font-size: 0.85rem; flex-wrap: wrap; }
        
        .badge { font-weight: 700; text-transform: uppercase; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; }
        .badge.technical { background: rgba(29, 155, 240, 0.2); color: var(--accent-blue); }
        .badge.cashflow { background: rgba(0, 186, 124, 0.2); color: var(--accent-green); }
        .badge.skill { background: rgba(120, 86, 255, 0.2); color: var(--accent-purple); }
        .badge.energy { background: rgba(244, 33, 46, 0.2); color: var(--accent-red); }
        .badge.time { background: rgba(255, 215, 0, 0.2); color: var(--accent-gold); }
        .badge.custom { background: rgba(113, 118, 123, 0.2); color: var(--text-secondary); }
        
        .timestamp { color: var(--text-secondary); margin-left: auto; font-size: 0.75rem; }
        
        .block-text { font-size: 0.95rem; line-height: 1.4; color: var(--text-primary); font-weight: 600; margin-bottom: 8px; }
        
        .box { margin-top: 6px; padding: 8px 12px; border-radius: 6px; font-size: 0.85rem; line-height: 1.3; }
        .box-hope { background: rgba(255, 215, 0, 0.1); border-left: 3px solid var(--accent-gold); color: var(--text-primary); }
        .box-action { background: rgba(0, 186, 124, 0.1); border-left: 3px solid var(--accent-green); color: var(--text-primary); }
        
        .card-actions { margin-top: 10px; display: flex; justify-content: space-between; align-items: center; }
        .resolve-btn { background: none; border: 1px solid var(--border-color); color: var(--text-secondary); padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; cursor: pointer; font-weight: 600; }
        .resolve-btn.active { background: rgba(0, 186, 124, 0.2); color: var(--accent-green); border-color: var(--accent-green); }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1> Unblocked</h1>
        <button class="theme-toggle" onclick="toggleTheme()" id="themeBtn">🌙 Dark</button>
    </header>

    <div class="gamify-bar">
        <div>
            <strong>Level <?= $level ?> Strategist</strong>
            <span style="color: var(--text-secondary); margin-left: 6px;"><?= $totalXp ?> XP</span>
        </div>
        <div>
            <span class="level-badge"><?= $resolvedCount ?> Resolved</span>
        </div>
    </div>

    <div class="composer">
        <form method="POST" action="index.php">
            <div class="grid-2">
                <select name="block_type" id="block_type" onchange="toggleCustomInput(this.value)" required>
                    <option value="technical"> Technical / Bug</option>
                    <option value="cashflow"> Cashflow / Money Lack</option>
                    <option value="skill"> Skill / Knowledge Lack</option>
                    <option value="energy"> Energy / Burnout</option>
                    <option value="time"> Time / Capacity Lack</option>
                    <option value="custom"> Custom Obstacle...</option>
                </select>

                <input type="text" name="custom_type" id="custom_type" placeholder="Specify Custom Category" style="display: none;">
            </div>

            <textarea name="block_description" placeholder=" What is blocking you right now?" required></textarea>
            <textarea name="why_hope" placeholder=" Why is there hope? (What leverage/options do you still have?)"></textarea>
            <textarea name="recovery_action" placeholder=" How to recover? (What is the immediate next micro-step?)"></textarea>

            <button type="submit" class="btn-submit">Log Obstacle (+XP)</button>
        </form>
    </div>

    <ul class="feed">
        <?php if (empty($entries)): ?>
            <li class="tweet-card">
                <div class="tweet-content" style="text-align: center; color: var(--text-secondary); padding: 20px 0;">
                    No obstacles logged yet. Feeling stuck? Break it down above!
                </div>
            </li>
        <?php endif; ?>

        <?php foreach ($entries as $item): ?>
            <li class="tweet-card">
                <div class="avatar <?= $item['is_resolved'] ? 'resolved' : 'blocked' ?>">
                    <?= $item['is_resolved'] ? '✓' : '🔓' ?>
                </div>
                <div class="tweet-content">
                    <div class="tweet-header">
                        <span class="badge <?= htmlspecialchars($item['block_type']) ?>">
                            <?= $item['block_type'] === 'custom' && !empty($item['custom_type']) ? htmlspecialchars($item['custom_type']) : htmlspecialchars($item['block_type']) ?>
                        </span>
                        <span style="color: var(--text-secondary); font-size: 0.75rem;">+<?= $item['xp_gained'] ?> XP</span>
                        <span class="timestamp"><?= date('M d, H:i', strtotime($item['created_at'])) ?></span>
                    </div>

                    <div class="block-text"><?= nl2br(htmlspecialchars($item['block_description'])) ?></div>

                    <?php if (!empty($item['why_hope'])): ?>
                        <div class="box box-hope">
                            <strong> Hope & Leverage:</strong><br><?= nl2br(htmlspecialchars($item['why_hope'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($item['recovery_action'])): ?>
                        <div class="box box-action">
                            <strong> Recovery Micro-Action:</strong><br><?= nl2br(htmlspecialchars($item['recovery_action'])) ?>
                        </div>
                    <?php endif; ?>

                    <div class="card-actions">
                        <form method="POST" action="index.php" style="margin: 0;">
                            <input type="hidden" name="action" value="toggle_resolve">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="resolve-btn <?= $item['is_resolved'] ? 'active' : '' ?>">
                                <?= $item['is_resolved'] ? '✓ Unblocked (+50 XP)' : 'Mark as Unblocked' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
function toggleCustomInput(val) {
    const customInput = document.getElementById('custom_type');
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
    localStorage.setItem('unblock_theme', theme);
    const themeBtn = document.getElementById('themeBtn');
    themeBtn.textContent = theme === 'light' ? '☀️ Light' : '🌙 Dark';
}

// Initial theme check
(function() {
    const savedTheme = localStorage.getItem('unblock_theme') || 'dark';
    setTheme(savedTheme);
})();
</script>

</body>
</html>