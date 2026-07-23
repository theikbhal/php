<?php
// ==========================================
// SPRINT & POMODORO ENGINE - SINGLE FILE PHP
// ==========================================

$db_file = __DIR__ . '/sprint_pomo.sqlite';
$pdo = new PDO("sqlite:" . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize Database Schema
$pdo->exec("
CREATE TABLE IF NOT EXISTS sprints (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sprint_date TEXT NOT NULL,
    duration_minutes INTEGER DEFAULT 120,
    status TEXT DEFAULT 'planned', -- planned, active, completed
    notes TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sprint_id INTEGER NULL,
    title TEXT NOT NULL,
    description TEXT DEFAULT '',
    priority TEXT DEFAULT 'medium', -- low, medium, high
    status TEXT DEFAULT 'backlog', -- backlog, estimated, in_sprint, completed
    estimated_pobos INTEGER DEFAULT 1,
    completed_pobos INTEGER DEFAULT 0,
    work_duration INTEGER DEFAULT 15,
    break_duration INTEGER DEFAULT 3,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sprint_id) REFERENCES sprints(id) ON DELETE SET NULL
);
");

// Helper: Auto-generate Human-Readable Sprint Name
function generateSprintName($pdo) {
    $adjectives = ['Agile', 'Swift', 'Focused', 'Calm', 'Mindful', 'Radiant', 'Sturdy', 'Vibrant', 'Serene', 'Brave'];
    $nouns = ['Panda', 'Falcon', 'Cedar', 'Breeze', 'Mango', 'Olive', 'Phoenix', 'Lotus', 'River', 'Summit'];
    
    $adj = $adjectives[array_rand($adjectives)];
    $noun = $nouns[array_rand($nouns)];
    
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM sprints");
    $nextId = ($stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0) + 1;
    
    return "{$adj} {$noun} #{$nextId}";
}

// API ROUTER
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    
    // FETCH INITIAL DATA
    if ($action === 'data') {
        $sprints = $pdo->query("SELECT * FROM sprints ORDER BY sprint_date ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tasks = $pdo->query("SELECT * FROM tasks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['sprints' => $sprints, 'tasks' => $tasks]);
        exit;
    }
    
    // CREATE SPRINT
    if ($action === 'create_sprint' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = !empty($input['name']) ? $input['name'] : generateSprintName($pdo);
        $date = $input['date'] ?? date('Y-m-d');
        $duration = (int)($input['duration'] ?? 120);
        
        $stmt = $pdo->prepare("INSERT INTO sprints (name, sprint_date, duration_minutes) VALUES (?, ?, ?)");
        $stmt->execute([$name, $date, $duration]);
        echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId(), 'name' => $name]);
        exit;
    }
    
    // MOVE SPRINT DATE
    if ($action === 'move_sprint' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE sprints SET sprint_date = ? WHERE id = ?");
        $stmt->execute([$input['date'], $input['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // UPDATE SPRINT NOTES / STATUS
    if ($action === 'update_sprint' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE sprints SET notes = ?, status = ? WHERE id = ?");
        $stmt->execute([$input['notes'] ?? '', $input['status'] ?? 'planned', $input['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // DELETE SPRINT
    if ($action === 'delete_sprint' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("DELETE FROM sprints WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // ADD TASK
    if ($action === 'create_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("INSERT INTO tasks (title, priority, status, estimated_pobos, work_duration, break_duration, sprint_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $status = !empty($input['sprint_id']) ? 'in_sprint' : ($input['estimated_pobos'] > 0 ? 'estimated' : 'backlog');
        $stmt->execute([
            $input['title'],
            $input['priority'] ?? 'medium',
            $status,
            (int)($input['estimated_pobos'] ?? 1),
            (int)($input['work_duration'] ?? 15),
            (int)($input['break_duration'] ?? 3),
            $input['sprint_id'] ?? null
        ]);
        echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // UPDATE TASK STATUS / PROGRESS
    if ($action === 'update_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_pobos = ?, estimated_pobos = ?, sprint_id = ?, priority = ? WHERE id = ?");
        $stmt->execute([
            $input['status'],
            (int)$input['completed_pobos'],
            (int)$input['estimated_pobos'],
            $input['sprint_id'] ?? null,
            $input['priority'] ?? 'medium',
            $input['id']
        ]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // DELETE TASK
    if ($action === 'delete_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // IMPORT DATA
    if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['sprints'])) {
            foreach ($input['sprints'] as $s) {
                $stmt = $pdo->prepare("INSERT INTO sprints (name, sprint_date, duration_minutes, status, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$s['name'], $s['sprint_date'], $s['duration_minutes'] ?? 120, $s['status'] ?? 'planned', $s['notes'] ?? '']);
            }
        }
        if (isset($input['tasks'])) {
            foreach ($input['tasks'] as $t) {
                $stmt = $pdo->prepare("INSERT INTO tasks (title, priority, status, estimated_pobos, completed_pobos, work_duration, break_duration) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$t['title'], $t['priority'] ?? 'medium', $t['status'] ?? 'backlog', $t['estimated_pobos'] ?? 1, $t['completed_pobos'] ?? 0, $t['work_duration'] ?? 15, $t['break_duration'] ?? 3]);
            }
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sprint & Pomodoro Studio</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#f0f9ff', 500: '#0284c7', 600: '#0369a1', 900: '#0c4a6e' }
                    }
                }
            }
        }
    </script>
    <!-- Lucide Icons & Canvas Confetti -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #374151; border-radius: 4px; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 font-sans min-h-screen flex flex-col">

    <!-- Top Navigation Header -->
    <header class="border-b border-gray-800 bg-gray-950 px-6 py-4 flex items-center justify-between sticky top-0 z-30">
        <div class="flex items-center space-x-3">
            <div class="p-2 bg-brand-600 rounded-lg text-white">
                <i data-lucide="zap" class="w-6 h-6"></i>
            </div>
            <div>
                <h1 class="font-bold text-lg leading-tight">SprintPulse Studio</h1>
                <p class="text-xs text-gray-400">Lean Daily Sprint & Pomodoro Workstation</p>
            </div>
        </div>

        <div class="flex items-center space-x-3">
            <button onclick="toggleDarkMode()" class="p-2 bg-gray-800 hover:bg-gray-700 rounded-lg text-gray-300">
                <i data-lucide="moon" class="w-5 h-5"></i>
            </button>
            <button onclick="openModal('onboardingModal')" class="p-2 bg-gray-800 hover:bg-gray-700 rounded-lg text-gray-300 flex items-center space-x-1 text-xs">
                <i data-lucide="help-circle" class="w-4 h-4"></i>
                <span class="hidden md:inline">Onboarding</span>
            </button>
            <button onclick="openModal('importExportModal')" class="px-3 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg text-xs font-medium text-gray-200 flex items-center space-x-1">
                <i data-lucide="database" class="w-4 h-4"></i>
                <span>Data Backup / Import</span>
            </button>
            <button onclick="openModal('newSprintModal')" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-xs font-semibold flex items-center space-x-2">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>New Sprint</span>
            </button>
        </div>
    </header>

    <!-- Main Workspace Layout -->
    <main class="flex-1 grid grid-cols-1 lg:grid-cols-12 gap-6 p-6">

        <!-- Column 1: Backlog & Task Management (3 Cols) -->
        <section class="lg:col-span-3 bg-gray-950 border border-gray-800 rounded-xl p-4 flex flex-col h-[calc(100vh-7rem)]">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-bold text-sm tracking-wide text-gray-300 uppercase flex items-center space-x-2">
                    <i data-lucide="list-todo" class="w-4 h-4 text-brand-500"></i>
                    <span>Backlog & Estimates</span>
                </h2>
                <button onclick="openModal('newTaskModal')" class="p-1.5 text-xs bg-gray-800 hover:bg-gray-700 rounded-md text-brand-400">
                    + Add Task
                </button>
            </div>

            <!-- Search & Filter -->
            <div class="mb-3">
                <input type="text" id="taskSearch" oninput="filterTasks()" placeholder="Search tasks..." class="w-full bg-gray-900 border border-gray-800 rounded-lg px-3 py-1.5 text-xs text-gray-200 focus:outline-none focus:border-brand-500">
            </div>

            <!-- Task Lists Tabbed Container -->
            <div class="flex-1 overflow-y-auto custom-scrollbar space-y-3 pr-1" id="backlogTaskList">
                <!-- Dynamically Inserted -->
            </div>
        </section>

        <!-- Column 2: Active Pomodoro Focus Station (5 Cols) -->
        <section class="lg:col-span-5 flex flex-col space-y-6">
            <!-- Active Timer Card -->
            <div class="bg-gray-950 border border-gray-800 rounded-xl p-6 flex flex-col items-center justify-center text-center relative overflow-hidden">
                <div class="absolute top-4 left-4 text-xs font-mono text-gray-400 flex items-center space-x-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                    <span id="activeSprintBadge">No Active Sprint Selected</span>
                </div>

                <!-- Timer Dial -->
                <div class="my-6">
                    <div id="pomoPhaseLabel" class="text-xs uppercase font-semibold text-brand-400 tracking-widest mb-2">Work Focus Phase</div>
                    <div id="pomoDisplay" class="text-6xl font-black font-mono tracking-tight text-white mb-2">15:00</div>
                    <div id="activeTaskTitle" class="text-sm font-medium text-gray-400 max-w-md truncate">Select a task to start session</div>
                </div>

                <!-- Controls -->
                <div class="flex items-center space-x-3 mb-6">
                    <button id="startTimerBtn" onclick="toggleTimer()" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-bold flex items-center space-x-2">
                        <i data-lucide="play" class="w-4 h-4"></i>
                        <span id="startBtnText">Start Focus</span>
                    </button>
                    <button onclick="resetTimer()" class="px-4 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-lg text-sm font-medium">
                        Reset
                    </button>
                    <button onclick="completeCurrentPomo()" class="px-4 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-sm font-medium flex items-center space-x-1">
                        <i data-lucide="check-circle" class="w-4 h-4"></i>
                        <span>Done Pobo</span>
                    </button>
                </div>

                <!-- Timing Preset Selector -->
                <div class="flex items-center space-x-2 border-t border-gray-800 pt-4 w-full justify-center text-xs text-gray-400">
                    <span>Preset:</span>
                    <button onclick="setPreset(15, 3)" class="px-2 py-1 bg-gray-900 border border-gray-800 rounded hover:border-brand-500">15m / 3m</button>
                    <button onclick="setPreset(25, 5)" class="px-2 py-1 bg-gray-900 border border-gray-800 rounded hover:border-brand-500">25m / 5m</button>
                    <button onclick="setPreset(50, 10)" class="px-2 py-1 bg-gray-900 border border-gray-800 rounded hover:border-brand-500">50m / 10m</button>
                    <button onclick="setPreset(140, 20)" class="px-2 py-1 bg-gray-900 border border-gray-800 rounded hover:border-brand-500">140m / 20m</button>
                </div>
            </div>

            <!-- Break Activity Recommendations Card -->
            <div class="bg-gray-950 border border-gray-800 rounded-xl p-5">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3 flex items-center space-x-2">
                    <i data-lucide="heart-pulse" class="w-4 h-4 text-emerald-400"></i>
                    <span>Mindful Break Rituals</span>
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                    <div class="p-2.5 bg-gray-900 border border-gray-800 rounded-lg text-center hover:border-emerald-500/50">
                        <p class="font-semibold text-gray-200">💧 Wudu & Wash</p>
                        <p class="text-[10px] text-gray-400">Refresh face and mind</p>
                    </div>
                    <div class="p-2.5 bg-gray-900 border border-gray-800 rounded-lg text-center hover:border-emerald-500/50">
                        <p class="font-semibold text-gray-200">🕌 Namaz & Zikir</p>
                        <p class="text-[10px] text-gray-400">Spiritual reset</p>
                    </div>
                    <div class="p-2.5 bg-gray-900 border border-gray-800 rounded-lg text-center hover:border-emerald-500/50">
                        <p class="font-semibold text-gray-200">👁️ Eye Rest</p>
                        <p class="text-[10px] text-gray-400">Palming & 20ft gaze</p>
                    </div>
                    <div class="p-2.5 bg-gray-900 border border-gray-800 rounded-lg text-center hover:border-emerald-500/50">
                        <p class="font-semibold text-gray-200">🚶 Short Walk</p>
                        <p class="text-[10px] text-gray-400">Get steps & hydrate</p>
                    </div>
                </div>
            </div>

            <!-- Quick Sprint Notes Container -->
            <div class="bg-gray-950 border border-gray-800 rounded-xl p-4 flex-1 flex flex-col">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center space-x-2">
                        <i data-lucide="file-text" class="w-4 h-4 text-brand-400"></i>
                        <span>Sprint Reflection & Notes</span>
                    </label>
                    <button onclick="saveSprintNotes()" class="text-xs text-brand-400 hover:underline">Save Notes</button>
                </div>
                <textarea id="sprintNotesArea" class="w-full flex-1 bg-gray-900 border border-gray-800 rounded-lg p-3 text-xs text-gray-200 focus:outline-none focus:border-brand-500 resize-none custom-scrollbar" placeholder="Capture blockers, ideas, or reflection for the current sprint..."></textarea>
            </div>
        </section>

        <!-- Column 3: Calendar & Sprint Schedule (4 Cols) -->
        <section class="lg:col-span-4 bg-gray-950 border border-gray-800 rounded-xl p-4 flex flex-col h-[calc(100vh-7rem)]">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-bold text-sm tracking-wide text-gray-300 uppercase flex items-center space-x-2">
                    <i data-lucide="calendar" class="w-4 h-4 text-brand-500"></i>
                    <span>Sprint Timeline & Shift</span>
                </h2>
                <span class="text-xs text-gray-400" id="currentDateDisplay"></span>
            </div>

            <!-- Sprint List / Calendar Cards -->
            <div class="flex-1 overflow-y-auto custom-scrollbar space-y-3 pr-1" id="sprintListContainer">
                <!-- Dynamically Inserted -->
            </div>
        </section>
    </main>

    <!-- MODAL: NEW TASK -->
    <div id="newTaskModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-xl max-w-md w-full p-6">
            <h3 class="text-lg font-bold text-white mb-4">Create New Task</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Task Title</label>
                    <input type="text" id="newTaskTitle" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs text-gray-100 focus:border-brand-500 focus:outline-none">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Priority</label>
                        <select id="newTaskPriority" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs text-gray-100">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Estimated Pobodoro</label>
                        <input type="number" id="newTaskEst" min="0" value="1" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs text-gray-100">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Assign to Sprint</label>
                    <select id="newTaskSprintId" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs text-gray-100">
                        <option value="">Unassigned (Backlog)</option>
                    </select>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button onclick="closeModal('newTaskModal')" class="px-4 py-2 bg-gray-800 text-xs text-gray-300 rounded-lg">Cancel</button>
                <button onclick="submitNewTask()" class="px-4 py-2 bg-brand-600 text-xs text-white font-bold rounded-lg">Save Task</button>
            </div>
        </div>
    </div>

    <!-- MODAL: NEW SPRINT -->
    <div id="newSprintModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-xl max-w-md w-full p-6">
            <h3 class="text-lg font-bold text-white mb-4">Plan New Sprint</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Sprint Name (Leave blank for auto 2-word generator)</label>
                    <input type="text" id="newSprintName" placeholder="Auto-generated e.g. Swift Falcon #12" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs text-gray-100 focus:border-brand-500 focus:outline-none">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Date</label>
                        <input type="date" id="newSprintDate" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs text-gray-100">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Duration (Minutes)</label>
                        <select id="newSprintDuration" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs text-gray-100">
                            <option value="30">30 Mins</option>
                            <option value="40">40 Mins</option>
                            <option value="60">1 Hour</option>
                            <option value="120" selected>2 Hours</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button onclick="closeModal('newSprintModal')" class="px-4 py-2 bg-gray-800 text-xs text-gray-300 rounded-lg">Cancel</button>
                <button onclick="submitNewSprint()" class="px-4 py-2 bg-brand-600 text-xs text-white font-bold rounded-lg">Plan Sprint</button>
            </div>
        </div>
    </div>

    <!-- MODAL: DATA IMPORT/EXPORT -->
    <div id="importExportModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-xl max-w-lg w-full p-6">
            <h3 class="text-lg font-bold text-white mb-2">Data Backup & Export</h3>
            <p class="text-xs text-gray-400 mb-4">Export or restore your Sprints and Pomodoro tasks using standardized JSON.</p>
            
            <div class="space-y-4">
                <div>
                    <button onclick="exportData()" class="w-full py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-lg flex items-center justify-center space-x-2">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        <span>Download JSON Backup</span>
                    </button>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Import JSON Data</label>
                    <textarea id="importJsonText" rows="5" class="w-full bg-gray-950 border border-gray-800 rounded-lg p-2.5 text-xs font-mono text-gray-200" placeholder="Paste JSON structure here..."></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button onclick="closeModal('importExportModal')" class="px-4 py-2 bg-gray-800 text-xs text-gray-300 rounded-lg">Close</button>
                <button onclick="submitImport()" class="px-4 py-2 bg-brand-600 text-xs text-white font-bold rounded-lg">Import Data</button>
            </div>
        </div>
    </div>

    <!-- MODAL: ONBOARDING & DOCUMENTATION -->
    <div id="onboardingModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
        <div class="bg-gray-900 border border-gray-800 rounded-xl max-w-xl w-full p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-gray-800 pb-3">
                <h3 class="text-base font-bold text-white flex items-center space-x-2">
                    <i data-lucide="sparkles" class="w-5 h-5 text-brand-400"></i>
                    <span>Welcome to SprintPulse Studio</span>
                </h3>
                <button onclick="closeModal('onboardingModal')" class="text-gray-400 hover:text-white">&times;</button>
            </div>
            <div class="text-xs text-gray-300 space-y-3 leading-relaxed">
                <p><strong>1. Backlog & Unestimated Tasks:</strong> Dump ideas into the backlog without pressure. Assign estimated Pomodoros (pobos) when ready.</p>
                <p><strong>2. Sprint Planning:</strong> Auto-name 2-hour sprints (e.g. <em>Swift Cedar #4</em>). Move/shift sprints smoothly between calendar dates.</p>
                <p><strong>3. Pomodoro Focus Engine:</strong> Default 15m work + 3m break cycles designed for deep lean execution. Includes break ritual guidance (Wudu, Zikir, Walk, Eye rest).</p>
                <p><strong>4. Local & Minimalist:</strong> Powered by SQLite. Runs locally on PHP ports 8021/8022.</p>
            </div>
            <div class="pt-2 flex justify-end">
                <button onclick="closeModal('onboardingModal')" class="px-4 py-2 bg-brand-600 text-xs text-white font-bold rounded-lg">Got it, let's build!</button>
            </div>
        </div>
    </div>

    <!-- Application JavaScript Engine -->
    <script>
        let state = { sprints: [], tasks: [], activeSprintId: null, activeTaskId: null };
        let timer = { interval: null, remaining: 15 * 60, workDuration: 15, breakDuration: 3, isWork: true, running: false };

        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            document.getElementById('currentDateDisplay').innerText = new Date().toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
            document.getElementById('newSprintDate').value = new Date().toISOString().split('T')[0];
            loadData();
        });

        function loadData() {
            fetch('index.php?api=data')
                .then(res => res.json())
                .then(data => {
                    state.sprints = data.sprints;
                    state.tasks = data.tasks;
                    renderSprints();
                    renderTasks();
                    populateSprintDropdowns();
                });
        }

        function renderSprints() {
            const container = document.getElementById('sprintListContainer');
            container.innerHTML = '';

            if (state.sprints.length === 0) {
                container.innerHTML = `<div class="text-center py-8 text-xs text-gray-500">No sprints planned yet. Create your first sprint!</div>`;
                return;
            }

            state.sprints.forEach(s => {
                const isSelected = state.activeSprintId === s.id;
                const card = document.createElement('div');
                card.className = `p-3.5 rounded-xl border text-xs transition cursor-pointer ${isSelected ? 'bg-brand-900/30 border-brand-500' : 'bg-gray-900 border-gray-800 hover:border-gray-700'}`;
                card.onclick = () => selectSprint(s.id);

                card.innerHTML = `
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="font-bold text-gray-200">${s.name}</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-gray-800 text-gray-400">${s.duration_minutes}m</span>
                    </div>
                    <div class="flex items-center justify-between text-[11px] text-gray-400">
                        <span>📅 ${s.sprint_date}</span>
                        <div class="flex items-center space-x-2">
                            <button onclick="event.stopPropagation(); moveSprintPrompt(${s.id}, '${s.sprint_date}')" class="hover:text-brand-400">Shift</button>
                            <button onclick="event.stopPropagation(); deleteSprint(${s.id})" class="hover:text-red-400">Delete</button>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        function renderTasks() {
            const container = document.getElementById('backlogTaskList');
            const search = document.getElementById('taskSearch').value.toLowerCase();
            container.innerHTML = '';

            const filtered = state.tasks.filter(t => t.title.toLowerCase().includes(search));

            if (filtered.length === 0) {
                container.innerHTML = `<div class="text-center py-8 text-xs text-gray-500">No tasks found.</div>`;
                return;
            }

            filtered.forEach(t => {
                const card = document.createElement('div');
                const isSelected = state.activeTaskId === t.id;
                card.className = `p-3 rounded-lg border text-xs flex flex-col space-y-2 ${isSelected ? 'bg-brand-900/40 border-brand-500' : 'bg-gray-900 border-gray-800'}`;
                
                card.innerHTML = `
                    <div class="flex items-start justify-between">
                        <span class="font-medium text-gray-200">${t.title}</span>
                        <span class="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded ${t.priority === 'high' ? 'bg-red-900/50 text-red-300' : 'bg-gray-800 text-gray-400'}">${t.priority}</span>
                    </div>
                    <div class="flex items-center justify-between text-[10px] text-gray-400 pt-1 border-t border-gray-800">
                        <span>Pobo: ${t.completed_pobos}/${t.estimated_pobos}</span>
                        <div class="flex items-center space-x-2">
                            <button onclick="selectTask(${t.id})" class="text-brand-400 hover:underline">Focus</button>
                            <button onclick="deleteTask(${t.id})" class="text-red-400 hover:underline">Delete</button>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        function selectSprint(id) {
            state.activeSprintId = id;
            const s = state.sprints.find(item => item.id === id);
            document.getElementById('activeSprintBadge').innerText = s ? `Active: ${s.name}` : 'No Active Sprint';
            document.getElementById('sprintNotesArea').value = s ? (s.notes || '') : '';
            renderSprints();
        }

        function selectTask(id) {
            state.activeTaskId = id;
            const t = state.tasks.find(item => item.id === id);
            if (t) {
                document.getElementById('activeTaskTitle').innerText = t.title;
                timer.workDuration = t.work_duration || 15;
                timer.breakDuration = t.break_duration || 3;
                resetTimer();
            }
            renderTasks();
        }

        function toggleTimer() {
            if (timer.running) {
                clearInterval(timer.interval);
                timer.running = false;
                document.getElementById('startBtnText').innerText = 'Resume Focus';
            } else {
                timer.running = true;
                document.getElementById('startBtnText').innerText = 'Pause';
                timer.interval = setInterval(() => {
                    if (timer.remaining > 0) {
                        timer.remaining--;
                        updateTimerDisplay();
                    } else {
                        clearInterval(timer.interval);
                        timer.running = false;
                        triggerCelebration();
                        if (timer.isWork) {
                            alert('Work Pomodoro completed! Time for a mindful break.');
                            timer.isWork = false;
                            timer.remaining = timer.breakDuration * 60;
                            document.getElementById('pomoPhaseLabel').innerText = 'Mindful Break Phase';
                        } else {
                            alert('Break completed! Ready for the next work session.');
                            timer.isWork = true;
                            timer.remaining = timer.workDuration * 60;
                            document.getElementById('pomoPhaseLabel').innerText = 'Work Focus Phase';
                        }
                        updateTimerDisplay();
                    }
                }, 1000);
            }
        }

        function resetTimer() {
            clearInterval(timer.interval);
            timer.running = false;
            timer.remaining = (timer.isWork ? timer.workDuration : timer.breakDuration) * 60;
            document.getElementById('startBtnText').innerText = 'Start Focus';
            updateTimerDisplay();
        }

        function setPreset(work, breakMins) {
            timer.workDuration = work;
            timer.breakDuration = breakMins;
            timer.isWork = true;
            resetTimer();
        }

        function updateTimerDisplay() {
            const m = Math.floor(timer.remaining / 60).toString().padStart(2, '0');
            const s = (timer.remaining % 60).toString().padStart(2, '0');
            document.getElementById('pomoDisplay').innerText = `${m}:${s}`;
        }

        function completeCurrentPomo() {
            if (!state.activeTaskId) return;
            const task = state.tasks.find(t => t.id === state.activeTaskId);
            if (task) {
                task.completed_pobos += 1;
                if (task.completed_pobos >= task.estimated_pobos) {
                    task.status = 'completed';
                }
                fetch('index.php?api=update_task', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(task)
                }).then(() => {
                    triggerCelebration();
                    loadData();
                });
            }
        }

        function triggerCelebration() {
            confetti({ particleCount: 80, spread: 70, origin: { y: 0.6 } });
        }

        function submitNewSprint() {
            const name = document.getElementById('newSprintName').value;
            const date = document.getElementById('newSprintDate').value;
            const duration = document.getElementById('newSprintDuration').value;

            fetch('index.php?api=create_sprint', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, date, duration })
            }).then(() => {
                closeModal('newSprintModal');
                loadData();
            });
        }

        function submitNewTask() {
            const title = document.getElementById('newTaskTitle').value;
            const priority = document.getElementById('newTaskPriority').value;
            const estimated_pobos = document.getElementById('newTaskEst').value;
            const sprint_id = document.getElementById('newTaskSprintId').value;

            if (!title) return;

            fetch('index.php?api=create_task', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title, priority, estimated_pobos, sprint_id })
            }).then(() => {
                closeModal('newTaskModal');
                loadData();
            });
        }

        function saveSprintNotes() {
            if (!state.activeSprintId) return;
            const notes = document.getElementById('sprintNotesArea').value;
            const sprint = state.sprints.find(s => s.id === state.activeSprintId);
            if (sprint) {
                sprint.notes = notes;
                fetch('index.php?api=update_sprint', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(sprint)
                }).then(() => alert('Sprint notes updated.'));
            }
        }

        function moveSprintPrompt(id, currentDate) {
            const newDate = prompt('Shift Sprint to Date (YYYY-MM-DD):', currentDate);
            if (newDate && newDate !== currentDate) {
                fetch('index.php?api=move_sprint', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, date: newDate })
                }).then(() => loadData());
            }
        }

        function deleteSprint(id) {
            if (confirm('Delete this sprint?')) {
                fetch('index.php?api=delete_sprint', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                }).then(() => loadData());
            }
        }

        function deleteTask(id) {
            if (confirm('Delete this task?')) {
                fetch('index.php?api=delete_task', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                }).then(() => loadData());
            }
        }

        function populateSprintDropdowns() {
            const select = document.getElementById('newTaskSprintId');
            select.innerHTML = '<option value="">Unassigned (Backlog)</option>';
            state.sprints.forEach(s => {
                select.innerHTML += `<option value="${s.id}">${s.name} (${s.sprint_date})</option>`;
            });
        }

        function exportData() {
            const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(state, null, 2));
            const anchor = document.createElement('a');
            anchor.setAttribute("href", dataStr);
            anchor.setAttribute("download", `sprint_backup_${new Date().toISOString().split('T')[0]}.json`);
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
        }

        function submitImport() {
            const jsonText = document.getElementById('importJsonText').value;
            try {
                const parsed = JSON.parse(jsonText);
                fetch('index.php?api=import', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(parsed)
                }).then(() => {
                    closeModal('importExportModal');
                    loadData();
                    alert('Import successful!');
                });
            } catch (e) {
                alert('Invalid JSON structure.');
            }
        }

        function filterTasks() { renderTasks(); }
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
        function toggleDarkMode() { document.documentElement.classList.toggle('dark'); }
    </script>
</body>
</html>