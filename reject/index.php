<?php
// Initialize SQLite Database
$dbFile = __DIR__ . '/rejection_tracker.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS rejection_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        attempt_type TEXT NOT NULL, -- 'cold_ask', 'sales_pitch', 'social_ask', 'favor_request', 'custom'
        target_person TEXT DEFAULT NULL,
        outcome TEXT NOT NULL, -- 'rejected', 'accepted', 'pending'
        fear_level INTEGER DEFAULT 5, -- Scale 1-10
        note TEXT,
        lesson_learned TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempt_type   = $_POST['attempt_type'] ?? 'cold_ask';
    $target_person = trim($_POST['target_person'] ?? '');
    $outcome        = $_POST['outcome'] ?? 'rejected';
    $fear_level     = (int)($_POST['fear_level'] ?? 5);
    $note           = trim($_POST['note'] ?? '');
    $lesson_learned = trim($_POST['lesson_learned'] ?? '');

    if (!empty($note) || !empty($attempt_type)) {
        $stmt = $pdo->prepare("INSERT INTO rejection_logs (attempt_type, target_person, outcome, fear_level, note, lesson_learned) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$attempt_type, $target_person, $outcome, $fear_level, $note, $lesson_learned]);
    }
    header("Location: index.php");
    exit;
}

// Fetch Timeline Entries
$entries = $pdo->query("SELECT * FROM rejection_logs ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Quick Stats
$totalAttempts = count($entries);
$totalRejections = count(array_filter($entries, fn($e) => $e['outcome'] === 'rejected'));
$totalAccepted = count(array_filter($entries, fn($e) => $e['outcome'] === 'accepted'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejection Muscle — Daily Try & Fail Log</title>
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
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-primary); display: flex; justify-content: center; min-height: 100vh; padding: 0 10px; }
        .container { width: 100%; max-width: 600px; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); min-height: 100vh; padding-bottom: 40px; }
        header { position: sticky; top: 0; background: rgba(15, 20, 25, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); padding: 16px; z-index: 10; display: flex; justify-content: space-between; align-items: center; }
        header h1 { font-size: 1.15rem; font-weight: 700; }
        .stats-bar { display: flex; gap: 12px; font-size: 0.8rem; font-weight: 600; }
        .stat-badge { padding: 4px 8px; border-radius: 12px; background: rgba(255, 255, 255, 0.05); border: 1px solid var(--border-color); }
        .composer { border-bottom: 1px solid var(--border-color); padding: 16px; background-color: var(--card-bg); }
        .composer select, .composer textarea, .composer input[type="text"], .composer input[type="number"] {
            width: 100%; background: #000; border: 1px solid var(--border-color); color: var(--text-primary);
            padding: 10px; border-radius: 8px; font-size: 0.95rem; margin-bottom: 10px; outline: none;
        }
        .composer textarea { resize: vertical; min-height: 60px; }
        .composer select:focus, .composer textarea:focus, .composer input:focus { border-color: var(--accent-purple); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .btn-submit { background-color: var(--accent-purple); color: #fff; border: none; padding: 12px 18px; border-radius: 9999px; font-weight: 700; cursor: pointer; width: 100%; transition: opacity 0.2s; }
        .btn-submit:hover { opacity: 0.9; }
        .feed { list-style: none; }
        .tweet-card { border-bottom: 1px solid var(--border-color); padding: 16px; display: flex; gap: 12px; transition: background 0.2s; }
        .tweet-card:hover { background-color: rgba(255, 255, 255, 0.03); }
        .avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem; flex-shrink: 0; }
        .avatar.rejected { background: rgba(244, 33, 46, 0.2); color: var(--accent-red); }
        .avatar.accepted { background: rgba(0, 186, 124, 0.2); color: var(--accent-green); }
        .avatar.pending { background: rgba(255, 215, 0, 0.2); color: var(--accent-gold); }
        .tweet-content { flex: 1; }
        .tweet-header { display: flex; gap: 8px; align-items: center; margin-bottom: 4px; font-size: 0.85rem; flex-wrap: wrap; }
        .badge { font-weight: 700; text-transform: uppercase; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; }
        .badge.rejected { background: var(--accent-red); color: #fff; }
        .badge.accepted { background: var(--accent-green); color: #fff; }
        .badge.pending { background: var(--accent-gold); color: #000; }
        .timestamp { color: var(--text-secondary); margin-left: auto; }
        .tweet-text { font-size: 0.95rem; line-height: 1.4; color: var(--text-primary); margin-top: 6px; white-space: pre-line; }
        .lesson-box { margin-top: 8px; padding: 8px 12px; background: rgba(120, 86, 255, 0.1); border-left: 3px solid var(--accent-purple); font-size: 0.85rem; color: #d0c4ff; border-radius: 0 6px 6px 0; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>Rejection Muscle</h1>
        <div class="stats-bar">
            <span class="stat-badge" style="color: var(--accent-red)">Fails: <?= $totalRejections ?></span>
            <span class="stat-badge" style="color: var(--accent-green)">Wins: <?= $totalAccepted ?></span>
            <span class="stat-badge">Reps: <?= $totalAttempts ?></span>
        </div>
    </header>

    <div class="composer">
        <form method="POST" action="index.php">
            <div class="grid-2">
                <select name="attempt_type" required>
                    <option value="cold_ask">Cold Ask / Discount</option>
                    <option value="sales_pitch">Sales Pitch / Outreach</option>
                    <option value="social_ask">Social / Networking Ask</option>
                    <option value="favor_request">Favor / Unreasonable Request</option>
                    <option value="custom">Custom Challenge</option>
                </select>

                <select name="outcome" required>
                    <option value="rejected"> Rejected (Goal Hit! 🎉)</option>
                    <option value="accepted"> Accepted (Accidental Win 😅)</option>
                    <option value="pending">⏳ Pending Response</option>
                </select>
            </div>

            <div class="grid-2">
                <input type="text" name="target_person" placeholder="Target (e.g., Barista, Investor, Stranger)">
                <input type="number" name="fear_level" min="1" max="10" placeholder="Fear Level (1-10)" value="5">
            </div>

            <textarea name="note" placeholder="What was your request? How did you deliver it?" required></textarea>
            <textarea name="lesson_learned" placeholder="What did you learn? Did the fear match reality?"></textarea>

            <button type="submit" class="btn-submit">Log Rejection Rep</button>
        </form>
    </div>

    <ul class="feed">
        <?php if (empty($entries)): ?>
            <li class="tweet-card">
                <div class="tweet-content" style="text-align: center; color: var(--text-secondary); padding: 20px 0;">
                    No reps logged yet. Go ask for something unreasonable and get rejected!
                </div>
            </li>
        <?php endif; ?>

        <?php foreach ($entries as $item): ?>
            <li class="tweet-card">
                <div class="avatar <?= htmlspecialchars($item['outcome']) ?>">
                    <?= $item['outcome'] === 'rejected' ? '✕' : ($item['outcome'] === 'accepted' ? '✓' : '⏳') ?>
                </div>
                <div class="tweet-content">
                    <div class="tweet-header">
                        <span class="badge <?= htmlspecialchars($item['outcome']) ?>">
                            <?= htmlspecialchars($item['outcome']) ?>
                        </span>
                        <span style="color: var(--text-secondary); font-weight: 600;">
                            • <?= htmlspecialchars(ucwords(str_replace('_', ' ', $item['attempt_type']))) ?>
                        </span>
                        <?php if (!empty($item['target_person'])): ?>
                            <span style="color: var(--accent-purple);">→ @<?= htmlspecialchars($item['target_person']) ?></span>
                        <?php endif; ?>
                        <span style="color: var(--accent-gold);">⚡ Fear: <?= (int)$item['fear_level'] ?>/10</span>
                        <span class="timestamp"><?= date('M d, H:i', strtotime($item['created_at'])) ?></span>
                    </div>

                    <?php if (!empty($item['note'])): ?>
                        <div class="tweet-text"><?= nl2br(htmlspecialchars($item['note'])) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($item['lesson_learned'])): ?>
                        <div class="lesson-box">
                            <strong>💡 Reflection:</strong> <?= nl2br(htmlspecialchars($item['lesson_learned'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

</body>
</html>