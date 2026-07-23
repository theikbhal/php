<?php
// Optional SQLite persistence layer
$db = null;
if (extension_loaded('sqlite3')) {
    try {
        $db = new SQLite3('zikr_progress_v2.db');
        $db->exec('CREATE TABLE IF NOT EXISTS progress (id INTEGER PRIMARY KEY, count INTEGER, elapsed_time INTEGER, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            header('Content-Type: application/json');
            if ($_POST['action'] === 'save' && isset($_POST['count'])) {
                $count = (int)$_POST['count'];
                $elapsed = (int)($_POST['elapsed_time'] ?? 0);
                $stmt = $db->prepare('INSERT INTO progress (id, count, elapsed_time) VALUES (1, :count, :elapsed) ON CONFLICT(id) DO UPDATE SET count = :count, elapsed_time = :elapsed, updated_at = CURRENT_TIMESTAMP');
                $stmt->bindValue(':count', $count, SQLITE3_INTEGER);
                $stmt->bindValue(':elapsed', $elapsed, SQLITE3_INTEGER);
                $stmt->execute();
                echo json_encode(['status' => 'success']);
                exit;
            }
            if ($_POST['action'] === 'load') {
                $res = $db->querySingle('SELECT count, elapsed_time FROM progress WHERE id = 1', true);
                echo json_encode([
                    'count' => $res ? (int)$res['count'] : 0,
                    'elapsed_time' => $res ? (int)$res['elapsed_time'] : 0
                ]);
                exit;
            }
        }
    } catch (Exception $e) {
        $db = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>1,000 Allah Zikr Counter v2</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
      background-color: #0b0f17;
      background-image: radial-gradient(#1e293b 1.5px, transparent 1.5px);
      background-size: 24px 24px;
    }
    .grid-container {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(44px, 1fr));
      gap: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar {
      width: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
      background: #334155;
      border-radius: 4px;
    }
    /* Dynamic Row Color Palettes */
    .row-hue-0  { background: linear-gradient(135deg, #059669, #10b981); }
    .row-hue-1  { background: linear-gradient(135deg, #0284c7, #38bdf8); }
    .row-hue-2  { background: linear-gradient(135deg, #7c3aed, #a855f7); }
    .row-hue-3  { background: linear-gradient(135deg, #db2777, #f43f5e); }
    .row-hue-4  { background: linear-gradient(135deg, #d97706, #fbbf24); }
    .row-hue-5  { background: linear-gradient(135deg, #0d9488, #2dd4bf); }
    .row-hue-6  { background: linear-gradient(135deg, #4f46e5, #818cf8); }
    .row-hue-7  { background: linear-gradient(135deg, #c026d3, #e879f9); }
    .row-hue-8  { background: linear-gradient(135deg, #ea580c, #fb923c); }
    .row-hue-9  { background: linear-gradient(135deg, #16a34a, #4ade80); }
  </style>
</head>
<body class="text-slate-200 min-h-screen flex flex-col justify-between font-sans select-none overflow-x-hidden" onclick="handleGlobalClick(event)">

  <!-- Toast Notification Container -->
  <div id="toast-container" class="fixed top-20 right-4 z-50 flex flex-col gap-2 max-w-sm pointer-events-none"></div>

  <!-- Header Dashboard -->
  <header class="sticky top-0 z-40 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 p-4 shadow-2xl">
    <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
      
      <div class="flex items-center gap-3">
        <div class="p-3 bg-emerald-500/10 border border-emerald-500/20 rounded-2xl">
          <i class="fa-solid fa-moon text-amber-300 text-2xl animate-pulse"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold text-slate-100 flex items-center gap-2">
            Allah Zikr <span class="text-xs bg-emerald-500/20 text-emerald-400 px-2.5 py-0.5 rounded-full font-semibold">v2.0</span>
          </h1>
          <p class="text-xs text-slate-400">Tap anywhere, or press <kbd class="px-1.5 py-0.5 bg-slate-800 border border-slate-700 rounded text-slate-300">Space</kbd> / <kbd class="px-1.5 py-0.5 bg-slate-800 border border-slate-700 rounded text-slate-300">Enter</kbd></p>
        </div>
      </div>

      <!-- Live Counters & Stopwatch -->
      <div class="flex flex-wrap items-center justify-center gap-4 bg-slate-950/70 border border-slate-800/80 px-5 py-2.5 rounded-2xl">
        <div class="text-center">
          <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block">Completed</span>
          <span id="current-count" class="text-xl font-black text-emerald-400">0</span>
        </div>
        <div class="h-7 w-px bg-slate-800"></div>
        <div class="text-center">
          <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block">Remaining</span>
          <span id="remaining-count" class="text-xl font-black text-amber-400">1000</span>
        </div>
        <div class="h-7 w-px bg-slate-800"></div>
        <div class="text-center">
          <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block">Stopwatch</span>
          <span id="stopwatch-display" class="text-xl font-mono font-bold text-cyan-400">00:00:00</span>
        </div>
        <div class="h-7 w-px bg-slate-800"></div>
        <div class="text-center">
          <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block">Position</span>
          <span id="location-tracker" class="text-xs font-bold text-slate-300">R1 · C0</span>
        </div>
      </div>

      <!-- Action Toolbar -->
      <div class="flex items-center gap-2">
        <button id="btn-demo" onclick="toggleDemoMode(event)" title="Automated Demo" class="p-2.5 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 rounded-xl transition flex items-center gap-2 text-sm">
          <i id="demo-icon" class="fa-solid fa-play"></i> <span class="hidden sm:inline">Demo</span>
        </button>
        <button id="btn-tour" onclick="startOnboarding(event)" title="Replay Tour" class="p-2.5 bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-xl transition text-sm">
          <i class="fa-solid fa-route"></i>
        </button>
        <button id="btn-help" onclick="toggleHelpModal(event)" title="Help Document" class="p-2.5 bg-sky-500/10 hover:bg-sky-500/20 text-sky-400 border border-sky-500/30 rounded-xl transition text-sm">
          <i class="fa-solid fa-circle-question"></i>
        </button>
        <button id="btn-reset" onclick="resetCounter(event)" title="Reset Counter" class="p-2.5 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded-xl transition text-sm">
          <i class="fa-solid fa-rotate-left"></i>
        </button>
      </div>

    </div>

    <!-- Progress Bar -->
    <div class="max-w-6xl mx-auto mt-3 bg-slate-800/80 h-2 rounded-full overflow-hidden">
      <div id="progress-bar" class="bg-gradient-to-r from-emerald-500 via-teal-400 to-amber-300 h-full w-0 transition-all duration-300"></div>
    </div>
  </header>

  <!-- Grid Main Container -->
  <main class="max-w-6xl mx-auto w-full p-4 my-2 flex-grow">
    <div id="grid-wrapper" class="grid-container bg-slate-900/40 p-4 border border-slate-800 rounded-2xl max-h-[68vh] overflow-y-auto custom-scrollbar">
      <!-- 1,000 Cells Dynamic Generation -->
    </div>
  </main>

  <!-- Footer -->
  <footer class="border-t border-slate-800/60 bg-slate-950/80 p-3 text-center text-xs text-slate-500">
    Subhanallah · Alhamdulillah · Allahu Akbar — Daily Zikr Tracker v2
  </footer>

  <!-- Help & Documentation Modal -->
  <div id="help-modal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4" onclick="toggleHelpModal(event)">
    <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl relative text-slate-300" onclick="event.stopPropagation()">
      <button onclick="toggleHelpModal(event)" class="absolute top-4 right-4 text-slate-400 hover:text-white">
        <i class="fa-solid fa-xmark text-lg"></i>
      </button>
      <h3 class="text-xl font-bold text-amber-300 mb-4 flex items-center gap-2">
        <i class="fa-solid fa-book-open"></i> How to Use Zikr Counter
      </h3>
      <ul class="space-y-3 text-sm border-t border-slate-800 pt-3">
        <li class="flex items-start gap-3">
          <i class="fa-solid fa-hand-pointer text-emerald-400 mt-1"></i>
          <span><strong>Tap Anywhere:</strong> Tap or click anywhere on the screen (or press <kbd class="px-1 bg-slate-800 border rounded">Space</kbd>/<kbd class="px-1 bg-slate-800 border rounded">Enter</kbd>) to count 1 Zikr.</span>
        </li>
        <li class="flex items-start gap-3">
          <i class="fa-solid fa-stopwatch text-cyan-400 mt-1"></i>
          <span><strong>Stopwatch Tracking:</strong> Automatically starts on your first tap and pauses if idle. Shows time toasts for completed rows and 100-cell milestones.</span>
        </li>
        <li class="flex items-start gap-3">
          <i class="fa-solid fa-robot text-indigo-400 mt-1"></i>
          <span><strong>Demo Mode:</strong> Click the Demo button to automate the counter at high speeds and calculate estimated completion duration.</span>
        </li>
        <li class="flex items-start gap-3">
          <i class="fa-solid fa-palette text-amber-400 mt-1"></i>
          <span><strong>Dynamic Color Shift:</strong> Completed cells smoothly transition colors row-by-row as you progress.</span>
        </li>
      </ul>
      <button onclick="toggleHelpModal(event)" class="mt-6 w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-xl transition">
        Got it!
      </button>
    </div>
  </div>

  <!-- Onboarding Modal Layer -->
  <div id="onboarding-overlay" class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-md hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 text-center shadow-2xl relative">
      <div id="tour-step-icon" class="w-16 h-16 mx-auto mb-4 bg-amber-500/10 border border-amber-500/30 rounded-2xl flex items-center justify-center text-amber-300 text-2xl">
        <i class="fa-solid fa-moon"></i>
      </div>
      <h3 id="tour-step-title" class="text-xl font-bold text-slate-100 mb-2">Welcome to Zikr v2</h3>
      <p id="tour-step-desc" class="text-sm text-slate-400 mb-6">Track your daily 1,000 Allah Zikr with gamified moon navigation, live timing, and celebratory milestones.</p>
      <div class="flex items-center justify-between">
        <span id="tour-step-counter" class="text-xs text-slate-500 font-semibold">Step 1 of 3</span>
        <button id="tour-next-btn" onclick="nextTourStep()" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-xl transition">
          Next
        </button>
      </div>
    </div>
  </div>

  <script>
    const TOTAL_CELLS = 1000;
    let count = 0;
    let secondsElapsed = 0;
    let timerInterval = null;
    let timerStarted = false;
    let lastRowTime = 0;
    let lastCentumTime = 0;

    // Automated Demo State
    let isDemoRunning = false;
    let demoInterval = null;

    // DOM Elements
    const gridWrapper = document.getElementById('grid-wrapper');
    const currentCountEl = document.getElementById('current-count');
    const remainingCountEl = document.getElementById('remaining-count');
    const locationTrackerEl = document.getElementById('location-tracker');
    const stopwatchDisplay = document.getElementById('stopwatch-display');
    const progressBar = document.getElementById('progress-bar');
    const toastContainer = document.getElementById('toast-container');

    // Audio synth
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    function playTapSound(freq = 440) {
      if (audioCtx.state === 'suspended') audioCtx.resume();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
      gain.gain.setValueAtTime(0.04, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.08);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.08);
    }

    // Format Seconds to HH:MM:SS
    function formatTime(totalSecs) {
      const h = Math.floor(totalSecs / 3600).toString().padStart(2, '0');
      const m = Math.floor((totalSecs % 3600) / 60).toString().padStart(2, '0');
      const s = (totalSecs % 60).toString().padStart(2, '0');
      return `${h}:${m}:${s}`;
    }

    function startTimer() {
      if (!timerStarted && count < TOTAL_CELLS) {
        timerStarted = true;
        timerInterval = setInterval(() => {
          secondsElapsed++;
          stopwatchDisplay.textContent = formatTime(secondsElapsed);
        }, 1000);
      }
    }

    function stopTimer() {
      clearInterval(timerInterval);
      timerStarted = false;
    }

    // Build 1,000 Grid Nodes
    function initGrid() {
      gridWrapper.innerHTML = '';
      for (let i = 1; i <= TOTAL_CELLS; i++) {
        const cell = document.createElement('div');
        cell.id = `cell-${i}`;
        cell.className = 'relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border border-slate-800/80 bg-slate-900/80 text-slate-500 transition-all duration-200';
        cell.innerHTML = `<span class="text-[10px] font-mono opacity-60">${i}</span>`;
        gridWrapper.appendChild(cell);
      }
    }

    function getGridCols() {
      return Math.floor(gridWrapper.clientWidth / 50) || 10;
    }

    // Move Bouncing Moon & Increment Counter
    function incrementState() {
      if (count >= TOTAL_CELLS) return;

      startTimer();
      count++;
      playTapSound(320 + (count % 40) * 8);

      const cols = getGridCols();
      const prevRow = Math.ceil((count - 1) / cols) || 1;
      const currentRow = Math.ceil(count / cols);

      updateUI();
      saveProgress();

      // Check Row Completion Toast
      if (count > 1 && (count % cols === 0 || count === TOTAL_CELLS)) {
        const rowDuration = secondsElapsed - lastRowTime;
        lastRowTime = secondsElapsed;
        showToast(`Row ${prevRow} Complete!`, `Took ${formatTime(rowDuration)}`, 'fa-flag-checkered', 'text-cyan-400');
      }

      // Check Every 100 Cells Milestone Toast
      if (count % 100 === 0 && count < TOTAL_CELLS) {
        const centumDuration = secondsElapsed - lastCentumTime;
        lastCentumTime = secondsElapsed;
        showToast(`100 Zikr Milestone! (${count}/1000)`, `Last 100 took ${formatTime(centumDuration)} | Total: ${formatTime(secondsElapsed)}`, 'fa-star', 'text-amber-400');
        celebrateMilestone(60);
      } else if (count === TOTAL_CELLS) {
        stopTimer();
        if (isDemoRunning) toggleDemoMode();
        showToast(`🎉 1,000 Zikr Complete!`, `Total Duration: ${formatTime(secondsElapsed)}`, 'fa-trophy', 'text-emerald-400');
        celebrateAll();
      }
    }

    function updateUI() {
      const cols = getGridCols();

      for (let i = 1; i <= TOTAL_CELLS; i++) {
        const cell = document.getElementById(`cell-${i}`);
        const cellRow = Math.ceil(i / cols);
        const colorPaletteIndex = (cellRow - 1) % 10;

        if (i < count) {
          cell.className = `relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border border-white/10 row-hue-${colorPaletteIndex} text-white shadow-md`;
          cell.innerHTML = `<i class="fa-solid fa-check text-xs"></i><span class="text-[9px] opacity-80">${i}</span>`;
        } else if (i === count) {
          cell.className = 'relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border-2 border-amber-300 bg-amber-500/20 text-amber-200 font-bold scale-105 shadow-lg shadow-amber-500/30';
          cell.innerHTML = `<div id="active-moon" class="text-amber-300 text-sm animate-bounce"><i class="fa-solid fa-moon"></i></div><span class="text-[9px]">${i}</span>`;
          cell.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
          cell.className = 'relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border border-slate-800/80 bg-slate-900/80 text-slate-500';
          cell.innerHTML = `<span class="text-[10px] font-mono opacity-60">${i}</span>`;
        }
      }

      currentCountEl.textContent = count;
      remainingCountEl.textContent = TOTAL_CELLS - count;
      
      const row = Math.ceil(count / cols) || 1;
      const col = count % cols === 0 ? cols : count % cols;
      locationTrackerEl.textContent = `R${row} · C${col}`;

      progressBar.style.width = `${(count / TOTAL_CELLS) * 100}%`;
    }

    // Automated Demo Mode Toggle
    function toggleDemoMode(e) {
      if (e) e.stopPropagation();
      const demoBtn = document.getElementById('btn-demo');
      const demoIcon = document.getElementById('demo-icon');

      if (isDemoRunning) {
        clearInterval(demoInterval);
        isDemoRunning = false;
        demoBtn.classList.remove('bg-rose-500/20', 'text-rose-400', 'border-rose-500/30');
        demoBtn.classList.add('bg-indigo-500/10', 'text-indigo-400', 'border-indigo-500/30');
        demoIcon.className = 'fa-solid fa-play';
        showToast('Demo Stopped', 'Manual control restored.', 'fa-pause', 'text-slate-400');
      } else {
        if (count >= TOTAL_CELLS) return;
        isDemoRunning = true;
        demoBtn.classList.remove('bg-indigo-500/10', 'text-indigo-400', 'border-indigo-500/30');
        demoBtn.classList.add('bg-rose-500/20', 'text-rose-400', 'border-rose-500/30');
        demoIcon.className = 'fa-solid fa-square';

        const remaining = TOTAL_CELLS - count;
        const estSeconds = Math.round(remaining * 0.08); // 80ms per tick
        showToast('Automated Demo Started', `Est. completion time: ~${formatTime(estSeconds)}`, 'fa-robot', 'text-indigo-400');

        demoInterval = setInterval(() => {
          if (count < TOTAL_CELLS) {
            incrementState();
          } else {
            toggleDemoMode();
          }
        }, 80);
      }
    }

    // Toast Notification Creator
    function showToast(title, subtitle, iconClass = 'fa-bell', colorClass = 'text-emerald-400') {
      const toast = document.createElement('div');
      toast.className = 'bg-slate-900/95 border border-slate-800 p-3.5 rounded-xl shadow-xl flex items-center gap-3 backdrop-blur-md text-slate-200 transition-all duration-300 transform translate-x-10 opacity-0';
      toast.innerHTML = `
        <div class="p-2 bg-slate-800 rounded-lg ${colorClass}">
          <i class="fa-solid ${iconClass} text-lg"></i>
        </div>
        <div>
          <h4 class="text-xs font-bold">${title}</h4>
          <p class="text-[11px] text-slate-400">${subtitle}</p>
        </div>
      `;
      toastContainer.appendChild(toast);

      setTimeout(() => {
        toast.classList.remove('translate-x-10', 'opacity-0');
      }, 50);

      setTimeout(() => {
        toast.classList.add('opacity-0', 'translate-x-10');
        setTimeout(() => toast.remove(), 300);
      }, 4000);
    }

    // Help Modal Toggle
    function toggleHelpModal(e) {
      if (e) e.stopPropagation();
      const modal = document.getElementById('help-modal');
      modal.classList.toggle('hidden');
    }

    // Onboarding Interactive Tour
    const tourSteps = [
      {
        icon: 'fa-solid fa-moon',
        title: 'Bouncing Moon Tracker',
        desc: 'As you tap or press Space/Enter, your progress is tracked visually cell by cell with a bouncing moon.'
      },
      {
        icon: 'fa-solid fa-stopwatch',
        title: 'Stopwatch & Row Milestones',
        desc: 'The live stopwatch logs your pace automatically. Get toast notifications detailing exact completion times per row and every 100 Zikr!'
      },
      {
        icon: 'fa-solid fa-robot',
        title: 'Automated Demo Mode',
        desc: 'Want to sit back and watch? Tap the Demo button to simulate rapid counting and preview total estimated time.'
      }
    ];
    let currentTourStep = 0;

    function startOnboarding(e) {
      if (e) e.stopPropagation();
      currentTourStep = 0;
      updateTourStep();
      document.getElementById('onboarding-overlay').classList.remove('hidden');
    }

    function updateTourStep() {
      const step = tourSteps[currentTourStep];
      document.getElementById('tour-step-icon').innerHTML = `<i class="${step.icon}"></i>`;
      document.getElementById('tour-step-title').textContent = step.title;
      document.getElementById('tour-step-desc').textContent = step.desc;
      document.getElementById('tour-step-counter').textContent = `Step ${currentTourStep + 1} of ${tourSteps.length}`;
      document.getElementById('tour-next-btn').textContent = currentTourStep === tourSteps.length - 1 ? 'Finish' : 'Next';
    }

    function nextTourStep() {
      if (currentTourStep < tourSteps.length - 1) {
        currentTourStep++;
        updateTourStep();
      } else {
        document.getElementById('onboarding-overlay').classList.add('hidden');
      }
    }

    // Keyboard Control
    document.addEventListener('keydown', (e) => {
      if (e.code === 'Space' || e.code === 'Enter') {
        e.preventDefault();
        if (!isDemoRunning) incrementState();
      }
    });

    // Global Tap/Click Handler
    function handleGlobalClick(e) {
      if (e.target.closest('button') || e.target.closest('#grid-wrapper') || e.target.closest('#help-modal') || e.target.closest('#onboarding-overlay')) return;
      if (!isDemoRunning) incrementState();
    }

    gridWrapper.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!isDemoRunning) incrementState();
    });

    // Celebration Animations
    function celebrateMilestone(particleCount = 50) {
      confetti({ particleCount, spread: 60, origin: { y: 0.7 } });
    }

    function celebrateAll() {
      const duration = 4 * 1000;
      const end = Date.now() + duration;

      (function frame() {
        confetti({ particleCount: 8, angle: 60, spread: 55, origin: { x: 0 } });
        confetti({ particleCount: 8, angle: 120, spread: 55, origin: { x: 1 } });
        if (Date.now() < end) requestAnimationFrame(frame);
      })();
    }

    // Persistence Layer
    function saveProgress() {
      localStorage.setItem('zikr_count_v2', count);
      localStorage.setItem('zikr_time_v2', secondsElapsed);
      
      fetch('index_v2.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'save', count: count, elapsed_time: secondsElapsed })
      }).catch(() => {});
    }

    function loadProgress() {
      const savedCount = localStorage.getItem('zikr_count_v2');
      const savedTime = localStorage.getItem('zikr_time_v2');

      if (savedCount !== null) {
        count = parseInt(savedCount, 10);
        secondsElapsed = savedTime ? parseInt(savedTime, 10) : 0;
        stopwatchDisplay.textContent = formatTime(secondsElapsed);
        updateUI();
      } else {
        fetch('index_v2.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ action: 'load' })
        })
        .then(res => res.json())
        .then(data => {
          if (data && data.count !== undefined) {
            count = data.count;
            secondsElapsed = data.elapsed_time || 0;
            stopwatchDisplay.textContent = formatTime(secondsElapsed);
            updateUI();
          }
        }).catch(() => {});
      }
    }

    function resetCounter(e) {
      if (e) e.stopPropagation();
      if (confirm('Are you sure you want to reset your Zikr count and stopwatch to 0?')) {
        if (isDemoRunning) toggleDemoMode();
        stopTimer();
        count = 0;
        secondsElapsed = 0;
        lastRowTime = 0;
        lastCentumTime = 0;
        stopwatchDisplay.textContent = formatTime(0);
        saveProgress();
        updateUI();
        showToast('Counter Reset', 'All progress has been cleared.', 'fa-rotate-left', 'text-rose-400');
      }
    }

    // Window Resize Handler for Grid Reflow
    window.addEventListener('resize', () => updateUI());

    // Initialize Application
    initGrid();
    loadProgress();

    // Launch onboarding on first visit
    if (!localStorage.getItem('zikr_v2_visited')) {
      localStorage.setItem('zikr_v2_visited', 'true');
      startOnboarding();
    }
  </script>
</body>
</html>