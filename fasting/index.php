<?php
// Initialize SQLite Database
$dbFile = __DIR__ . '/fasting_tracker.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS fasting_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        fast_date DATE NOT NULL UNIQUE,
        fast_type TEXT NOT NULL, -- 'monday', 'thursday', 'ayyam_al_bidh', 'farz', 'volatility_other'
        status TEXT NOT NULL, -- 'completed', 'planned', 'broken'
        sehri_time TEXT DEFAULT NULL,
        iftar_time TEXT DEFAULT NULL,
        spiritual_note TEXT DEFAULT NULL,
        iklas_rating INTEGER DEFAULT 5, -- 1 to 5 scale
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add_log';

    if ($action === 'add_log') {
        $fast_date      = $_POST['fast_date'] ?? date('Y-m-d');
        $fast_type      = $_POST['fast_type'] ?? 'monday';
        $status         = $_POST['status'] ?? 'completed';
        $sehri_time     = trim($_POST['sehri_time'] ?? '');
        $iftar_time     = trim($_POST['iftar_time'] ?? '');
        $spiritual_note = trim($_POST['spiritual_note'] ?? '');
        $iklas_rating   = (int)($_POST['iklas_rating'] ?? 5);

        // Upsert entry
        $stmt = $pdo->prepare("INSERT INTO fasting_logs (fast_date, fast_type, status, sehri_time, iftar_time, spiritual_note, iklas_rating) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(fast_date) DO UPDATE SET 
            fast_type=excluded.fast_type, status=excluded.status, sehri_time=excluded.sehri_time, 
            iftar_time=excluded.iftar_time, spiritual_note=excluded.spiritual_note, iklas_rating=excluded.iklas_rating");
        $stmt->execute([$fast_date, $fast_type, $status, $sehri_time, $iftar_time, $spiritual_note, $iklas_rating]);
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM fasting_logs WHERE id = ?");
        $stmt->execute([$id]);
    }

    header("Location: index.php");
    exit;
}

// Monthly Metrics & Calculations
$currentMonth = date('Y-m');
$stmt = $pdo->prepare("SELECT * FROM fasting_logs WHERE strftime('%Y-%m', fast_date) = ? AND status = 'completed'");
$stmt->execute([$currentMonth]);
$monthlyCompleted = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countCompletedThisMonth = count($monthlyCompleted);
$goalTarget = 10;
$minPassTarget = 1;
$avgPerWeek = round($countCompletedThisMonth / 4.33, 1); // approx weeks per month

