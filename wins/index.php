<?php
// --- 1. DATABASE INIT ---
$dbFile = __DIR__ . '/wins.db';
$pdo = new PDO("sqlite:$dbFile");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE IF NOT EXISTS wins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content TEXT NOT NULL,
    category TEXT DEFAULT 'General',
    likes INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// --- 2. POST / ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $content = trim($_POST['content'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        if ($content !== '') {
            $stmt = $pdo->prepare("INSERT INTO wins (content, category) VALUES (?, ?)");
            $stmt->execute([$content, $category]);
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $content = trim($_POST['content'] ?? '');
        if ($content !== '') {
            $stmt = $pdo->prepare("UPDATE wins SET content = ? WHERE id = ?");
            $stmt->execute([$content, $id]);
        }
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM wins WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
    } elseif ($action === 'like') {
        $stmt = $pdo->prepare("UPDATE wins SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([(int)$_POST['id']]);
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?celebrate=true");
    exit;
}

// --- 3. EXPORT HANDLERS ---
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    $wins = $pdo->query("SELECT * FROM wins ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    if ($type === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="wins_feed.json"');
        echo json_encode($wins, JSON_PRETTY_PRINT);
        exit;
    } elseif ($type === 'md' || $type === 'txt') {
        header('Content-Type: text/plain');
        header("Content-Disposition: attachment; filename=\"wins_feed.{$type}\"");
        foreach ($wins as $w) {
            $date = date('Y-m-d H:i', strtotime($w['created_at']));
            echo "🏆 [{$date}] ({$w['category']})\n{$w['content']}\n❤️ Likes: {$w['likes']}\n---\n\n";
        }
        exit;
    }
}

