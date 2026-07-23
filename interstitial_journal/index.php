<?php
/**
 * Interstitial Journal
 * Single-file, SQLite, minimalistic, dark mode, mobile-first journaling for ADHD
 * 
 * @author Solo Dev
 * @license MIT
 */

// ─── CONFIG ──────────────────────────────────────────────────────────
$DB_FILE = __DIR__ . '/journal.db';
$PAGE_SIZE = 50;

// ─── DATABASE ────────────────────────────────────────────────────────
function initDB($dbFile) {
    $db = new SQLite3($dbFile);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content TEXT NOT NULL,
            mood TEXT DEFAULT NULL,
            energy INTEGER DEFAULT NULL CHECK(energy BETWEEN 1 AND 10),
            created_at INTEGER DEFAULT (strftime('%s', 'now')),
            day_key TEXT GENERATED ALWAYS AS (strftime('%Y-%m-%d', datetime(created_at, 'unixepoch'))) STORED
        )
    SQL);

    $db->exec('CREATE INDEX IF NOT EXISTS idx_entries_day ON entries(day_key)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_entries_created ON entries(created_at DESC)');

    return $db;
}

$db = initDB($DB_FILE);

// ─── API ROUTING ─────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace(basename(__FILE__), '', $path);
$path = trim($path, '/');

header('Content-Type: application/json');

// API: Create entry
if ($method === 'POST' && $path === 'api/entries') {
    $input = json_decode(file_get_contents('php://input'), true);
    $content = trim($input['content'] ?? '');
    $mood = $input['mood'] ?? null;
    $energy = isset($input['energy']) ? (int)$input['energy'] : null;

    if (empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => 'Content required']);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO entries (content, mood, energy) VALUES (:content, :mood, :energy)');
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->bindValue(':mood', $mood, $mood ? SQLITE3_TEXT : SQLITE3_NULL);
    $stmt->bindValue(':energy', $energy, $energy ? SQLITE3_INTEGER : SQLITE3_NULL);
    $stmt->execute();

    $id = $db->lastInsertRowID();
    $entry = $db->querySingle("SELECT * FROM entries WHERE id = $id", true);
    $entry['created_at'] = (int)$entry['created_at'];

    echo json_encode(['success' => true, 'entry' => $entry]);
    exit;
}

// API: List entries
if ($method === 'GET' && $path === 'api/entries') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $PAGE_SIZE;
    $day = $_GET['day'] ?? null;

    $where = '';
    $params = [];
    if ($day) {
        $where = "WHERE day_key = :day";
        $params[':day'] = $day;
    }

    $stmt = $db->prepare("SELECT * FROM entries $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $PAGE_SIZE, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    if ($day) $stmt->bindValue(':day', $day, SQLITE3_TEXT);
    $result = $stmt->execute();

    $entries = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['created_at'] = (int)$row['created_at'];
        $entries[] = $row;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM entries $where");
    if ($day) $countStmt->bindValue(':day', $day, SQLITE3_TEXT);
    $total = $countStmt->execute()->fetchArray(SQLITE3_ASSOC)['total'];

    $daysResult = $db->query("SELECT DISTINCT day_key FROM entries ORDER BY day_key DESC LIMIT 30");
    $days = [];
    while ($d = $daysResult->fetchArray(SQLITE3_ASSOC)) {
        $days[] = $d['day_key'];
    }

    $stats = $db->querySingle("
        SELECT 
            COUNT(*) as total_entries,
            COUNT(DISTINCT day_key) as total_days,
            AVG(energy) as avg_energy,
            MAX(created_at) as last_entry
        FROM entries
    ", true);

    echo json_encode([
        'entries' => $entries,
        'pagination' => [
            'page' => $page,
            'per_page' => $PAGE_SIZE,
            'total' => $total,
            'pages' => ceil($total / $PAGE_SIZE)
        ],
        'days' => $days,
        'stats' => $stats
    ]);
    exit;
}

// API: Export
if ($method === 'GET' && $path === 'api/export') {
    header('Content-Type: text/markdown');
    header('Content-Disposition: attachment; filename="interstitial-journal-' . date('Y-m-d') . '.md"');

    $result = $db->query("SELECT * FROM entries ORDER BY created_at ASC");

    echo "# Interstitial Journal Export\n\n";
    echo "> Generated: " . date('Y-m-d H:i:s') . "\n\n";

    $currentDay = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $day = date('Y-m-d', $row['created_at']);
        $time = date('H:i', $row['created_at']);

        if ($day !== $currentDay) {
            echo "\n## $day\n\n";
            $currentDay = $day;
        }

        $moodStr = $row['mood'] ? " [{$row['mood']}]" : '';
        $energyStr = $row['energy'] ? " (energy: {$row['energy']}/10)" : '';

        echo "**$time** — {$row['content']}$moodStr$energyStr\n\n";
    }
    exit;
}