// Fetch All Entries for Timeline
$allEntries = $pdo->query("SELECT * FROM fasting_logs ORDER BY fast_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Map dates for calendar view
$loggedDates = [];
foreach ($allEntries as $e) {
    $loggedDates[$e['fast_date']] = $e;
}

// Calendar Month Generation
$year = (int)date('Y');
$month = (int)date('m');
$firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
$numberDays = date('t', $firstDayOfMonth);
$dateComponents = getdate($firstDayOfMonth);
$dayOfWeek = $dateComponents['wday']; // 0 = Sun, 1 = Mon ...
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saum Tracker — Sunnah Fasting & Spiritual Discipline</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --card-bg: #161b22;
            --border-color: #30363d;
            --text-primary: #c9d1d9;
            --text-secondary: #8b949e;
            --accent-green: #238636;
            --accent-emerald: #2ea043;
            --accent-gold: #d29922;
            --accent-blue: #58a6ff;
            --accent-purple: #bc8cff;
            --input-bg: #010409;
        }

        [data-theme="light"] {
            --bg-color: #f6f8fa;
            --card-bg: #ffffff;
            --border-color: #d0d7de;
            --text-primary: #1f2328;
            --text-secondary: #656d76;
            --accent-green: #1f883d;
            --accent-emerald: #1a7f37;
            --accent-gold: #9a6700;
            --accent-blue: #0969da;
            --accent-purple: #8250df;
            --input-bg: #f6f8fa;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-primary); display: flex; justify-content: center; min-height: 100vh; padding: 0 10px; transition: background 0.2s; }
        .container { width: 100%; max-width: 640px; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); min-height: 100vh; padding-bottom: 50px; }
        
        header { position: sticky; top: 0; background: rgba(13, 17, 23, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); padding: 14px 16px; z-index: 10; display: flex; justify-content: space-between; align-items: center; }
        [data-theme="light"] header { background: rgba(246, 248, 250, 0.85); }
        header h1 { font-size: 1.15rem; font-weight: 700; color: var(--text-primary); }
        
        .theme-btn { background: none; border: 1px solid var(--border-color); color: var(--text-primary); padding: 5px 12px; border-radius: 20px; cursor: pointer; font-size: 0.8rem; font-weight: 600; }
        
        /* Stats Dashboard */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 14px 16px; background: var(--card-bg); border-bottom: 1px solid var(--border-color); text-align: center; }
        .stat-card { background: var(--bg-color); padding: 10px; border-radius: 8px; border: 1px solid var(--border-color); }
        .stat-value { font-size: 1.25rem; font-weight: 800; color: var(--accent-emerald); }
        .stat-label { font-size: 0.72rem; color: var(--text-secondary); text-transform: uppercase; margin-top: 2px; }

        /* Spiritual Pillars Banner */
        .pillars-bar { display: flex; justify-content: space-around; padding: 8px; background: rgba(210, 153, 34, 0.08); border-bottom: 1px solid var(--border-color); font-size: 0.78rem; font-weight: 600; color: var(--accent-gold); }

        /* Calendar View */
        .section-title { font-size: 0.9rem; font-weight: 700; padding: 12px 16px 6px; color: var(--text-secondary); text-transform: uppercase; }
        .calendar-container { padding: 0 16px 16px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
        .cal-head { font-size: 0.75rem; font-weight: 700; color: var(--text-secondary); padding: 4px 0; }
        .cal-day { min-height: 42px; border: 1px solid var(--border-color); border-radius: 6px; padding: 4px; font-size: 0.8rem; background: var(--card-bg); position: relative; }
        .cal-day.empty { background: transparent; border: none; }
        .cal-day.today { border-color: var(--accent-blue); font-weight: bold; }
        .cal-day .badge-dot { position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 6px; height: 6px; border-radius: 50%; }
        .dot-completed { background-color: var(--accent-emerald); }
        .dot-planned { background-color: var(--accent-gold); }

        /* Composer Form */
        .composer { border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 16px; background-color: var(--card-bg); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .composer select, .composer input, .composer textarea {
            width: 100%; background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-primary);
            padding: 9px 12px; border-radius: 8px; font-size: 0.9rem; margin-bottom: 10px; outline: none;
        }
        .composer textarea { resize: vertical; min-height: 55px; }
        .composer select:focus, .composer input:focus, .composer textarea:focus { border-color: var(--accent-emerald); }
        
        .btn-submit { background-color: var(--accent-green); color: #ffffff; border: none; padding: 11px 18px; border-radius: 9999px; font-weight: 700; cursor: pointer; width: 100%; transition: opacity 0.2s; font-size: 0.9rem; }
        .btn-submit:hover { opacity: 0.9; }

        /* Timeline Feed */
        .feed { list-style: none; }
        .tweet-card { border-bottom: 1px solid var(--border-color); padding: 16px; display: flex; gap: 12px; }
        .avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; flex-shrink: 0; }
        .avatar.completed { background: rgba(46, 160, 67, 0.15); color: var(--accent-emerald); }
        .avatar.planned { background: rgba(210, 153, 34, 0.15); color: var(--accent-gold); }
        
        .tweet-content { flex: 1; }
        .tweet-header { display: flex; gap: 8px; align-items: center; margin-bottom: 4px; font-size: 0.82rem; flex-wrap: wrap; }
        .badge { font-weight: 700; text-transform: uppercase; font-size: 0.68rem; padding: 2px 6px; border-radius: 4px; }
        .badge.completed { background: var(--accent-emerald); color: #fff; }
        .badge.planned { background: var(--accent-gold); color: #000; }
        .fast-type-tag { font-weight: 600; color: var(--accent-blue); }
        
        .timing-info { font-size: 0.8rem; color: var(--text-secondary); margin: 4px 0; }
        .note-text { font-size: 0.92rem; line-height: 1.4; margin-top: 4px; white-space: pre-line; }
        
        .celebration-box { margin-top: 8px; padding: 8px 12px; background: rgba(46, 160, 67, 0.1); border-left: 3px solid var(--accent-emerald); border-radius: 0 6px 6px 0; font-size: 0.82rem; color: var(--accent-emerald); }
        
        .delete-btn { background: none; border: border: none; color: var(--text-secondary); cursor: pointer; font-size: 0.75rem; margin-left: auto; }
        .delete-btn:hover { color: #f85149; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>🌙 Saum (Fasting) Tracker</h1>
        <button class="theme-btn" onclick="toggleTheme()" id="themeBtn">🌙 Dark</button>
    </header>

    <div class="pillars-bar">
        <span>✨ Ikhlas (Sincerity)</span>
        <span>🛡️ Taqwa (Mindfulness)</span>
        <span>🤲 Tawakkul (Trust)</span>
        <span>👁️ Tawajjuh (Focus)</span>
    </div>

    <!-- Monthly Target Progress -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $countCompletedThisMonth ?> / <?= $goalTarget ?></div>
            <div class="stat-label">Monthly Goal</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $avgPerWeek ?></div>
            <div class="stat-label">Avg / Week</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: <?= $countCompletedThisMonth >= $minPassTarget ? 'var(--accent-emerald)' : 'var(--accent-gold)' ?>;">
                <?= $countCompletedThisMonth >= $minPassTarget ? 'PASS' : 'NEED 1' ?>
            </div>
            <div class="stat-label">Min Threshold</div>
        </div>
    </div>

    <!-- Calendar View -->
    <div class="section-title">📅 <?= date('F Y') ?> Overview</div>
    <div class="calendar-container">
        <div class="calendar-grid">
            <div class="cal-head">S</div><div class="cal-head">M</div><div class="cal-head">T</div>
            <div class="cal-head">W</div><div class="cal-head">T</div><div class="cal-head">F</div><div class="cal-head">S</div>

            <?php
            for ($i = 0; $i < $dayOfWeek; $i++) {
                echo '<div class="cal-day empty"></div>';
            }
            for ($day = 1; $day <= $numberDays; $day++) {
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $isToday = ($dateStr === date('Y-m-d')) ? 'today' : '';
                $dotClass = '';
                if (isset($loggedDates[$dateStr])) {
                    $dotClass = $loggedDates[$dateStr]['status'] === 'completed' ? 'dot-completed' : 'dot-planned';
                }
                echo "<div class='cal-day {$isToday}'>";
                echo $day;
                if ($dotClass) {
                    echo "<div class='badge-dot {$dotClass}'></div>";
                }
                echo "</div>";
            }
            ?>
        </div>
    </div>

    <!-- Fasting Composer -->
    <div class="composer">
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="add_log">
            
            <div class="grid-2">
                <div>
                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Fast Date</label>
                    <input type="date" name="fast_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Fasting Type</label>
                    <select name="fast_type" required>
                        <option value="monday">Monday Sunnah</option>
                        <option value="thursday">Thursday Sunnah</option>
                        <option value="ayyam_al_bidh">13, 14, 15 White Days (Ayyam al-Bidh)</option>
                        <option value="farz">Farz / Qaza</option>
                        <option value="volatility_other">Other Voluntarily Fast</option>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div>
                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Status</label>
                    <select name="status" required>
                        <option value="completed">✓ Completed Fast</option>
                        <option value="planned">📌 Plan Future Fast</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 0.75rem; color: var(--text-secondary);">Ikhlas / Focus Rating (1-5)</label>
                    <input type="number" name="iklas_rating" min="1" max="5" value="5">
                </div>
            </div>

            <div class="grid-2">
                <input type="text" name="sehri_time" placeholder="Sehri Time (Optional e.g. 04:30 AM)">
                <input type="text" name="iftar_time" placeholder="Iftar Time (Optional e.g. 06:45 PM)">
            </div>

            <textarea name="spiritual_note" placeholder="Optional spiritual reflections, Duwa, or preparation notes..."></textarea>

            <button type="submit" class="btn-submit">🌙 Log / Plan Fasting</button>
        </form>
    </div>

    <!-- Twitter-style Timeline -->
    <ul class="feed">
        <?php if (empty($allEntries)): ?>
            <li class="tweet-card">
                <div class="tweet-content" style="text-align: center; color: var(--text-secondary); padding: 20px 0;">
                    No fasting logs yet. Select a date above to plan or complete your first Sunnah fast!
                </div>
            </li>
        <?php endif; ?>

        <?php foreach ($allEntries as $item): ?>
            <li class="tweet-card">
                <div class="avatar <?= htmlspecialchars($item['status']) ?>">
                    <?= $item['status'] === 'completed' ? '☪' : '📌' ?>
                </div>
                <div class="tweet-content">
                    <div class="tweet-header">
                        <span class="badge <?= htmlspecialchars($item['status']) ?>">
                            <?= htmlspecialchars($item['status']) ?>
                        </span>
                        <span class="fast-type-tag">
                            • <?= htmlspecialchars(ucwords(str_replace('_', ' ', $item['fast_type']))) ?>
                        </span>
                        <span style="color: var(--text-secondary); font-size: 0.8rem; margin-left: auto;">
                            <?= date('D, M d, Y', strtotime($item['fast_date'])) ?>
                        </span>
                    </div>

                    <?php if (!empty($item['sehri_time']) || !empty($item['iftar_time'])): ?>
                        <div class="timing-info">
                            🕒 <?php if(!empty($item['sehri_time'])) echo "Sehri: " . htmlspecialchars($item['sehri_time']); ?>
                            <?php if(!empty($item['sehri_time']) && !empty($item['iftar_time'])) echo " | "; ?>
                            <?php if(!empty($item['iftar_time'])) echo "Iftar: " . htmlspecialchars($item['iftar_time']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($item['spiritual_note'])): ?>
                        <div class="note-text"><?= nl2br(htmlspecialchars($item['spiritual_note'])) ?></div>
                    <?php endif; ?>

                    <?php if ($item['status'] === 'completed'): ?>
                        <div class="celebration-box">
                            🎉 Alhamdulillah! Fast completed with Ikhlas rating: <?= str_repeat('⭐', (int)$item['iklas_rating']) ?>
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; margin-top: 8px;">
                        <form method="POST" action="index.php" style="margin-left: auto;" onsubmit="return confirm('Delete this record?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <button type="submit" class="delete-btn">Delete</button>
                        </form>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    setTheme(newTheme);
}

function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('saum_tracker_theme', theme);
    const themeBtn = document.getElementById('themeBtn');
    themeBtn.textContent = theme === 'light' ? '☀️ Light' : '🌙 Dark';
}

(function() {
    const savedTheme = localStorage.getItem('saum_tracker_theme') || 'dark';
    setTheme(savedTheme);
})();
</script>

</body>
</html>