// --- 4. DATA FETCHING ---
$wins = $pdo->query("SELECT * FROM wins ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$totalWins = count($wins);
$totalLikes = $pdo->query("SELECT SUM(likes) FROM wins")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WinsFeed — Celebrate Your Victories</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        /* CSS Background Pattern Switcher */
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
    </style>
</head>
<body id="app-body" class="bg-pattern-grid min-h-screen flex flex-col justify-between">

    <!-- Top Navigation Header -->
    <header class="sticky top-0 z-50 backdrop-blur-md bg-zinc-950/80 border-b border-zinc-800/80 px-4 py-3">
        <div class="max-w-2xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-xl">🚀</span>
                <h1 class="text-lg font-bold tracking-tight text-white">WinsFeed</h1>
            </div>
            
            <!-- Pattern Theme Toggle & Stats -->
            <div class="flex items-center gap-3">
                <div class="flex bg-zinc-900 border border-zinc-800 rounded-lg p-1 text-xs text-zinc-400">
                    <button onclick="setPattern('bg-pattern-grid')" class="px-2 py-0.5 rounded hover:text-white">Grid</button>
                    <button onclick="setPattern('bg-pattern-dots')" class="px-2 py-0.5 rounded hover:text-white">Dots</button>
                    <button onclick="setPattern('bg-pattern-waves')" class="px-2 py-0.5 rounded hover:text-white">Waves</button>
                </div>
                <div class="bg-amber-500/10 border border-amber-500/20 px-3 py-1 rounded-full text-xs font-semibold text-amber-400">
                    🏆 <?= $totalWins ?> Wins
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-2xl w-full mx-auto p-4 flex-grow">

        <!-- TWITTER-STYLE WIN COMPOSER -->
        <section class="bg-zinc-900/90 border border-zinc-800 rounded-2xl p-4 shadow-xl mb-6">
            <form method="POST" id="win-form">
                <input type="hidden" name="action" value="add">
                <div class="flex gap-3">
                    <div class="w-10 h-10 rounded-full bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-lg font-bold text-amber-400 shrink-0">
                        ✨
                    </div>
                    <div class="flex-grow">
                        <textarea name="content" rows="3" placeholder="What victory did you achieve today? (Big or small)..." required
                                  class="w-full bg-transparent text-zinc-100 placeholder-zinc-500 text-sm focus:outline-none resize-none"></textarea>
                        
                        <div class="flex items-center justify-between border-t border-zinc-800/80 pt-3 mt-2">
                            <select name="category" class="bg-zinc-950 border border-zinc-800 text-xs text-zinc-300 rounded-lg px-2.5 py-1.5 focus:outline-none">
                                <option value="🎯 Product">🎯 Product</option>
                                <option value="💡 Code">💡 Code</option>
                                <option value="📈 Business">📈 Business</option>
                                <option value="🧠 Personal">🧠 Personal</option>
                            </select>

                            <button type="submit" 
                                    class="bg-amber-500 hover:bg-amber-400 text-black font-semibold text-xs px-4 py-2 rounded-full transition shadow-lg shadow-amber-500/20 flex items-center gap-1.5">
                                <span>Celebrate Win</span> 🎉
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <!-- WINS TIMELINE FEED -->
        <section class="space-y-4">
            <?php if (empty($wins)): ?>
                <div class="text-center py-12 bg-zinc-900/40 border border-zinc-800/50 rounded-2xl">
                    <p class="text-zinc-500 text-sm">No wins recorded yet. Post your first victory above!</p>
                </div>
            <?php endif; ?>

            <?php foreach ($wins as $win): ?>
            <article class="bg-zinc-900/60 border border-zinc-800/80 hover:border-zinc-700/80 transition rounded-2xl p-4 group">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xs px-2.5 py-0.5 rounded-full bg-zinc-800 border border-zinc-700/50 text-zinc-300 font-medium">
                            <?= htmlspecialchars($win['category']) ?>
                        </span>
                        <span class="text-xs text-zinc-500">
                            <?= date('M j, Y • g:i a', strtotime($win['created_at'])) ?>
                        </span>
                    </div>

                    <!-- Inline Action Dropdown / Controls -->
                    <div class="flex items-center gap-2 opacity-60 group-hover:opacity-100 transition">
                        <button onclick="toggleEdit(<?= $win['id'] ?>)" class="text-xs text-zinc-400 hover:text-white">✏️ Edit</button>
                        <form method="POST" class="inline" onsubmit="return confirm('Delete this win?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $win['id'] ?>">
                            <button class="text-xs text-zinc-500 hover:text-red-400">✕</button>
                        </form>
                    </div>
                </div>

                <!-- Display Text -->
                <p id="content-<?= $win['id'] ?>" class="text-sm text-zinc-200 leading-relaxed whitespace-pre-wrap my-2">
                    <?= htmlspecialchars($win['content']) ?>
                </p>

                <!-- Hidden Inline Edit Form -->
                <form id="edit-form-<?= $win['id'] ?>" method="POST" class="hidden my-2 space-y-2">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= $win['id'] ?>">
                    <textarea name="content" rows="2" class="w-full bg-zinc-950 border border-zinc-700 rounded-lg p-2 text-xs text-white focus:outline-none"><?= htmlspecialchars($win['content']) ?></textarea>
                    <div class="flex justify-end gap-2">
                        <button type="button" onclick="toggleEdit(<?= $win['id'] ?>)" class="text-xs text-zinc-400 px-2 py-1">Cancel</button>
                        <button type="submit" class="bg-zinc-800 text-xs text-white px-3 py-1 rounded-md">Save</button>
                    </div>
                </form>

                <!-- Footer Reaction Row -->
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

    <!-- Bottom Utility Footer -->
    <footer class="border-t border-zinc-800/80 bg-zinc-950/60 py-4 px-4 text-xs text-zinc-500 mt-8">
        <div class="max-w-2xl mx-auto flex flex-wrap justify-between items-center gap-2">
            <div>WinsFeed • Native PHP + SQLite Engine</div>
            <div class="flex gap-3">
                <span>Export:</span>
                <a href="?export=json" class="hover:text-amber-400 underline">JSON</a>
                <a href="?export=md" class="hover:text-amber-400 underline">Markdown</a>
                <a href="?export=txt" class="hover:text-amber-400 underline">Text</a>
            </div>
        </div>
    </footer>

    <script>
        // Pattern switcher stored in LocalStorage
        function setPattern(patternClass) {
            const body = document.getElementById('app-body');
            body.className = `${patternClass} min-h-screen flex flex-col justify-between`;
            localStorage.setItem('wins_pattern', patternClass);
        }

        // Restore chosen pattern on page load
        const savedPattern = localStorage.getItem('wins_pattern') || 'bg-pattern-grid';
        setPattern(savedPattern);

        // Inline Editor Toggle
        function toggleEdit(id) {
            document.getElementById(`content-${id}`).classList.toggle('hidden');
            document.getElementById(`edit-form-${id}`).classList.toggle('hidden');
        }

        // Major Confetti Celebration on New Win Submission
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('celebrate')) {
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 },
                colors: ['#f59e0b', '#ef4444', '#10b981', '#6366f1']
            });
            // Clean up query param from URL without page reload
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        // Micro Confetti Burst on "Like/Heart"
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