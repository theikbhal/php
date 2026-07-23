<?php
/**
 * Single-File PHP Micro-App: Omnicard & Productivity Suite
 * Stack: PHP 8+, SQLite3, Tailwind CSS (CDN), Alpine.js (CDN), FontAwesome (CDN)
 */

declare(strict_types=1);

// Configuration & DB Path
define('DB_FILE', __DIR__ . '/omnicard.sqlite');

// -----------------------------------------------------------------------------
// Database Engine
// -----------------------------------------------------------------------------
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
    }
    return $pdo;
}

function initDB(): void {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            color TEXT DEFAULT '#3b82f6',
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            top_word TEXT NOT NULL,
            short_desc TEXT,
            notes TEXT,
            enable_checkbox INTEGER DEFAULT 0,
            is_checked INTEGER DEFAULT 0,
            enable_counter INTEGER DEFAULT 0,
            counter_val INTEGER DEFAULT 0,
            enable_textbox INTEGER DEFAULT 0,
            custom_text TEXT,
            enable_list INTEGER DEFAULT 0,
            list_items TEXT, -- JSON Array
            enable_date INTEGER DEFAULT 0,
            date_val DATE,
            enable_datetime INTEGER DEFAULT 0,
            datetime_val DATETIME,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS card_groups (
            card_id INTEGER NOT NULL,
            group_id INTEGER NOT NULL,
            PRIMARY KEY (card_id, group_id),
            FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
        );
    ");

    // Seed default group if empty
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM groups");
    if ($stmt->fetch()['cnt'] == 0) {
        $db->exec("INSERT INTO groups (name, color) VALUES ('Default Group', '#6366f1')");
    }
}

initDB();

