<?php
// --- 1. DATABASE INIT & AUTO-MIGRATION ---
$dbFile = __DIR__ . '/wins.db';
$pdo = new PDO("sqlite:$dbFile");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE IF NOT EXISTS wins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content TEXT NOT NULL,
    category TEXT DEFAULT 'General',
    priority TEXT DEFAULT 'normal', -- urgent, important, normal
    timeframe TEXT DEFAULT 'today',  -- now, today, week, month
    tags TEXT DEFAULT '',
    likes INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Quick schema patch for older database files
$cols =$pdo->query("PRAGMA table_info(wins)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('priority', $cols))$pdo->exec("ALTER TABLE wins ADD COLUMN priority TEXT DEFAULT 'normal'");
if (!in_array('timeframe', $cols))$pdo->exec("ALTER TABLE wins ADD COLUMN timeframe TEXT DEFAULT 'today'");
if (!in_array('tags', $cols))$pdo->exec("ALTER TABLE wins ADD COLUMN tags TEXT DEFAULT ''");

// --- 2. POST / ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? '';

    if ($action === 'add') {
        $content = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? '🎯 Product');
        $priority = trim($_POST['priority'] ?? 'normal');
        $timeframe = trim($_POST['timeframe'] ?? 'today');
        $tags = trim($_POST['tags'] ?? '');
        
        if ($content !== '') {
            $stmt =$pdo->prepare("INSERT INTO wins (content, category, priority, timeframe, tags) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$content, $category,$priority, $timeframe,$tags]);
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $content = trim($_POST['content'] ?? '');
        $priority = trim($_POST['priority'] ?? 'normal');
        $timeframe = trim($_POST['timeframe'] ?? 'today');
        $tags = trim($_POST['tags'] ?? '');
        
        if ($content !== '') {
            $stmt =$pdo->prepare("UPDATE wins SET content = ?, priority = ?, timeframe = ?, tags = ? WHERE id = ?");
            $stmt->execute([$content, $priority,$timeframe, $tags,$id]);
        }
    } elseif ($action === 'delete') {
        $stmt =$pdo->prepare("DELETE FROM wins WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
    } elseif ($action === 'like') {
        $stmt =$pdo->prepare("UPDATE wins SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?celebrate=true");
    exit;
}

// --- 3. EXPORT HANDLERS ---
if (isset($_GET['export'])) {
    $type =$_GET['export'];
    $wins =$pdo->query("SELECT * FROM wins ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    if ($type === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="wins_enhanced.json"');
        echo json_encode($wins, JSON_PRETTY_PRINT);
        exit;
    } elseif ($type === 'md' ||$type === 'txt') {
        header('Content-Type: text/plain');
        header("Content-Disposition: attachment; filename=\"wins_enhanced.{$type}\"");
        foreach ($wins as$w) {
            $date = date('Y-m-d H:i', strtotime($w['created_at']));
            $p = strtoupper($w['priority']);
            $t = strtoupper($w['timeframe']);
            echo "🏆 [{$date}] [{$p}] [Time: {$t}] ({$w['category']})\n";
            echo "Tags: {$w['tags']}\n";
            echo "{$w['content']}\n";
            echo "❤️ Likes: {$w['likes']}\n---\n\n";
        }
        exit;
    }
}

// --- 4. DATA FETCHING ---
$wins =$pdo->query("SELECT * FROM wins ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$totalWins = count($wins);
$urgentCount =$pdo->query("SELECT COUNT(*) FROM wins WHERE priority = 'urgent'")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WinsFeed Pro — Enhanced Victory Engine</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        body { background-color: #09090b; color: #f4f4f5; font-family: system-ui, -apple-system, sans-serif; }
        .bg-pattern-grid {
            background-image: radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 0);
            background-size: 24px 24px;
        }
        .bg-pattern-dots {
            background-image: radial-gradient(#3f3f46 1px, transparent 1px);
            background-size: 16px 16px;
        }
        .bg-pattern-waves {
            background-image: repeating-linear-gradient(45deg, rgba(255,255,255,0.02) 0, rgba(255,255,255,0.02) 10px, transparent 0, transparent 50px);
        }
        .bg-pattern-mesh {
            background-image: linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 32px 32px;
        }
    </style>
</head>
<body id="app-body" class="bg-pattern-grid min-h-screen flex flex-col justify-between">

    <!-- Top Header -->
    <header class="sticky top-0 z-50 backdrop-blur-md bg-zinc-950/80 border-b border-zinc-800/80 px-4 py-3">
        <div class="max-w-2xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-xl">🚀</span>
                <h1 class="text-lg font-bold tracking-tight text-white">WinsFeed Pro</h1>
            </div>
            
            <!-- Pattern Switcher & Counters -->
            <div class="flex items-center gap-3">
                <div class="flex bg-zinc-900 border border-zinc-800 rounded-lg p-1 text-[10px] text-zinc-400">
                    <button onclick="setPattern('bg-pattern-grid')" class="px-2 py-0.5 rounded hover:text-white">Grid</button>
                    <button onclick="setPattern('bg-pattern-dots')" class="px-2 py-0.5 rounded hover:text-white">Dots</button>
                    <button onclick="setPattern('bg-pattern-waves')" class="px-2 py-0.5 rounded hover:text-white">Waves</button>
                    <button onclick="setPattern('bg-pattern-mesh')" class="px-2 py-0.5 rounded hover:text-white">Mesh</button>
                </div>
                <?php if ($urgentCount > 0): ?>
                    <div class="bg-red-500/10 border border-red-500/30 px-2.5 py-1 rounded-full text-xs font-semibold text-red-400">
                        🚨 <?= $urgentCount ?> Urgent
                    </div>
                <?php endif; ?>
                <div class="bg-amber-500/10 border border-amber-500/20 px-3 py-1 rounded-full text-xs font-semibold text-amber-400">
                    🏆 <?= $totalWins ?>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-2xl w-full mx-auto p-4 flex-grow">

        <!-- ENHANCED COMPOSER -->
        <section class="bg-zinc-900/90 border border-zinc-800 rounded-2xl p-4 shadow-xl mb-6">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="flex gap-3">
                    <div class="w-10 h-10 rounded-full bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-lg font-bold text-amber-400 shrink-0">
                        ✨
                    </div>
                    <div class="flex-grow space-y-2">
                        <textarea name="content" rows="3" placeholder="Log your win, milestone, or task victory..." required
                                  class="w-full bg-transparent text-zinc-100 placeholder-zinc-500 text-sm focus:outline-none resize-none"></textarea>
                        
                        <!-- Priority & Timeframe Selectors -->
                        <div class="grid grid-cols-3 gap-2 pt-2 border-t border-zinc-800/60">
                            <div>
                                <label class="block text-[10px] text-zinc-500 font-bold uppercase mb-1">Priority</label>
                                <select name="priority" class="w-full bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded-lg p-1.5 focus:outline-none">
                                    <option value="normal">🟢 Normal</option>
                                    <option value="important">⭐ Important</option>
                                    <option value="urgent">🚨 Urgent</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] text-zinc-500 font-bold uppercase mb-1">Timeframe</label>
                                <select name="timeframe" class="w-full bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded-lg p-1.5 focus:outline-none">
                                    <option value="now">⚡ Now</option>
                                    <option value="today" selected>📅 Today</option>
                                    <option value="week">🗓️ This Week</option>
                                    <option value="month">📊 This Month</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] text-zinc-500 font-bold uppercase mb-1">Category</label>
                                <select name="category" class="w-full bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded-lg p-1.5 focus:outline-none">
                                    <option value="🎯 Product">🎯 Product</option>
                                    <option value="💡 Code">💡 Code</option>
                                    <option value="📈 Business">📈 Business</option>
                                    <option value="🧠 Personal">🧠 Personal</option>
                                </select>
                            </div>
                        </div>

                        <!-- Tags and Submit Button -->
                        <div class="flex items-center justify-between pt-2">
                            <input type="text" name="tags" placeholder="Tags (e.g. #v1 #ui #deploy)" 
                                   class="bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded-lg px-2.5 py-1.5 focus:outline-none w-2/3">
                            <button type="submit" 
                                    class="bg-amber-500 hover:bg-amber-400 text-black font-semibold text-xs px-4 py-2 rounded-full transition shadow-lg shadow-amber-500/20 flex items-center gap-1.5">
                                <span>Celebrate Win</span> 🎉
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <!-- WINS TIMELINE -->
        <section class="space-y-4">
            <?php if (empty($wins)): ?>
                <div class="text-center py-12 bg-zinc-900/40 border border-zinc-800/50 rounded-2xl">
                    <p class="text-zinc-500 text-sm">No victories recorded yet. Start logging above!</p>
                </div>
            <?php endif; ?>

            <?php foreach ($wins as$win): ?>
            <?php 
                // Priority Styling
                $pColor = 'border-zinc-800/80';$pBadge = 'bg-zinc-800 text-zinc-400';
                if ($win['priority'] === 'urgent') {$pColor = 'border-red-500/50 bg-red-950/10';
                    $pBadge = 'bg-red-500/20 text-red-400 border border-red-500/30';                 } elseif ($win['priority'] === 'important') {
                    $pColor = 'border-amber-500/40 bg-amber-950/10';$pBadge = 'bg-amber-500/20 text-amber-400 border border-amber-500/30';
                }
            ?>
            <article class="bg-zinc-900/60 border <?= $pColor ?> hover:border-zinc-700/80 transition rounded-2xl p-4 group">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <!-- Priority Badge -->
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider <?= $pBadge ?>">
                            <?= htmlspecialchars($win['priority']) ?>
                        </span>
                        <!-- Timeframe Badge -->
                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-300 font-mono">
                            ⏱️ <?= htmlspecialchars($win['timeframe']) ?>
                        </span>
                        <!-- Category Badge -->
                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-zinc-800/80 text-zinc-400">
                            <?= htmlspecialchars($win['category']) ?>
                        </span>
                        <span class="text-[10px] text-zinc-500 ml-1">
                            <?= date('M j, Y • g:i a', strtotime($win['created_at'])) ?>
                        </span>
                    </div>

                    <!-- Edit/Delete Actions -->
                    <div class="flex items-center gap-2 opacity-60 group-hover:opacity-100 transition">
                        <button onclick="toggleEdit(<?= $win['id'] ?>)" class="text-xs text-zinc-400 hover:text-white">✏️</button>
                        <form method="POST" class="inline" onsubmit="return confirm('Delete this win?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $win['id'] ?>">
                            <button class="text-xs text-zinc-500 hover:text-red-400">✕</button>
                        </form>
                    </div>
                </div>

                <!-- Display Content -->
                <p id="content-<?= $win['id'] ?>" class="text-sm text-zinc-200 leading-relaxed whitespace-pre-wrap my-2">
                    <?= htmlspecialchars($win['content']) ?>
                </p>

                <?php if (!empty($win['tags'])): ?>
                    <div id="tags-<?= $win['id'] ?>" class="flex flex-wrap gap-1 my-1">
                        <?php foreach (explode(' ', $win['tags']) as$tag): ?>
                            <span class="text-[11px] text-amber-400/80 bg-amber-400/5 px-1.5 py-0.5 rounded font-mono"><?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Inline Edit Form -->
                <form id="edit-form-<?= $win['id'] ?>" method="POST" class="hidden my-2 space-y-2">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= $win['id'] ?>">
                    <textarea name="content" rows="2" class="w-full bg-zinc-950 border border-zinc-700 rounded-lg p-2 text-xs text-white focus:outline-none"><?= htmlspecialchars($win['content']) ?></textarea>
                    
                    <div class="grid grid-cols-3 gap-2">
                        <select name="priority" class="bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded p-1">
                            <option value="normal" <?= $win['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="important" <?= $win['priority'] === 'important' ? 'selected' : '' ?>>Important</option>
                            <option value="urgent" <?= $win['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        </select>
                        <select name="timeframe" class="bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded p-1">
                            <option value="now" <?= $win['timeframe'] === 'now' ? 'selected' : '' ?>>Now</option>
                            <option value="today" <?= $win['timeframe'] === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= $win['timeframe'] === 'week' ? 'selected' : '' ?>>This Week</option>
                            <option value="month" <?= $win['timeframe'] === 'month' ? 'selected' : '' ?>>This Month</option>
                        </select>
                        <input type="text" name="tags" value="<?= htmlspecialchars($win['tags']) ?>" class="bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded p-1">
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" onclick="toggleEdit(<?= $win['id'] ?>)" class="text-xs text-zinc-400 px-2 py-1">Cancel</button>
                        <button type="submit" class="bg-amber-500 text-xs text-black font-semibold px-3 py-1 rounded-md">Save Changes</button>
                    </div>
                </form>

                <!-- Footer Likes -->
                <div class="flex items-center gap-4 mt-3 pt-2 border-t border-zinc-800/40 text-xs text-zinc-500">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="like">
                        <input type="hidden" name="id" value="<?= $win['id'] ?>">
                        <button type="submit" onclick="fireMicroConfetti(event)" class="hover:text-red-400 flex items-center gap-1 transition">
                            <span>❤️</span> <span><?= $win['likes'] ?></span>
                        </button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </section>

    </main>

    <!-- Footer -->
    <footer class="border-t border-zinc-800/80 bg-zinc-950/60 py-4 px-4 text-xs text-zinc-500 mt-8">
        <div class="max-w-2xl mx-auto flex flex-wrap justify-between items-center gap-2">
            <div>WinsFeed Pro • Native PHP + SQLite Engine</div>
            <div class="flex gap-3">
                <span>Export:</span>
                <a href="?export=json" class="hover:text-amber-400 underline">JSON</a>
                <a href="?export=md" class="hover:text-amber-400 underline">Markdown</a>
                <a href="?export=txt" class="hover:text-amber-400 underline">Text</a>
            </div>
        </div>
    </footer>

    <script>
        function setPattern(patternClass) {
            const body = document.getElementById('app-body');
            body.className = `${patternClass} min-h-screen flex flex-col justify-between`;
            localStorage.setItem('wins_pattern', patternClass);
        }
        const savedPattern = localStorage.getItem('wins_pattern') || 'bg-pattern-grid';
        setPattern(savedPattern);

        function toggleEdit(id) {
            document.getElementById(`content-${id}`).classList.toggle('hidden');
            const tags = document.getElementById(`tags-${id}`);
            if (tags) tags.classList.toggle('hidden');
            document.getElementById(`edit-form-${id}`).classList.toggle('hidden');
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('celebrate')) {
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 },
                colors: ['#f59e0b', '#ef4444', '#10b981', '#6366f1']
            });
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        function fireMicroConfetti(e) {
            const rect = e.target.getBoundingClientRect();
            confetti({
                particleCount: 15,
                spread: 30,
                startVelocity: 15,
                origin: {
                    x: (rect.left + rect.width / 2) / window.innerWidth,
                    y: (rect.top + rect.height / 2) / window.innerHeight
                }
            });
        }
    </script>
</body>
</html>