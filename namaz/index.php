<?php
// --- DATABASE INITIALIZATION ---
$db_file = __DIR__ . '/namaz.sqlite';
$pdo = new PDO("sqlite:" . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE IF NOT EXISTS prayer_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    log_date TEXT NOT NULL,
    prayer_name TEXT NOT NULL,
    faraz INTEGER DEFAULT 0,
    sunnah INTEGER DEFAULT 0,
    nafl INTEGER DEFAULT 0,
    dua INTEGER DEFAULT 0,
    note TEXT,
    UNIQUE(log_date, prayer_name)
)");

// --- HANDLE POST REQUESTS ---
$just_celebrated = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['log_date'] ?? date('Y-m-d');
    $prayer = $_POST['prayer_name'] ?? '';
    
    if ($prayer) {
        $faraz = isset($_POST['faraz']) ? 1 : 0;
        $sunnah = isset($_POST['sunnah']) ? 1 : 0;
        $nafl = isset($_POST['nafl']) ? 1 : 0;
        $dua = isset($_POST['dua']) ? 1 : 0;
        $note = trim($_POST['note'] ?? '');

        $stmt = $pdo->prepare("INSERT INTO prayer_logs (log_date, prayer_name, faraz, sunnah, nafl, dua, note)
            VALUES (:d, :p, :f, :s, :n, :dua, :note)
            ON CONFLICT(log_date, prayer_name) DO UPDATE SET
            faraz=:f, sunnah=:s, nafl=:n, dua=:dua, note=:note");
        
        $stmt->execute([
            ':d' => $date, ':p' => $prayer,
            ':f' => $faraz, ':s' => $sunnah,
            ':n' => $nafl, ':dua' => $dua, ':note' => $note
        ]);

        // Trigger celebrate if all 5 Faraz completed
        $st = $pdo->prepare("SELECT COUNT(*) FROM prayer_logs WHERE log_date = ? AND faraz = 1");
        $st->execute([$date]);
        if ($st->fetchColumn() >= 5) {
            $just_celebrated = true;
        }
    }
    if (!$just_celebrated) {
        header("Location: index.php?date=" . urlencode($date));
        exit;
    }
}

// --- DATA QUERIES ---
$selected_date = $_GET['date'] ?? date('Y-m-d');
$prayers = [
    'Fajr' => '🌅',
    'Dhuhr' => '☀️',
    'Asr' => '🌤️',
    'Maghrib' => '🌆',
    'Isha' => '🌙'
];

$stmt = $pdo->prepare("SELECT * FROM prayer_logs WHERE log_date = ?");
$stmt->execute([$selected_date]);
$daily_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$daily_logs = [];
foreach ($daily_raw as $row) {
    $daily_logs[$row['prayer_name']] = $row;
}

// Streak Calculation
$streak = 0;
$check_date = new DateTime();
while (true) {
    $d_str = $check_date->format('Y-m-d');
    $st = $pdo->prepare("SELECT COUNT(*) FROM prayer_logs WHERE log_date = ? AND faraz = 1");
    $st->execute([$d_str]);
    $cnt = $st->fetchColumn();
    if ($cnt >= 5) {
        $streak++;
        $check_date->modify('-1 day');
    } else {
        if ($d_str === date('Y-m-d')) {
            $check_date->modify('-1 day');
            continue;
        }
        break;
    }
}