// -----------------------------------------------------------------------------
// REST API Controller
// -----------------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['api'];

    try {
        $db = getDB();

        if ($action === 'cards' && $method === 'GET') {
            $search = $_GET['q'] ?? '';
            $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

            $sql = "SELECT c.*, GROUP_CONCAT(cg.group_id) as group_ids 
                    FROM cards c 
                    LEFT JOIN card_groups cg ON c.id = cg.card_id";
            $params = [];

            if ($groupId) {
                $sql .= " WHERE c.id IN (SELECT card_id FROM card_groups WHERE group_id = :gid)";
                $params[':gid'] = $groupId;
            }

            if ($search !== '') {
                $sql .= ($groupId ? " AND" : " WHERE") . " (c.top_word LIKE :s OR c.short_desc LIKE :s OR c.notes LIKE :s)";
                $params[':s'] = "%$search%";
            }

            $sql .= " GROUP BY c.id ORDER BY c.sort_order ASC, c.id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $cards = $stmt->fetchAll();

            foreach ($cards as &$card) {
                $card['list_items'] = $card['list_items'] ? json_decode($card['list_items'], true) : [];
                $card['group_ids'] = $card['group_ids'] ? array_map('intval', explode(',', $card['group_ids'])) : [];
            }

            echo json_encode(['status' => 'success', 'data' => $cards]);
            exit;
        }

        if ($action === 'card_save' && $method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? null;

            $fields = [
                'top_word' => $input['top_word'] ?? 'New',
                'short_desc' => $input['short_desc'] ?? '',
                'notes' => $input['notes'] ?? '',
                'enable_checkbox' => $input['enable_checkbox'] ? 1 : 0,
                'is_checked' => $input['is_checked'] ? 1 : 0,
                'enable_counter' => $input['enable_counter'] ? 1 : 0,
                'counter_val' => (int)($input['counter_val'] ?? 0),
                'enable_textbox' => $input['enable_textbox'] ? 1 : 0,
                'custom_text' => $input['custom_text'] ?? '',
                'enable_list' => $input['enable_list'] ? 1 : 0,
                'list_items' => json_encode($input['list_items'] ?? []),
                'enable_date' => $input['enable_date'] ? 1 : 0,
                'date_val' => $input['date_val'] ?? null,
                'enable_datetime' => $input['enable_datetime'] ? 1 : 0,
                'datetime_val' => $input['datetime_val'] ?? null,
            ];

            if ($id) {
                $sql = "UPDATE cards SET top_word=:top_word, short_desc=:short_desc, notes=:notes, 
                        enable_checkbox=:enable_checkbox, is_checked=:is_checked, enable_counter=:enable_counter, 
                        counter_val=:counter_val, enable_textbox=:enable_textbox, custom_text=:custom_text, 
                        enable_list=:enable_list, list_items=:list_items, enable_date=:enable_date, 
                        date_val=:date_val, enable_datetime=:enable_datetime, datetime_val=:datetime_val WHERE id=:id";
                $fields['id'] = $id;
            } else {
                $sql = "INSERT INTO cards (top_word, short_desc, notes, enable_checkbox, is_checked, enable_counter, counter_val, enable_textbox, custom_text, enable_list, list_items, enable_date, date_val, enable_datetime, datetime_val) 
                        VALUES (:top_word, :short_desc, :notes, :enable_checkbox, :is_checked, :enable_counter, :counter_val, :enable_textbox, :custom_text, :enable_list, :list_items, :enable_date, :date_val, :enable_datetime, :datetime_val)";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($fields);
            $cardId = $id ? (int)$id : (int)$db->lastInsertId();

            // Handle Groups
            if (isset($input['group_ids']) && is_array($input['group_ids'])) {
                $db->prepare("DELETE FROM card_groups WHERE card_id = ?")->execute([$cardId]);
                $gStmt = $db->prepare("INSERT INTO card_groups (card_id, group_id) VALUES (?, ?)");
                foreach ($input['group_ids'] as $gid) {
                    $gStmt->execute([$cardId, (int)$gid]);
                }
            }

            echo json_encode(['status' => 'success', 'id' => $cardId]);
            exit;
        }

        if ($action === 'card_delete' && $method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("DELETE FROM cards WHERE id = ?");
            $stmt->execute([(int)$input['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'groups' && $method === 'GET') {
            $stmt = $db->query("SELECT * FROM groups ORDER BY sort_order ASC, name ASC");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
            exit;
        }

        if ($action === 'group_save' && $method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!empty($input['id'])) {
                $stmt = $db->prepare("UPDATE groups SET name = ?, color = ? WHERE id = ?");
                $stmt->execute([$input['name'], $input['color'] ?? '#3b82f6', $input['id']]);
            } else {
                $stmt = $db->prepare("INSERT INTO groups (name, color) VALUES (?, ?)");
                $stmt->execute([$input['name'], $input['color'] ?? '#3b82f6']);
            }
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'group_delete' && $method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("DELETE FROM groups WHERE id = ?");
            $stmt->execute([(int)$input['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'export' && $method === 'GET') {
            $cards = $db->query("SELECT * FROM cards")->fetchAll();
            $groups = $db->query("SELECT * FROM groups")->fetchAll();
            $cardGroups = $db->query("SELECT * FROM card_groups")->fetchAll();

            header('Content-Disposition: attachment; filename="omnicard_backup_' . date('Y-m-d') . '.json"');
            echo json_encode(['cards' => $cards, 'groups' => $groups, 'card_groups' => $cardGroups], JSON_PRETTY_PRINT);
            exit;
        }

        if ($action === 'import' && $method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['cards']) || !isset($data['groups'])) {
                throw new Exception("Invalid JSON import structure");
            }

            $db->beginTransaction();
            $db->exec("DELETE FROM card_groups");
            $db->exec("DELETE FROM cards");
            $db->exec("DELETE FROM groups");

            foreach ($data['groups'] as $g) {
                $stmt = $db->prepare("INSERT INTO groups (id, name, color, sort_order) VALUES (?, ?, ?, ?)");
                $stmt->execute([$g['id'], $g['name'], $g['color'], $g['sort_order'] ?? 0]);
            }

            foreach ($data['cards'] as $c) {
                $stmt = $db->prepare("INSERT INTO cards (id, top_word, short_desc, notes, enable_checkbox, is_checked, enable_counter, counter_val, enable_textbox, custom_text, enable_list, list_items, enable_date, date_val, enable_datetime, datetime_val, sort_order) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $c['id'], $c['top_word'], $c['short_desc'], $c['notes'], $c['enable_checkbox'], $c['is_checked'], 
                    $c['enable_counter'], $c['counter_val'], $c['enable_textbox'], $c['custom_text'], 
                    $c['enable_list'], $c['list_items'], $c['enable_date'], $c['date_val'], 
                    $c['enable_datetime'], $c['datetime_val'], $c['sort_order'] ?? 0
                ]);
            }

            if (isset($data['card_groups'])) {
                foreach ($data['card_groups'] as $cg) {
                    $stmt = $db->prepare("INSERT INTO card_groups (card_id, group_id) VALUES (?, ?)");
                    $stmt->execute([$cg['card_id'], $cg['group_id']]);
                }
            }

            $db->commit();
            echo json_encode(['status' => 'success']);
            exit;
        }

    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OmniCard Suite - Micro App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .animated-bg {
            background: linear-gradient(135deg, #0f172a 25%, #1e1b4b 50%, #0f172a 75%);
            background-size: 400% 400%;
            animation: gradientMove 15s ease infinite;
        }
        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .card-focus-ring {
            ring: 3px solid #6366f1;
            box-shadow: 0 0 15px rgba(99, 102, 241, 0.5);
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="h-full animated-bg font-sans antialiased overflow-hidden" x-data="omniApp()" x-init="initApp()" @keydown.window="handleGlobalKeys($event)">

<div class="flex h-screen overflow-hidden">

    <!-- Sidebar Navigation -->
    <aside class="w-64 bg-slate-900/80 backdrop-blur-md border-r border-slate-800 flex flex-col justify-between hidden md:flex">
        <div class="p-4 space-y-6">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center font-bold text-white shadow-lg">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div>
                    <h1 class="font-bold text-lg tracking-tight leading-none text-white">OmniCard</h1>
                    <span class="text-xs text-indigo-400">1,000 Card Engine</span>
                </div>
            </div>

            <!-- View Navigation -->
            <nav class="space-y-1">
                <button @click="currentView = 'cards'" :class="currentView === 'cards' ? 'bg-indigo-600/20 text-indigo-400 border-r-2 border-indigo-500' : 'text-slate-400 hover:bg-slate-800/50'" class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium transition">
                    <i class="fa-solid fa-border-all w-5"></i>
                    <span>Cards Grid</span>
                </button>
                <button @click="currentView = 'groups'" :class="currentView === 'groups' ? 'bg-indigo-600/20 text-indigo-400 border-r-2 border-indigo-500' : 'text-slate-400 hover:bg-slate-800/50'" class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium transition">
                    <i class="fa-solid fa-folder-tree w-5"></i>
                    <span>Groups Manager</span>
                </button>
                <button @click="currentView = 'calendar'" :class="currentView === 'calendar' ? 'bg-indigo-600/20 text-indigo-400 border-r-2 border-indigo-500' : 'text-slate-400 hover:bg-slate-800/50'" class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-lg text-sm font-medium transition">
                    <i class="fa-solid fa-calendar-days w-5"></i>
                    <span>Calendar View</span>
                </button>
            </nav>

            <!-- Productivity Utilities Module -->
            <div class="bg-slate-950/60 rounded-xl p-3 border border-slate-800/80 space-y-3">
                <div class="flex items-center justify-between text-xs font-semibold text-slate-400">
                    <span><i class="fa-solid fa-stopwatch mr-1"></i> Timer Suite</span>
                    <span x-text="pomoMode ? 'Pomodoro' : 'Stopwatch'" class="text-indigo-400"></span>
                </div>
                
                <div class="text-center py-1">
                    <div class="text-2xl font-mono font-bold tracking-wider text-white" x-text="formatTime(timerSeconds)">00:00</div>
                </div>

                <div class="grid grid-cols-3 gap-1">
                    <button @click="toggleTimer()" class="bg-indigo-600 hover:bg-indigo-500 text-white rounded py-1 text-xs font-semibold">
                        <span x-text="timerRunning ? 'Pause' : 'Start'"></span>
                    </button>
                    <button @click="resetTimer()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 rounded py-1 text-xs">Reset</button>
                    <button @click="switchTimerMode()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 rounded py-1 text-xs"><i class="fa-solid fa-arrows-rotate"></i></button>
                </div>
                
                <div class="pt-1 text-[10px] text-slate-500 text-center">
                    Pomodoro: 15m Work / 3m Break
                </div>
            </div>
        </div>

        <div class="p-4 border-t border-slate-800 space-y-2">
            <button @click="openDocsModal = true" class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium text-slate-400 hover:bg-slate-800">
                <span><i class="fa-solid fa-code mr-2"></i> API & Help Docs</span>
                <i class="fa-solid fa-chevron-right text-[10px]"></i>
            </button>
            <button @click="triggerCelebration()" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white px-3 py-2 rounded-lg text-xs font-semibold shadow">
                🎉 Celebrate Milestone
            </button>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- Top Toolbar / Navbar -->
        <header class="h-16 bg-slate-900/60 backdrop-blur-md border-b border-slate-800 flex items-center justify-between px-4 z-10">
            <div class="flex items-center space-x-3 flex-1 max-w-xl">
                <!-- Mobile Navigation Menu Toggle -->
                <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden text-slate-400 hover:text-white p-2">
                    <i class="fa-solid fa-bars"></i>
                </button>
                
                <!-- Search Input Field -->
                <div class="relative w-full">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm"></i>
                    <input type="text" x-model="searchQuery" @input.debounce.300ms="fetchCards()" placeholder="Search cards... (Ctrl+K)" 
                           class="w-full bg-slate-950/80 border border-slate-800 rounded-lg pl-9 pr-16 py-1.5 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    <kbd class="hidden sm:inline-block absolute right-2.5 top-1/2 -translate-y-1/2 bg-slate-800 text-slate-400 px-1.5 py-0.5 text-[10px] rounded border border-slate-700">Ctrl K</kbd>
                </div>
            </div>

            <div class="flex items-center space-x-2 pl-4">
                <button @click="openCardModal()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg text-sm font-medium flex items-center space-x-1.5 shadow">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span class="hidden sm:inline">New Card</span>
                </button>
                <button @click="exportData()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 p-2 rounded-lg text-sm" title="Export Data">
                    <i class="fa-solid fa-download"></i>
                </button>
                <label class="bg-slate-800 hover:bg-slate-700 text-slate-300 p-2 rounded-lg text-sm cursor-pointer" title="Import Data">
                    <i class="fa-solid fa-upload"></i>
                    <input type="file" @change="importData($event)" class="hidden" accept=".json">
                </label>
            </div>
        </header>

        <!-- Dynamic Content Body -->
        <div class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-6">
            
            <!-- Cards View -->
            <template x-if="currentView === 'cards'">
                <div>
                    <!-- Group Filter Pills -->
                    <div class="flex items-center space-x-2 overflow-x-auto pb-3 mb-4 no-scrollbar">
                        <button @click="selectedGroupFilter = null; fetchCards()" 
                                :class="selectedGroupFilter === null ? 'bg-indigo-600 text-white' : 'bg-slate-900/80 text-slate-400 hover:bg-slate-800'"
                                class="px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap transition border border-slate-800">
                            All Cards
                        </button>
                        <template x-for="g in groups" :key="g.id">
                            <button @click="selectedGroupFilter = g.id; fetchCards()" 
                                    :class="selectedGroupFilter === g.id ? 'bg-indigo-600 text-white' : 'bg-slate-900/80 text-slate-400 hover:bg-slate-800'"
                                    class="px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap transition border border-slate-800 flex items-center space-x-1.5">
                                <span class="w-2 h-2 rounded-full" :style="'background-color:' + g.color"></span>
                                <span x-text="g.name"></span>
                            </button>
                        </template>
                    </div>

                    <!-- 1,000 Cards Micro Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-3">
                        <template x-for="(card, index) in cards" :key="card.id">
                            <div @click="focusedIndex = index; openCardModal(card)"
                                 :class="{'card-focus-ring': focusedIndex === index}"
                                 class="group relative bg-slate-900/80 border border-slate-800 hover:border-indigo-500 rounded-xl p-3 cursor-pointer transition-all duration-200 flex flex-col justify-between h-28 overflow-hidden shadow-sm hover:shadow-indigo-500/10">
                                
                                <!-- Top Word -->
                                <div class="font-bold text-sm text-indigo-400 truncate tracking-wide" x-text="card.top_word"></div>

                                <!-- Hover Peek Max 5 Words -->
                                <div class="text-[11px] text-slate-400 line-clamp-2 opacity-80 group-hover:opacity-100 transition" x-text="getHoverPeek(card.short_desc)"></div>

                                <!-- Icons / Badges Footer -->
                                <div class="flex items-center justify-between text-[10px] text-slate-500 border-t border-slate-800/60 pt-1 mt-1">
                                    <div class="flex space-x-1">
                                        <template x-if="card.enable_checkbox"><i class="fa-regular fa-square-check" :class="{'text-emerald-400': card.is_checked}"></i></template>
                                        <template x-if="card.enable_counter"><i class="fa-solid fa-hashtag text-indigo-400"></i></template>
                                        <template x-if="card.enable_list"><i class="fa-solid fa-list text-amber-400"></i></template>
                                        <template x-if="card.notes"><i class="fa-solid fa-sticky-note text-sky-400"></i></template>
                                    </div>
                                    <span class="text-[9px] text-slate-600" x-text="'#' + card.id"></span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Empty State -->
                    <template x-if="cards.length === 0">
                        <div class="text-center py-20 space-y-3">
                            <div class="w-12 h-12 rounded-full bg-slate-900 text-slate-600 flex items-center justify-center mx-auto text-xl">
                                <i class="fa-solid fa-box-open"></i>
                            </div>
                            <p class="text-slate-500 text-sm">No cards match your filter criteria.</p>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Groups Manager View -->
            <template x-if="currentView === 'groups'">
                <div class="max-w-4xl mx-auto space-y-6">
                    <div class="flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white">Groups Management</h2>
                        <button @click="openGroupModal()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg text-xs font-semibold">
                            <i class="fa-solid fa-plus mr-1"></i> New Group
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-for="g in groups" :key="g.id">
                            <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-4 flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-4 h-4 rounded-full" :style="'background-color:' + g.color"></div>
                                    <span class="font-medium text-slate-200" x-text="g.name"></span>
                                </div>
                                <div class="flex space-x-2">
                                    <button @click="openGroupModal(g)" class="text-slate-400 hover:text-white text-xs p-1"><i class="fa-solid fa-pen"></i></button>
                                    <button @click="deleteGroup(g.id)" class="text-red-400 hover:text-red-300 text-xs p-1"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Calendar View -->
            <template x-if="currentView === 'calendar'">
                <div class="space-y-4">
                    <h2 class="text-lg font-bold text-white">Date & Schedule Matrix</h2>
                    <div class="bg-slate-900/80 border border-slate-800 rounded-xl p-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <template x-for="card in cardsWithDates" :key="card.id">
                                <div class="bg-slate-950 border border-slate-800 rounded-lg p-3 space-y-2">
                                    <div class="flex justify-between items-start">
                                        <span class="font-bold text-indigo-400 text-sm" x-text="card.top_word"></span>
                                        <span class="text-[10px] bg-slate-800 px-2 py-0.5 rounded text-slate-400" x-text="card.date_val || card.datetime_val"></span>
                                    </div>
                                    <p class="text-xs text-slate-400" x-text="card.short_desc"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </main>
</div>

<!-- Card Modal (Edit / View) -->
<div x-show="cardModalOpen" x-cloak class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div @click.away="cardModalOpen = false" class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 space-y-6 shadow-2xl">
        <div class="flex justify-between items-center border-b border-slate-800 pb-4">
            <h3 class="text-lg font-bold text-white" x-text="activeCard.id ? 'Edit Card #' + activeCard.id : 'Create Card'"></h3>
            <button @click="cardModalOpen = false" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="space-y-4">
            <!-- Top Word & Short Desc -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Top Word (1 Word)</label>
                    <input type="text" x-model="activeCard.top_word" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Short Description (Hover Peek)</label>
                    <input type="text" x-model="activeCard.short_desc" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500">
                </div>
            </div>

            <!-- Notes Editable Area -->
            <div>
                <label class="block text-xs font-semibold text-slate-400 mb-1">Extended Notes</label>
                <textarea x-model="activeCard.notes" rows="3" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-indigo-500"></textarea>
            </div>

            <!-- Opt-In Dynamic Modules Config -->
            <div class="border-t border-slate-800 pt-4 space-y-3">
                <span class="text-xs font-bold text-indigo-400 tracking-wider uppercase">Optional Dynamic Controls</span>
                
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" x-model="activeCard.enable_checkbox" class="rounded border-slate-800 bg-slate-950 text-indigo-600">
                        <span>Checkbox</span>
                    </label>
                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" x-model="activeCard.enable_counter" class="rounded border-slate-800 bg-slate-950 text-indigo-600">
                        <span>Counter</span>
                    </label>
                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" x-model="activeCard.enable_textbox" class="rounded border-slate-800 bg-slate-950 text-indigo-600">
                        <span>Custom Text</span>
                    </label>
                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" x-model="activeCard.enable_list" class="rounded border-slate-800 bg-slate-950 text-indigo-600">
                        <span>List Array</span>
                    </label>
                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" x-model="activeCard.enable_date" class="rounded border-slate-800 bg-slate-950 text-indigo-600">
                        <span>Date</span>
                    </label>
                    <label class="flex items-center space-x-2 text-xs text-slate-300 cursor-pointer">
                        <input type="checkbox" x-model="activeCard.enable_datetime" class="rounded border-slate-800 bg-slate-950 text-indigo-600">
                        <span>Date-Time</span>
                    </label>
                </div>
            </div>

            <!-- Dynamic Fields Execution Area -->
            <div class="space-y-3 bg-slate-950 p-4 rounded-xl border border-slate-800/80">
                <template x-if="activeCard.enable_checkbox">
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" x-model="activeCard.is_checked" id="cardCheck" class="rounded border-slate-800 text-indigo-600">
                        <label for="cardCheck" class="text-xs text-slate-300">Mark Completed</label>
                    </div>
                </template>

                <template x-if="activeCard.enable_counter">
                    <div class="flex items-center space-x-3">
                        <span class="text-xs text-slate-400">Counter:</span>
                        <button @click="activeCard.counter_val--" class="bg-slate-800 px-2 py-0.5 rounded text-xs text-slate-200">-</button>
                        <span x-text="activeCard.counter_val" class="font-mono text-sm text-indigo-400"></span>
                        <button @click="activeCard.counter_val++" class="bg-slate-800 px-2 py-0.5 rounded text-xs text-slate-200">+</button>
                    </div>
                </template>

                <template x-if="activeCard.enable_date">
                    <div>
                        <label class="block text-[10px] text-slate-400 mb-1">Target Date</label>
                        <input type="date" x-model="activeCard.date_val" class="bg-slate-900 border border-slate-800 rounded px-2 py-1 text-xs text-white">
                    </div>
                </template>
            </div>

            <!-- Group Assignment -->
            <div>
                <label class="block text-xs font-semibold text-slate-400 mb-1">Assign Groups</label>
                <div class="flex flex-wrap gap-2">
                    <template x-for="g in groups" :key="g.id">
                        <button type="button" @click="toggleGroupAssignment(g.id)" 
                                :class="activeCard.group_ids && activeCard.group_ids.includes(g.id) ? 'bg-indigo-600 text-white' : 'bg-slate-950 text-slate-400 border border-slate-800'"
                                class="px-2.5 py-1 rounded-lg text-xs font-medium transition">
                            <span x-text="g.name"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex justify-between border-t border-slate-800 pt-4">
            <button x-show="activeCard.id" @click="deleteCard(activeCard.id)" class="bg-red-600/20 hover:bg-red-600/30 text-red-400 px-3 py-1.5 rounded-lg text-xs font-semibold">Delete Card</button>
            <div class="flex space-x-2">
                <button @click="cardModalOpen = false" class="bg-slate-800 text-slate-300 px-3 py-1.5 rounded-lg text-xs font-semibold">Cancel</button>
                <button @click="saveCard()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-1.5 rounded-lg text-xs font-semibold">Save Card</button>
            </div>
        </div>
    </div>
</div>

<!-- Docs Modal -->
<div x-show="openDocsModal" x-cloak class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div @click.away="openDocsModal = false" class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-xl p-6 space-y-4 shadow-2xl">
        <h3 class="text-lg font-bold text-white"><i class="fa-solid fa-book text-indigo-400 mr-2"></i> Quick Documentation & API Reference</h3>
        <div class="text-xs text-slate-300 space-y-2 max-h-80 overflow-y-auto font-mono bg-slate-950 p-4 rounded-xl border border-slate-800">
            <p class="text-indigo-400 font-bold">// Navigation Controls</p>
            <p>Use Keyboard: Arrow keys or W/A/S/D to move focus across cards.</p>
            <p>Ctrl + K: Focus global search field.</p>
            <p class="text-indigo-400 font-bold mt-2">// REST API Endpoints</p>
            <p>GET  /index.php?api=cards        - Fetch all cards</p>
            <p>POST /index.php?api=card_save    - Create/Update card</p>
            <p>POST /index.php?api=card_delete  - Delete card</p>
            <p>GET  /index.php?api=groups       - Fetch groups</p>
            <p>GET  /index.php?api=export       - Export database backup</p>
        </div>
        <div class="text-right">
            <button @click="openDocsModal = false" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-xs font-semibold">Close Docs</button>
        </div>
    </div>
</div>

<script>
function omniApp() {
    return {
        currentView: 'cards',
        cards: [],
        groups: [],
        searchQuery: '',
        selectedGroupFilter: null,
        focusedIndex: 0,
        cardModalOpen: false,
        openDocsModal: false,
        mobileMenuOpen: false,
        activeCard: {},
        
        // Timer Engine State
        timerSeconds: 900, // 15 min default
        timerRunning: false,
        pomoMode: true,
        timerInterval: null,

        async initApp() {
            await this.fetchGroups();
            await this.fetchCards();
        },

        async fetchCards() {
            let url = 'index.php?api=cards&q=' + encodeURIComponent(this.searchQuery);
            if (this.selectedGroupFilter) url += '&group_id=' + this.selectedGroupFilter;
            const res = await fetch(url);
            const json = await res.json();
            this.cards = json.data || [];
        },

        async fetchGroups() {
            const res = await fetch('index.php?api=groups');
            const json = await res.json();
            this.groups = json.data || [];
        },

        get hoverPeekLimit() { return 5; },

        getHoverPeek(text) {
            if (!text) return '';
            return text.split(' ').slice(0, 5).join(' ');
        },

        get cardsWithDates() {
            return this.cards.filter(c => c.enable_date || c.enable_datetime);
        },

        openCardModal(card = null) {
            if (card) {
                this.activeCard = JSON.parse(JSON.stringify(card));
            } else {
                this.activeCard = { top_word: '', short_desc: '', notes: '', group_ids: [] };
            }
            this.cardModalOpen = true;
        },

        toggleGroupAssignment(groupId) {
            if (!this.activeCard.group_ids) this.activeCard.group_ids = [];
            const idx = this.activeCard.group_ids.indexOf(groupId);
            if (idx > -1) this.activeCard.group_ids.splice(idx, 1);
            else this.activeCard.group_ids.push(groupId);
        },

        async saveCard() {
            await fetch('index.php?api=card_save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(this.activeCard)
            });
            this.cardModalOpen = false;
            this.fetchCards();
        },

        async deleteCard(id) {
            if (!confirm('Delete this card?')) return;
            await fetch('index.php?api=card_delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            this.cardModalOpen = false;
            this.fetchCards();
        },

        handleGlobalKeys(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                document.querySelector('input[type="text"]').focus();
                return;
            }
            if (this.cardModalOpen) return;

            if (['ArrowRight', 'd', 'D'].includes(e.key)) {
                if (this.focusedIndex < this.cards.length - 1) this.focusedIndex++;
            } else if (['ArrowLeft', 'a', 'A'].includes(e.key)) {
                if (this.focusedIndex > 0) this.focusedIndex--;
            } else if (['Enter'].includes(e.key) && this.cards[this.focusedIndex]) {
                this.openCardModal(this.cards[this.focusedIndex]);
            }
        },

        // Productivity Timer
        toggleTimer() {
            if (this.timerRunning) {
                clearInterval(this.timerInterval);
                this.timerRunning = false;
            } else {
                this.timerRunning = true;
                this.timerInterval = setInterval(() => {
                    if (this.timerSeconds > 0) this.timerSeconds--;
                    else this.resetTimer();
                }, 1000);
            }
        },

        resetTimer() {
            clearInterval(this.timerInterval);
            this.timerRunning = false;
            this.timerSeconds = this.pomoMode ? 900 : 0;
        },

        switchTimerMode() {
            this.pomoMode = !this.pomoMode;
            this.resetTimer();
        },

        formatTime(sec) {
            const m = Math.floor(sec / 60).toString().padStart(2, '0');
            const s = (sec % 60).toString().padStart(2, '0');
            return `${m}:${s}`;
        },

        triggerCelebration() {
            alert('🎉 Milestone reached! 1,000 Card Single-File Suite functioning at high speed.');
        },

        exportData() {
            window.location.href = 'index.php?api=export';
        },

        async importData(e) {
            const file = e.target.files[0];
            if (!file) return;
            const text = await file.text();
            await fetch('index.php?api=import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: text
            });
            alert('Import successful!');
            this.fetchCards();
            this.fetchGroups();
        }
    };
}
</script>
</body>
</html>