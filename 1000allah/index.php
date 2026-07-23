<?php
// Optional SQLite persistence layer
$db = null;
if (extension_loaded('sqlite3')) {
    try {
        $db = new SQLite3('zikr_progress.db');
        $db->exec('CREATE TABLE IF NOT EXISTS progress (id INTEGER PRIMARY KEY, count INTEGER, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            header('Content-Type: application/json');
            if ($_POST['action'] === 'save' && isset($_POST['count'])) {
                $count = (int)$_POST['count'];
                $stmt = $db->prepare('INSERT INTO progress (id, count) VALUES (1, :count) ON CONFLICT(id) DO UPDATE SET count = :count, updated_at = CURRENT_TIMESTAMP');
                $stmt->bindValue(':count', $count, SQLITE3_INTEGER);
                $stmt->execute();
                echo json_encode(['status' => 'success']);
                exit;
            }
            if ($_POST['action'] === 'load') {
                $res = $db->querySingle('SELECT count FROM progress WHERE id = 1');
                echo json_encode(['count' => $res !== false ? (int)$res : 0]);
                exit;
            }
        }
    } catch (Exception $e) {
        // Fallback gracefully to localStorage if SQLite is unavailable
        $db = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>1,000 Allah Zikr Counter</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
      background-color: #0d1117;
      background-image: radial-gradient(#1f293d 1px, transparent 1px);
      background-size: 24px 24px;
    }
    .grid-container {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
      gap: 6px;
    }
    .cell-active {
      background: linear-gradient(135deg, #059669 0%, #10b981 100%);
      box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);
    }
    .car-node {
      transition: all 0.15s ease-out;
    }
    .custom-scrollbar::-webkit-scrollbar {
      width: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
      background: #374151;
      border-radius: 4px;
    }
  </style>
</head>
<body class="text-slate-200 min-h-screen flex flex-col justify-between font-sans select-none" onclick="handleGlobalClick(event)">

  <!-- Header & Dynamic Dashboard -->
  <header class="sticky top-0 z-30 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 p-4 shadow-xl">
    <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
      
      <div class="flex items-center gap-3">
        <div class="p-3 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
          <i class="fa-solid fa-kaaba text-emerald-400 text-2xl"></i>
        </div>
        <div>
          <h1 class="text-xl font-bold text-slate-100 flex items-center gap-2">
            Allah Zikr <span class="text-xs bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full">1,000 Target</span>
          </h1>
          <p class="text-xs text-slate-400">Tap anywhere, or press <kbd class="px-1.5 py-0.5 bg-slate-800 border border-slate-700 rounded text-slate-300">Space</kbd> / <kbd class="px-1.5 py-0.5 bg-slate-800 border border-slate-700 rounded text-slate-300">Enter</kbd></p>
        </div>
      </div>

      <!-- Live Counters -->
      <div class="flex items-center gap-6 bg-slate-950/60 border border-slate-800/80 px-6 py-2.5 rounded-2xl">
        <div class="text-center">
          <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider block">Completed</span>
          <span id="current-count" class="text-2xl font-black text-emerald-400">0</span>
        </div>
        <div class="h-8 w-px bg-slate-800"></div>
        <div class="text-center">
          <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider block">Remaining</span>
          <span id="remaining-count" class="text-2xl font-black text-amber-400">1000</span>
        </div>
        <div class="h-8 w-px bg-slate-800"></div>
        <div class="text-center">
          <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider block">Row / Col</span>
          <span id="location-tracker" class="text-sm font-bold text-slate-300">R1 · C0</span>
        </div>
      </div>

      <!-- Control Buttons -->
      <div class="flex items-center gap-2">
        <button id="reset-btn" onclick="resetCounter(event)" class="px-4 py-2 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded-xl transition flex items-center gap-2 font-medium text-sm">
          <i class="fa-solid fa-rotate-left"></i> Reset All
        </button>
      </div>

    </div>

    <!-- Progress Bar -->
    <div class="max-w-6xl mx-auto mt-3 bg-slate-800 h-2 rounded-full overflow-hidden">
      <div id="progress-bar" class="bg-gradient-to-r from-emerald-500 to-teal-400 h-full w-0 transition-all duration-300"></div>
    </div>
  </header>

  <!-- Grid Main Container -->
  <main class="max-w-6xl mx-auto w-full p-4 my-4 flex-grow">
    <div id="grid-wrapper" class="grid-container bg-slate-900/40 p-4 border border-slate-800 rounded-2xl max-h-[70vh] overflow-y-auto custom-scrollbar">
      <!-- 1,000 Cells Dynamic Generation -->
    </div>
  </main>

  <!-- Footer -->
  <footer class="border-t border-slate-800/60 bg-slate-950/80 p-3 text-center text-xs text-slate-500">
    Subhanallah · Alhamdulillah · Allahu Akbar — Daily Zikr Tracker
  </footer>

  <script>
    const TOTAL_CELLS = 1000;
    let count = 0;
    const gridWrapper = document.getElementById('grid-wrapper');
    const currentCountEl = document.getElementById('current-count');
    const remainingCountEl = document.getElementById('remaining-count');
    const locationTrackerEl = document.getElementById('location-tracker');
    const progressBar = document.getElementById('progress-bar');

    // Audio effects via Web Audio API (No external assets required)
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    function playTapSound(freq = 440) {
      if (audioCtx.state === 'suspended') audioCtx.resume();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
      gain.gain.setValueAtTime(0.05, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.1);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.1);
    }

    // Build 1,000 Grid Nodes
    function initGrid() {
      gridWrapper.innerHTML = '';
      for (let i = 1; i <= TOTAL_CELLS; i++) {
        const cell = document.createElement('div');
        cell.id = `cell-${i}`;
        cell.className = 'relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border border-slate-800/80 bg-slate-900/80 text-slate-500 transition-all';
        cell.innerHTML = `<span class="text-[10px] font-mono opacity-60">${i}</span>`;
        gridWrapper.appendChild(cell);
      }
    }

    // Move Car & Increment State
    function incrementState() {
      if (count >= TOTAL_CELLS) return;

      count++;
      playTapSound(350 + (count % 50) * 5); // Pitch ascends dynamically
      updateUI();
      saveProgress();

      // Check Milestones
      if (count % 100 === 0 && count < TOTAL_CELLS) {
        celebrateRow();
      } else if (count === TOTAL_CELLS) {
        celebrateAll();
      }
    }

    function updateUI() {
      // Clear Previous Active Styles & Car
      const prevCar = document.getElementById('active-car');
      if (prevCar) prevCar.remove();

      for (let i = 1; i <= TOTAL_CELLS; i++) {
        const cell = document.getElementById(`cell-${i}`);
        if (i < count) {
          cell.className = 'relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border border-emerald-500/40 cell-active text-white';
          cell.innerHTML = `<i class="fa-solid fa-check text-xs"></i><span class="text-[9px] opacity-80">${i}</span>`;
        } else if (i === count) {
          cell.className = 'relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border-2 border-amber-400 bg-amber-500/10 text-amber-300 font-bold scale-105 shadow-lg shadow-amber-500/20';
          cell.innerHTML = `<div id="active-car" class="car-node text-amber-400 text-sm animate-bounce"><i class="fa-solid fa-car-side"></i></div><span class="text-[9px]">${i}</span>`;
          cell.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
          cell.className = 'relative flex flex-col items-center justify-center p-2 min-h-[48px] rounded-lg border border-slate-800/80 bg-slate-900/80 text-slate-500';
          cell.innerHTML = `<span class="text-[10px] font-mono opacity-60">${i}</span>`;
        }
      }

      // Update Top Status Cards
      currentCountEl.textContent = count;
      remainingCountEl.textContent = TOTAL_CELLS - count;
      
      // Calculate row & col (assuming dynamic grid layout)
      const cols = Math.floor(gridWrapper.clientWidth / 48) || 10;
      const row = Math.ceil(count / cols) || 1;
      const col = count % cols === 0 ? cols : count % cols;
      locationTrackerEl.textContent = `R${row} · C${col}`;

      progressBar.style.width = `${(count / TOTAL_CELLS) * 100}%`;
    }

    // Key Handler
    document.addEventListener('keydown', (e) => {
      if (e.code === 'Space' || e.code === 'Enter') {
        e.preventDefault();
        incrementState();
      }
    });

    // Global Tap/Click Handler (Ignore buttons)
    function handleGlobalClick(e) {
      if (e.target.closest('button') || e.target.closest('#grid-wrapper')) return;
      incrementState();
    }

    // Grid Direct Tap
    gridWrapper.addEventListener('click', (e) => {
      e.stopPropagation();
      incrementState();
    });

    // Celebrations via Confetti API
    function celebrateRow() {
      confetti({
        particleCount: 50,
        spread: 60,
        origin: { y: 0.7 }
      });
    }

    function celebrateAll() {
      const duration = 3 * 1000;
      const end = Date.now() + duration;

      (function frame() {
        confetti({ particleCount: 7, angle: 60, spread: 55, origin: { x: 0 } });
        confetti({ particleCount: 7, angle: 120, spread: 55, origin: { x: 1 } });
        if (Date.now() < end) requestAnimationFrame(frame);
      })();
    }

    // Save & Sync Persistence
    function saveProgress() {
      localStorage.setItem('zikr_count', count);
      
      // Sync with PHP/SQLite backend if operational
      fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'save', count: count })
      }).catch(() => {}); // Gracefully fallback if server offline
    }

    function loadProgress() {
      const saved = localStorage.getItem('zikr_count');
      if (saved !== null) {
        count = parseInt(saved, 10);
        updateUI();
      } else {
        fetch('index.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ action: 'load' })
        })
        .then(res => res.json())
        .then(data => {
          if (data && data.count !== undefined) {
            count = data.count;
            updateUI();
          }
        }).catch(() => {});
      }
    }

    function resetCounter(e) {
      if (e) e.stopPropagation();
      if (confirm('Are you sure you want to reset your Zikr count to 0?')) {
        count = 0;
        saveProgress();
        updateUI();
      }
    }

    // Initialize
    initGrid();
    loadProgress();
  </script>
</body>
</html>