// API: Stats
if ($method === 'GET' && $path === 'api/stats') {
    $stats = $db->querySingle("
        SELECT 
            COUNT(*) as total_entries,
            COUNT(DISTINCT day_key) as total_days,
            ROUND(AVG(energy), 1) as avg_energy,
            MAX(created_at) as last_entry,
            MIN(created_at) as first_entry
        FROM entries
    ", true);

    $dailyResult = $db->query("
        SELECT day_key, COUNT(*) as count, ROUND(AVG(energy), 1) as avg_energy
        FROM entries
        WHERE day_key >= date('now', '-14 days')
        GROUP BY day_key
        ORDER BY day_key ASC
    ");
    $daily = [];
    while ($d = $dailyResult->fetchArray(SQLITE3_ASSOC)) {
        $daily[] = $d;
    }

    $moodResult = $db->query("
        SELECT mood, COUNT(*) as count
        FROM entries
        WHERE mood IS NOT NULL
        GROUP BY mood
        ORDER BY count DESC
    ");
    $moods = [];
    while ($m = $moodResult->fetchArray(SQLITE3_ASSOC)) {
        $moods[] = $m;
    }

    echo json_encode([
        'overview' => $stats,
        'daily' => $daily,
        'moods' => $moods
    ]);
    exit;
}

// ─── HTML FRONTEND ───────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0a0a0f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Interstitial Journal</title>
    <style>
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --bg-tertiary: #1a1a28;
            --bg-input: #0f0f18;
            --text-primary: #e8e8f0;
            --text-secondary: #9a9ab0;
            --text-muted: #6a6a80;
            --accent: #7c6fff;
            --accent-soft: rgba(124, 111, 255, 0.15);
            --accent-glow: rgba(124, 111, 255, 0.4);
            --border: rgba(255,255,255,0.06);
            --border-focus: rgba(124, 111, 255, 0.5);
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --radius: 14px;
            --radius-sm: 8px;
            --shadow: 0 4px 24px rgba(0,0,0,0.4);
            --font-mono: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
            --font-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --tap-size: 48px;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        html {
            font-size: 16px;
            -webkit-text-size-adjust: 100%;
        }

        body {
            font-family: var(--font-sans);
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .app {
            max-width: 640px;
            margin: 0 auto;
            padding: 0 16px 120px;
            position: relative;
        }

        .header {
            padding: 24px 0 16px;
            position: sticky;
            top: 0;
            background: linear-gradient(180deg, var(--bg-primary) 70%, transparent);
            z-index: 100;
            backdrop-filter: blur(12px);
        }

        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .logo {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text-primary);
        }

        .logo span {
            color: var(--accent);
        }

        .header-actions {
            display: flex;
            gap: 8px;
        }

        .icon-btn {
            width: var(--tap-size);
            height: var(--tap-size);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 1.1rem;
        }

        .icon-btn:hover, .icon-btn:active {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border-color: var(--border-focus);
        }

        .stats-bar {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }

        .stats-bar::-webkit-scrollbar {
            display: none;
        }

        .stat-pill {
            flex-shrink: 0;
            padding: 6px 14px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 100px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .stat-pill strong {
            color: var(--accent);
            font-weight: 600;
        }

        .input-area {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(0deg, var(--bg-primary) 60%, transparent);
            padding: 16px;
            z-index: 200;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
        }

        .input-wrapper {
            max-width: 640px;
            margin: 0 auto;
            position: relative;
        }

        .input-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 56px 14px 16px;
            min-height: 56px;
            max-height: 160px;
            overflow-y: auto;
            font-family: var(--font-sans);
            font-size: 1rem;
            color: var(--text-primary);
            line-height: 1.6;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            word-break: break-word;
        }

        .input-box:empty::before {
            content: "What just happened? What's next?";
            color: var(--text-muted);
            pointer-events: none;
        }

        .input-box:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }

        .submit-btn {
            position: absolute;
            right: 8px;
            bottom: 8px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.1rem;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 2px 12px var(--accent-glow);
        }

        .submit-btn:hover, .submit-btn:active {
            transform: scale(1.05);
            box-shadow: 0 4px 20px var(--accent-glow);
        }

        .submit-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none;
        }

        .mood-bar {
            display: flex;
            gap: 6px;
            margin-top: 10px;
            padding: 0 4px;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .mood-bar::-webkit-scrollbar {
            display: none;
        }

        .mood-btn {
            flex-shrink: 0;
            padding: 6px 14px;
            border-radius: 100px;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .mood-btn:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .mood-btn.active {
            background: var(--accent-soft);
            border-color: var(--accent);
            color: var(--accent);
        }

        .energy-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 0 4px;
        }

        .energy-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .energy-dots {
            display: flex;
            gap: 6px;
        }

        .energy-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .energy-dot:hover, .energy-dot.active {
            background: var(--accent);
            border-color: var(--accent);
            box-shadow: 0 0 8px var(--accent-glow);
        }

        .entries-section {
            margin-top: 8px;
        }

        .day-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 28px 0 16px;
            padding: 0 4px;
        }

        .day-header:first-child {
            margin-top: 0;
        }

        .day-header h2 {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            white-space: nowrap;
        }

        .day-header .day-line {
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .entry-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
            transition: border-color 0.2s ease;
        }

        .entry-card:hover {
            border-color: rgba(124, 111, 255, 0.2);
        }

        .entry-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .entry-time {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            color: var(--accent);
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .entry-badges {
            display: flex;
            gap: 6px;
        }

        .badge {
            padding: 2px 8px;
            border-radius: 100px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .badge-mood {
            background: rgba(124, 111, 255, 0.12);
            color: var(--accent);
        }

        .badge-energy {
            background: rgba(74, 222, 128, 0.12);
            color: var(--success);
        }

        .entry-content {
            font-size: 0.95rem;
            line-height: 1.7;
            color: var(--text-primary);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state .empty-icon {
            font-size: 3rem;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 0.9rem;
            line-height: 1.6;
            max-width: 300px;
            margin: 0 auto;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
            padding-bottom: 20px;
        }

        .page-btn {
            min-width: var(--tap-size);
            height: var(--tap-size);
            padding: 0 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .page-btn:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .page-btn.active {
            background: var(--accent-soft);
            border-color: var(--accent);
            color: var(--accent);
        }

        .page-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.25s ease;
        }

        .modal-overlay.open {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius) var(--radius) 0 0;
            width: 100%;
            max-width: 640px;
            max-height: 80vh;
            overflow-y: auto;
            padding: 24px;
            transform: translateY(100%);
            transition: transform 0.25s ease;
        }

        .modal-overlay.open .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 1.2rem;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            text-align: center;
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--accent);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .chart-container {
            margin-top: 20px;
        }

        .chart-title {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }

        .chart-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .chart-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            width: 70px;
            text-align: right;
            flex-shrink: 0;
        }

        .chart-track {
            flex: 1;
            height: 24px;
            background: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            overflow: hidden;
            position: relative;
        }

        .chart-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), #a594ff);
            border-radius: var(--radius-sm);
            transition: width 0.6s ease;
            min-width: 4px;
        }

        .chart-value {
            font-size: 0.8rem;
            color: var(--text-secondary);
            width: 30px;
            text-align: left;
        }

        .toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(-60px);
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 20px;
            color: var(--text-primary);
            font-size: 0.9rem;
            z-index: 3000;
            opacity: 0;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
            white-space: nowrap;
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (min-width: 640px) {
            .app {
                padding: 0 24px 140px;
            }

            .input-area {
                padding: 20px;
            }

            .modal-overlay {
                align-items: center;
            }

            .modal-content {
                border-radius: var(--radius);
                max-height: 70vh;
                transform: scale(0.95);
                opacity: 0;
            }

            .modal-overlay.open .modal-content {
                transform: scale(1);
                opacity: 1;
            }
        }

        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--bg-tertiary);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }

        ::selection {
            background: var(--accent-soft);
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <div class="app">
        <header class="header">
            <div class="header-top">
                <div class="logo">Interstitial <span>Journal</span></div>
                <div class="header-actions">
                    <button class="icon-btn" id="btnStats" title="Statistics" aria-label="Statistics">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </button>
                    <button class="icon-btn" id="btnExport" title="Export" aria-label="Export">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </button>
                </div>
            </div>
            <div class="stats-bar" id="statsBar">
                <div class="stat-pill">Loading...</div>
            </div>
        </header>

        <section class="entries-section" id="entriesSection">
            <div class="empty-state">
                <div class="empty-icon">&#9997;&#65039;</div>
                <h3>Start your first entry</h3>
                <p>Write 2-4 sentences about what you just finished and what's next. Hit Enter to save.</p>
            </div>
        </section>

        <div class="pagination" id="pagination"></div>
    </div>

    <div class="input-area">
        <div class="input-wrapper">
            <div class="input-box" id="inputBox" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Journal entry"></div>
            <button class="submit-btn" id="submitBtn" aria-label="Submit entry">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </div>
        <div class="mood-bar" id="moodBar">
            <button class="mood-btn" data-mood="focused">&#127919; Focused</button>
            <button class="mood-btn" data-mood="tired">&#128564; Tired</button>
            <button class="mood-btn" data-mood="anxious">&#128560; Anxious</button>
            <button class="mood-btn" data-mood="energized">&#9889; Energized</button>
            <button class="mood-btn" data-mood="frustrated">&#128548; Frustrated</button>
            <button class="mood-btn" data-mood="calm">&#129496; Calm</button>
            <button class="mood-btn" data-mood="overwhelmed">&#128565; Overwhelmed</button>
            <button class="mood-btn" data-mood="proud">&#127881; Proud</button>
        </div>
        <div class="energy-bar" id="energyBar">
            <span class="energy-label">Energy</span>
            <div class="energy-dots" id="energyDots">
                <div class="energy-dot" data-energy="1"></div>
                <div class="energy-dot" data-energy="2"></div>
                <div class="energy-dot" data-energy="3"></div>
                <div class="energy-dot" data-energy="4"></div>
                <div class="energy-dot" data-energy="5"></div>
                <div class="energy-dot" data-energy="6"></div>
                <div class="energy-dot" data-energy="7"></div>
                <div class="energy-dot" data-energy="8"></div>
                <div class="energy-dot" data-energy="9"></div>
                <div class="energy-dot" data-energy="10"></div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="statsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>&#128202; Your Patterns</h2>
                <button class="modal-close" id="closeStats">&times;</button>
            </div>
            <div class="stats-grid" id="statsGrid"></div>
            <div class="chart-container" id="dailyChart"></div>
            <div class="chart-container" id="moodChart"></div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        let currentPage = 1;
        let selectedMood = null;
        let selectedEnergy = null;
        let isSubmitting = false;

        const inputBox = document.getElementById('inputBox');
        const submitBtn = document.getElementById('submitBtn');
        const entriesSection = document.getElementById('entriesSection');
        const pagination = document.getElementById('pagination');
        const statsBar = document.getElementById('statsBar');
        const toast = document.getElementById('toast');
        const statsModal = document.getElementById('statsModal');
        const moodBtns = document.querySelectorAll('.mood-btn');
        const energyDots = document.querySelectorAll('.energy-dot');

        function formatTime(ts) {
            const d = new Date(ts * 1000);
            return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
        }

        function formatDay(ts) {
            const d = new Date(ts * 1000);
            const today = new Date();
            const yesterday = new Date(today); yesterday.setDate(yesterday.getDate() - 1);

            if (d.toDateString() === today.toDateString()) return 'Today';
            if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
            return d.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
        }

        function showToast(msg, duration = 2000) {
            toast.textContent = msg;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), duration);
        }

        async function api(method, endpoint, body = null) {
            const opts = { method, headers: { 'Content-Type': 'application/json' } };
            if (body) opts.body = JSON.stringify(body);
            const res = await fetch(endpoint, opts);
            if (!res.ok) throw new Error(await res.text());
            return res.json();
        }

        function renderEntries(data) {
            const { entries, pagination: pag, stats } = data;

            if (stats) {
                const days = stats.total_days || 0;
                const total = stats.total_entries || 0;
                const avgEnergy = stats.avg_energy ? parseFloat(stats.avg_energy).toFixed(1) : '—';
                const lastEntry = stats.last_entry ? formatTime(stats.last_entry) : '—';

                statsBar.innerHTML = `
                    <div class="stat-pill"><strong>${total}</strong> entries</div>
                    <div class="stat-pill"><strong>${days}</strong> days</div>
                    <div class="stat-pill">&#9889; <strong>${avgEnergy}</strong> avg</div>
                    <div class="stat-pill">Last: <strong>${lastEntry}</strong></div>
                `;
            }

            if (entries.length === 0 && currentPage === 1) {
                entriesSection.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">&#9997;&#65039;</div>
                        <h3>Start your first entry</h3>
                        <p>Write 2-4 sentences about what you just finished and what's next. Hit Enter to save.</p>
                    </div>
                `;
                pagination.innerHTML = '';
                return;
            }

            const grouped = {};
            entries.forEach(e => {
                const day = new Date(e.created_at * 1000).toDateString();
                if (!grouped[day]) grouped[day] = [];
                grouped[day].push(e);
            });

            let html = '';
            Object.keys(grouped).forEach(dayKey => {
                const dayEntries = grouped[dayKey];
                const firstEntry = dayEntries[0];

                html += `
                    <div class="day-header">
                        <h2>${formatDay(firstEntry.created_at)}</h2>
                        <div class="day-line"></div>
                    </div>
                `;

                dayEntries.forEach(entry => {
                    const moodBadge = entry.mood ? `<span class="badge badge-mood">${entry.mood}</span>` : '';
                    const energyBadge = entry.energy ? `<span class="badge badge-energy">${entry.energy}/10</span>` : '';

                    html += `
                        <article class="entry-card">
                            <div class="entry-header">
                                <time class="entry-time">${formatTime(entry.created_at)}</time>
                                <div class="entry-badges">
                                    ${moodBadge}
                                    ${energyBadge}
                                </div>
                            </div>
                            <div class="entry-content">${escapeHtml(entry.content)}</div>
                        </article>
                    `;
                });
            });

            entriesSection.innerHTML = html;

            if (pag.pages > 1) {
                let pagHtml = '';
                if (pag.page > 1) {
                    pagHtml += `<button class="page-btn" onclick="loadPage(${pag.page - 1})">&#8592; Prev</button>`;
                }
                pagHtml += `<button class="page-btn active">${pag.page} / ${pag.pages}</button>`;
                if (pag.page < pag.pages) {
                    pagHtml += `<button class="page-btn" onclick="loadPage(${pag.page + 1})">Next &#8594;</button>`;
                }
                pagination.innerHTML = pagHtml;
            } else {
                pagination.innerHTML = '';
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        async function loadPage(page = 1) {
            currentPage = page;
            try {
                const data = await api('GET', `api/entries?page=${page}`);
                renderEntries(data);
            } catch (err) {
                showToast('Failed to load entries');
                console.error(err);
            }
        }

        async function submitEntry() {
            const content = inputBox.textContent.trim();
            if (!content || isSubmitting) return;

            isSubmitting = true;
            submitBtn.disabled = true;

            try {
                const data = await api('POST', 'api/entries', {
                    content,
                    mood: selectedMood,
                    energy: selectedEnergy
                });

                inputBox.textContent = '';
                selectedMood = null;
                selectedEnergy = null;
                updateMoodUI();
                updateEnergyUI();

                await loadPage(1);
                showToast('Entry saved &#10003;');
                window.scrollTo({ top: 0, behavior: 'smooth' });

            } catch (err) {
                showToast('Failed to save entry');
                console.error(err);
            } finally {
                isSubmitting = false;
                submitBtn.disabled = false;
            }
        }

        function updateMoodUI() {
            moodBtns.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.mood === selectedMood);
            });
        }

        function updateEnergyUI() {
            energyDots.forEach((dot, i) => {
                dot.classList.toggle('active', i < (selectedEnergy || 0));
            });
        }

        moodBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                selectedMood = selectedMood === btn.dataset.mood ? null : btn.dataset.mood;
                updateMoodUI();
            });
        });

        energyDots.forEach(dot => {
            dot.addEventListener('click', () => {
                const val = parseInt(dot.dataset.energy);
                selectedEnergy = selectedEnergy === val ? null : val;
                updateEnergyUI();
            });
        });

        inputBox.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submitEntry();
            }
        });

        submitBtn.addEventListener('click', submitEntry);

        document.getElementById('btnExport').addEventListener('click', () => {
            window.location.href = 'api/export';
            showToast('Export downloaded &#10003;');
        });

        document.getElementById('btnStats').addEventListener('click', async () => {
            statsModal.classList.add('open');
            try {
                const data = await api('GET', 'api/stats');
                renderStats(data);
            } catch (err) {
                showToast('Failed to load stats');
            }
        });

        document.getElementById('closeStats').addEventListener('click', () => {
            statsModal.classList.remove('open');
        });

        statsModal.addEventListener('click', (e) => {
            if (e.target === statsModal) statsModal.classList.remove('open');
        });

        function renderStats(data) {
            const { overview, daily, moods } = data;

            document.getElementById('statsGrid').innerHTML = `
                <div class="stat-card">
                    <div class="stat-value">${overview.total_entries || 0}</div>
                    <div class="stat-label">Total Entries</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${overview.total_days || 0}</div>
                    <div class="stat-label">Days Journaling</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${overview.avg_energy || '—'}</div>
                    <div class="stat-label">Avg Energy</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${overview.last_entry ? formatTime(overview.last_entry) : '—'}</div>
                    <div class="stat-label">Last Entry</div>
                </div>
            `;

            if (daily.length > 0) {
                const maxCount = Math.max(...daily.map(d => d.count));
                let dailyHtml = '<div class="chart-title">&#128197; Entries per Day (Last 14 Days)</div>';
                daily.forEach(d => {
                    const pct = (d.count / maxCount * 100).toFixed(0);
                    const date = new Date(d.day_key).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    dailyHtml += `
                        <div class="chart-bar">
                            <div class="chart-label">${date}</div>
                            <div class="chart-track">
                                <div class="chart-fill" style="width: ${pct}%"></div>
                            </div>
                            <div class="chart-value">${d.count}</div>
                        </div>
                    `;
                });
                document.getElementById('dailyChart').innerHTML = dailyHtml;
            }

            if (moods.length > 0) {
                const maxMood = Math.max(...moods.map(m => m.count));
                let moodHtml = '<div class="chart-title">&#127912; Mood Distribution</div>';
                moods.forEach(m => {
                    const pct = (m.count / maxMood * 100).toFixed(0);
                    moodHtml += `
                        <div class="chart-bar">
                            <div class="chart-label">${m.mood}</div>
                            <div class="chart-track">
                                <div class="chart-fill" style="width: ${pct}%"></div>
                            </div>
                            <div class="chart-value">${m.count}</div>
                        </div>
                    `;
                });
                document.getElementById('moodChart').innerHTML = moodHtml;
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                inputBox.focus();
            }
            if (e.key === 'Escape') {
                inputBox.blur();
                statsModal.classList.remove('open');
            }
        });

        loadPage(1);

        if (window.innerWidth > 768) {
            setTimeout(() => inputBox.focus(), 300);
        }
    </script>
</body>
</html>