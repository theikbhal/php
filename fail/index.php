<?php
// Initialize SQLite Database
$dbFile = __DIR__ . '/tracker.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL, -- 'fail', 'bounce_back', 'namaaz'
        namaaz_type TEXT DEFAULT NULL, -- 'farz', 'sunnat', 'nafil', 'duwa'
        is_checked INTEGER DEFAULT 0,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category    = $_POST['category'] ?? 'fail';
    $namaaz_type = !empty($_POST['namaaz_type']) ? $_POST['namaaz_type'] : null;
    $is_checked  = isset($_POST['is_checked']) ? 1 : 0;
    $note        = trim($_POST['note'] ?? '');

    if (!empty($note) || $category === 'namaaz') {
        $stmt = $pdo->prepare("INSERT INTO entries (category, namaaz_type, is_checked, note) VALUES (?, ?, ?, ?)");
        $stmt->execute([$category, $namaaz_type, $is_checked, $note]);
    }
    header("Location: index.php");
    exit;
}

// Fetch Timeline Entries
$entries = $pdo->query("SELECT * FROM entries ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BounceBack — Failure & Reflection Log</title>
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
            --accent-gold: #ffd700;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-primary); display: flex; justify-content: center; min-height: 100vh; padding: 0 10px; }
        .container { width: 100%; max-width: 600px; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); min-height: 100vh; padding-bottom: 40px; }
        header { position: sticky; top: 0; background: rgba(15, 20, 25, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); padding: 16px; z-index: 10; }
        header h1 { font-size: 1.25rem; font-weight: 700; }
        .composer { border-bottom: 1px solid var(--border-color); padding: 16px; background-color: var(--card-bg); }
        .composer select, .composer textarea, .composer input[type="text"] {
            width: 100%; background: #000; border: 1px solid var(--border-color); color: var(--text-primary);
            padding: 10px; border-radius: 8px; font-size: 0.95rem; margin-bottom: 10px; outline: none;
        }
        .composer textarea { resize: vertical; min-height: 70px; }
        .composer select:focus, .composer textarea:focus { border-color: var(--accent-blue); }
        .options-row { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; flex-wrap: wrap; }
        .checkbox-label { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--text-secondary); cursor: pointer; }
        .btn-submit { background-color: var(--accent-blue); color: #fff; border: none; padding: 10px 18px; border-radius: 9999px; font-weight: 700; cursor: pointer; width: 100%; transition: opacity 0.2s; }
        .btn-submit:hover { opacity: 0.9; }
        .feed { list-style: none; }
        .tweet-card { border-bottom: 1px solid var(--border-color); padding: 16px; display: flex; gap: 12px; transition: background 0.2s; }
        .tweet-card:hover { background-color: rgba(255, 255, 255, 0.03); }
        .avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem; flex-shrink: 0; }
        .avatar.fail { background: rgba(244, 33, 46, 0.2); color: var(--accent-red); }
        .avatar.bounce { background: rgba(0, 186, 124, 0.2); color: var(--accent-green); }
        .avatar.namaaz { background: rgba(255, 215, 0, 0.2); color: var(--accent-gold); }
        .tweet-content { flex: 1; }
        .tweet-header { display: flex; gap: 8px; align-items: center; margin-bottom: 4px; font-size: 0.85rem; }
        .tweet-header .badge { font-weight: 700; text-transform: uppercase; font-size: 0.75rem; padding: 2px 6px; border-radius: 4px; }
        .badge.fail { background: var(--accent-red); color: #fff; }
        .badge.bounce { background: var(--accent-green); color: #fff; }
        .badge.namaaz { background: var(--accent-gold); color: #000; }
        .timestamp { color: var(--text-secondary); }
        .tweet-text { font-size: 0.95rem; line-height: 1.4; color: var(--text-primary); margin-top: 4px; white-space: pre-line; }
        .checked-status { display: inline-block; margin-top: 6px; font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; background: rgba(0, 186, 124, 0.15); color: var(--accent-green); border: 1px solid var(--accent-green); }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>BounceBack Feed</h1>
    </header>

    <div class="composer">
        <form method="POST" action="index.php">
            <div class="options-row">
                <select name="category" id="category" onchange="toggleNamaazOptions(this.value)" required>
                    <option value="fail"> Track Failure (Reflection)</option>
                    <option value="bounce_back"> Bounce Back / Progress</option>
                    <option value="namaaz"> Spiritual / Namaaz Log</option>
                </select>

                <select name="namaaz_type" id="namaaz_type" style="display: none;">
                    <option value="">-- Select Namaaz Type --</option>
                    <option value="farz">Faraz (Compulsory)</option>
                    <option value="sunnat">Sunath (Sunnah)</option>
                    <option value="nafil">Nafil (Voluntary)</option>
                    <option value="duwa">Duwa (Supplication)</option>
                </select>
            </div>

            <textarea name="note" placeholder="What happened? What did you learn or accomplish?"></textarea>

            <div class="options-row" style="justify-content: space-between;">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_checked" value="1"> Mark as Completed / Resolved
                </label>
                <button type="submit" class="btn-submit">Post Entry</button>
            </div>
        </form>
    </div>

    <ul class="feed">
        <?php if (empty($entries)): ?>
            <li class="tweet-card">
                <div class="tweet-content" style="text-align: center; color: var(--text-secondary); padding: 20px 0;">
                    No reflections logged yet. Start by logging a failure or prayer.
                </div>
            </li>
        <?php endif; ?>

        <?php foreach ($entries as $item): ?>
            <li class="tweet-card">
                <div class="avatar <?= htmlspecialchars($item['category']) ?>">
                    <?= $item['category'] === 'fail' ? '✕' : ($item['category'] === 'bounce_back' ? '✓' : '☪') ?>
                </div>
                <div class="tweet-content">
                    <div class="tweet-header">
                        <span class="badge <?= htmlspecialchars($item['category']) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $item['category'])) ?>
                        </span>
                        <?php if (!empty($item['namaaz_type'])): ?>
                            <span style="color: var(--accent-gold); font-weight: 600;">• <?= ucfirst(htmlspecialchars($item['namaaz_type'])) ?></span>
                        <?php endif; ?>
                        <span class="timestamp">• <?= date('M d, H:i', strtotime($item['created_at'])) ?></span>
                    </div>

                    <?php if (!empty($item['note'])): ?>
                        <div class="tweet-text"><?= nl2br(htmlspecialchars($item['note'])) ?></div>
                    <?php endif; ?>

                    <?php if ($item['is_checked']): ?>
                        <span class="checked-status">✓ Completed</span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
function toggleNamaazOptions(val) {
    const namaazSelect = document.getElementById('namaaz_type');
    if (val === 'namaaz') {
        namaazSelect.style.display = 'block';
    } else {
        namaazSelect.style.display = 'none';
        namaazSelect.value = '';
    }
}
</script>

</body>
</html>