// Monthly report metrics
$month_start = date('Y-m-01', strtotime($selected_date));
$month_end = date('Y-m-t', strtotime($selected_date));
$rep_stmt = $pdo->prepare("SELECT 
    SUM(faraz) as total_faraz, 
    SUM(sunnah) as total_sunnah, 
    SUM(nafl) as total_nafl, 
    SUM(dua) as total_dua 
    FROM prayer_logs WHERE log_date BETWEEN ? AND ?");
$rep_stmt->execute([$month_start, $month_end]);
$monthly_stats = $rep_stmt->fetch(PDO::FETCH_ASSOC);

// Calendar heatmap data
$cal_stmt = $pdo->prepare("SELECT log_date, SUM(faraz) as total FROM prayer_logs WHERE log_date BETWEEN ? AND ? GROUP BY log_date");
$cal_stmt->execute([$month_start, $month_end]);
$cal_raw = $cal_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Namaz Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script>
        tailwindcss = {
            darkMode: 'class'
        }
    </script>
    <style>
        /* Custom Checkbox Styling for clean touch pills */
        .check-pill input:checked + div {
            background-color: #10B981;
            color: #FFFFFF;
            border-color: #059669;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 dark:bg-slate-900 dark:text-slate-100 transition-colors duration-200 min-h-screen font-sans pb-12">
    <div class="max-w-md mx-auto px-4 pt-6">
        
        <!-- Header & Theme Controls -->
        <header class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-emerald-400">Namaz Tracker</h1>
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-400">Daily Spiritual Journal</p>
            </div>
            <div class="flex items-center space-x-2">
                <!-- Toggle Theme Button -->
                <button onclick="toggleTheme()" type="button" class="p-2.5 rounded-xl bg-slate-800 border border-slate-700 text-slate-200 hover:bg-slate-700 transition-all flex items-center justify-center">
                    <span id="theme-icon" class="text-base">🌙</span>
                </button>

                <!-- Streak Counter -->
                <div class="bg-slate-800 border border-slate-700 rounded-xl px-3 py-1.5 text-right shadow-sm flex items-center space-x-2">
                    <span class="text-lg">🔥</span>
                    <div>
                        <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 block -mb-1">Streak</span>
                        <span class="text-base font-black text-emerald-400"><?= $streak ?> Days</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Date Selector -->
        <div class="bg-slate-800 rounded-xl p-3 mb-6 border border-slate-700 flex items-center justify-between shadow-sm">
            <a href="?date=<?= date('Y-m-d', strtotime($selected_date . ' -1 day')) ?>" class="p-2 hover:bg-slate-700 rounded-lg text-slate-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
            </a>
            <div class="flex items-center space-x-2">
                <span class="text-sm">📅</span>
                <input type="date" value="<?= $selected_date ?>" onchange="location.href='?date='+this.value" class="bg-transparent font-bold text-slate-100 outline-none cursor-pointer text-sm">
            </div>
            <a href="?date=<?= date('Y-m-d', strtotime($selected_date . ' +1 day')) ?>" class="p-2 hover:bg-slate-700 rounded-lg text-slate-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
            </a>
        </div>

        <!-- Daily Prayer Form Cards -->
        <div class="space-y-4 mb-8">
            <?php foreach ($prayers as $p => $icon): 
                $log = $daily_logs[$p] ?? ['faraz'=>0, 'sunnah'=>0, 'nafl'=>0, 'dua'=>0, 'note'=>''];
            ?>
            <form method="POST" class="bg-slate-800 rounded-2xl p-4 border border-slate-700/80 shadow-md">
                <input type="hidden" name="log_date" value="<?= $selected_date ?>">
                <input type="hidden" name="prayer_name" value="<?= $p ?>">
                
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center space-x-2">
                        <span class="text-2xl"><?= $icon ?></span>
                        <span class="font-bold text-lg text-slate-100"><?= $p ?></span>
                    </div>
                    <button type="submit" class="text-xs bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-4 py-2 rounded-xl transition-colors flex items-center space-x-1 shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        <span>Save</span>
                    </button>
                </div>

                <div class="grid grid-cols-4 gap-2 mb-3 text-xs">
                    <?php 
                    $types = [
                        'faraz' => 'Faraz', 
                        'sunnah' => 'Sunnah', 
                        'nafl' => 'Nafl', 
                        'dua' => 'Du\'a'
                    ];
                    foreach ($types as $key => $label): 
                        $checked = $log[$key] ? 'checked' : '';
                    ?>
                    <label class="check-pill cursor-pointer select-none">
                        <input type="checkbox" name="<?= $key ?>" <?= $checked ?> class="hidden">
                        <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-700 text-center font-bold text-slate-300 transition-all active:scale-95">
                            <?= $label ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="relative">
                    <input type="text" name="note" value="<?= htmlspecialchars($log['note']) ?>" placeholder="Add note or reflection..." class="w-full bg-slate-900 text-xs text-slate-200 border border-slate-700 rounded-xl pl-8 pr-3 py-2.5 outline-none focus:border-emerald-500">
                    <span class="absolute left-2.5 top-2.5 text-xs text-slate-500">✏️</span>
                </div>
            </form>
            <?php endforeach; ?>
        </div>

        <!-- Monthly Report -->
        <section class="bg-slate-800 rounded-2xl p-4 border border-slate-700 mb-6 shadow-md">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3 border-b border-slate-700 pb-2">Monthly Totals (<?= date('F Y', strtotime($selected_date)) ?>)</h2>
            <div class="grid grid-cols-4 gap-2 text-center">
                <div class="p-2.5 bg-slate-900 rounded-xl border border-slate-700">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Faraz</span>
                    <span class="text-base font-black text-emerald-400"><?= $monthly_stats['total_faraz'] ?? 0 ?></span>
                </div>
                <div class="p-2.5 bg-slate-900 rounded-xl border border-slate-700">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Sunnah</span>
                    <span class="text-base font-black text-teal-400"><?= $monthly_stats['total_sunnah'] ?? 0 ?></span>
                </div>
                <div class="p-2.5 bg-slate-900 rounded-xl border border-slate-700">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Nafl</span>
                    <span class="text-base font-black text-indigo-400"><?= $monthly_stats['total_nafl'] ?? 0 ?></span>
                </div>
                <div class="p-2.5 bg-slate-900 rounded-xl border border-slate-700">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Du'a</span>
                    <span class="text-base font-black text-sky-400"><?= $monthly_stats['total_dua'] ?? 0 ?></span>
                </div>
            </div>
        </section>

        <!-- Consistency Grid -->
        <section class="bg-slate-800 rounded-2xl p-4 border border-slate-700 shadow-md">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3 border-b border-slate-700 pb-2">Consistency Grid</h2>
            <div class="grid grid-cols-7 gap-1.5 text-center text-xs">
                <?php
                $days_in_month = date('t', strtotime($month_start));
                for ($d = 1; $d <= $days_in_month; $d++):
                    $cur_d = date('Y-m-', strtotime($month_start)) . sprintf('%02d', $d);
                    $c_faraz = $cal_raw[$cur_d] ?? 0;
                    
                    $bg = 'bg-slate-900 text-slate-500';
                    if ($c_faraz >= 5) $bg = 'bg-emerald-500 text-slate-950 font-black shadow-sm';
                    elseif ($c_faraz >= 3) $bg = 'bg-emerald-700 text-emerald-100 font-bold';
                    elseif ($c_faraz >= 1) $bg = 'bg-emerald-950 text-emerald-300';
                ?>
                <a href="?date=<?= $cur_d ?>" class="<?= $bg ?> py-2 rounded-lg transition-all active:scale-95 hover:ring-2 hover:ring-emerald-400">
                    <?= $d ?>
                </a>
                <?php endfor; ?>
            </div>
        </section>

    </div>

    <!-- Celebration Logic -->
    <script>
        function applyTheme(isDark) {
            const body = document.body;
            const themeIcon = document.getElementById('theme-icon');
            if (isDark) {
                document.documentElement.classList.add('dark');
                body.className = "bg-slate-900 text-slate-100 transition-colors duration-200 min-h-screen font-sans pb-12";
                themeIcon.textContent = '☀️';
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                body.className = "bg-slate-100 text-slate-900 transition-colors duration-200 min-h-screen font-sans pb-12";
                themeIcon.textContent = '🌙';
                localStorage.setItem('theme', 'light');
            }
        }

        function toggleTheme() {
            const isDark = document.documentElement.classList.contains('dark');
            applyTheme(!isDark);
        }

        // Default Theme Initialization
        const savedTheme = localStorage.getItem('theme') || 'dark';
        applyTheme(savedTheme === 'dark');

        <?php if ($just_celebrated): ?>
        confetti({
            particleCount: 120,
            spread: 80,
            origin: { y: 0.6 }
        });
        <?php endif; ?>
    </script>
</body>
</html>