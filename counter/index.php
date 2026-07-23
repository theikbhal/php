<?php
// --- DATABASE SETUP & HELPERS ---
$dbFile = __DIR__ . '/counters.db';
$db = new PDO("sqlite:$dbFile");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS counters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    type TEXT CHECK(type IN ('up', 'down')) NOT NULL DEFAULT 'up',
    target INTEGER DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// AJAX Action Router
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? 'New Counter');
            $type = $_POST['type'] === 'down' ? 'down' : 'up';
            $initial = (int)($_POST['initial'] ?? 0);
            $target = ($_POST['target'] !== '' && $_POST['target'] !== null) ? (int)$_POST['target'] : null;

            $stmt = $db->prepare("INSERT INTO counters (name, count, type, target) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $initial, $type, $target]);
            echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]);
        } 
        elseif ($action === 'update_count') {
            $id = (int)$_POST['id'];
            $delta = (int)$_POST['delta'];
            
            $stmt = $db->prepare("UPDATE counters SET count = count + ? WHERE id = ?");
            $stmt->execute([$delta, $id]);
            echo json_encode(['status' => 'success']);
        } 
        elseif ($action === 'reset') {
            $id = (int)$_POST['id'];
            $value = (int)($_POST['value'] ?? 0);
            
            $stmt = $db->prepare("UPDATE counters SET count = ? WHERE id = ?");
            $stmt->execute([$value, $id]);
            echo json_encode(['status' => 'success']);
        } 
        elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("DELETE FROM counters WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch all counters for initial load
$counters = $db->query("SELECT * FROM counters ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Counter Dashboard</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --border: #334155;
            --text: #f8fafc;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --danger: #f43f5e;
            --success: #22c55e;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: var(--bg); color: var(--text); padding: 20px; max-width: 1000px; margin: 0 auto; line-height: 1.5; }

        header { margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        h1 { font-size: 1.5rem; font-weight: 600; }

        /* Form Layout */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; align-items: end; }
        
        label { display: block; font-size: 0.8rem; color: var(--muted); margin-bottom: 4px; }
        input, select, button { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg); color: var(--text); font-size: 0.9rem; }
        input:focus, select:focus { outline: 2px solid var(--accent); border-color: transparent; }

        button { background: var(--accent); color: #000; font-weight: 600; cursor: pointer; border: none; transition: opacity 0.15s; }
        button:hover { opacity: 0.9; }
        button.btn-danger { background: var(--danger); color: #fff; }
        button.btn-secondary { background: var(--border); color: var(--text); }

        /* Grid */
        .counters-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        
        .counter-card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; position: relative; }
        .counter-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .counter-title { font-weight: 600; font-size: 1rem; word-break: break-all; }
        .counter-badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; background: var(--border); color: var(--muted); text-transform: uppercase; }

        .counter-value { font-size: 2.5rem; font-weight: 700; text-align: center; margin: 12px 0; font-variant-numeric: tabular-nums; }
        .counter-target { font-size: 0.8rem; text-align: center; color: var(--muted); margin-top: -8px; margin-bottom: 12px; }
        .counter-target.reached { color: var(--success); font-weight: 600; }

        .counter-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
        .counter-actions button { font-size: 1.2rem; font-weight: 700; }
        
        .counter-footer { display: flex; gap: 8px; margin-top: 8px; }
        .counter-footer button { font-size: 0.75rem; font-weight: 500; padding: 6px; }

        .empty-state { text-align: center; color: var(--muted); padding: 40px; }
    </style>
</head>
<body>

<header>
    <h1>Counter Engine</h1>
</header>

<!-- Create Counter Form -->
<section class="card">
    <form id="createForm" class="form-grid">
        <div>
            <label for="name">Counter Name</label>
            <input type="text" id="name" name="name" placeholder="e.g. Daily Sales" required>
        </div>
        <div>
            <label for="type">Mode</label>
            <select id="type" name="type">
                <option value="up">Count Up (+)</option>
                <option value="down">Count Down (-)</option>
            </select>
        </div>
        <div>
            <label for="initial">Start Value</label>
            <input type="number" id="initial" name="initial" value="0">
        </div>
        <div>
            <label for="target">Target (Optional)</label>
            <input type="number" id="target" name="target" placeholder="e.g. 100">
        </div>
        <div>
            <button type="submit" id="btn-create">Add Counter</button>
        </div>
    </form>
</section>

<!-- Counters Output -->
<main id="countersContainer" class="counters-grid">
    <?php if (empty($counters)): ?>
        <div class="empty-state" id="emptyState">No active counters. Create one above to get started.</div>
    <?php else: ?>
        <?php foreach ($counters as $c): ?>
            <?php 
                $isTargetReached = ($c['target'] !== null) && 
                    (($c['type'] === 'up' && $c['count'] >= $c['target']) || 
                     ($c['type'] === 'down' && $c['count'] <= $c['target']));
            ?>
            <div class="counter-card" id="counter-<?= $c['id'] ?>" data-id="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>" data-target="<?= $c['target'] ?? '' ?>">
                <div>
                    <div class="counter-header">
                        <span class="counter-title"><?= htmlspecialchars($c['name']) ?></span>
                        <span class="counter-badge"><?= $c['type'] === 'up' ? 'Count Up' : 'Count Down' ?></span>
                    </div>
                    <div class="counter-value" id="val-<?= $c['id'] ?>"><?= $c['count'] ?></div>
                    <div class="counter-target <?= $isTargetReached ? 'reached' : '' ?>" id="target-<?= $c['id'] ?>">
                        <?php if ($c['target'] !== null): ?>
                            Target: <?= $c['target'] ?> <?= $isTargetReached ? '✓ (Reached)' : '' ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="counter-actions">
                        <button type="button" class="btn-secondary btn-dec" data-id="<?= $c['id'] ?>" aria-label="Decrement">-</button>
                        <button type="button" class="btn-inc" data-id="<?= $c['id'] ?>" aria-label="Increment">+</button>
                    </div>
                    <div class="counter-footer">
                        <button type="button" class="btn-secondary btn-reset" data-id="<?= $c['id'] ?>" data-initial="<?= $c['count'] ?>">Reset</button>
                        <button type="button" class="btn-danger btn-delete" data-id="<?= $c['id'] ?>">Delete</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('countersContainer');

    // Create Handler
    document.getElementById('createForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'create');

        const res = await fetch('index.php', { method: 'POST', body: formData });
        if (res.ok) {
            window.location.reload(); // Quick re-render for UI sync
        }
    });

    // Event Delegation for Counter Actions (Increment, Decrement, Reset, Delete)
    container.addEventListener('click', async (e) => {
        const target = e.target;
        const id = target.dataset.id;
        if (!id) return;

        // Increment / Decrement
        if (target.classList.contains('btn-inc') || target.classList.contains('btn-dec')) {
            const delta = target.classList.contains('btn-inc') ? 1 : -1;
            const valEl = document.getElementById(`val-${id}`);
            let currentVal = parseInt(valEl.innerText) + delta;
            valEl.innerText = currentVal;

            updateTargetStatus(id, currentVal);

            const formData = new FormData();
            formData.append('action', 'update_count');
            formData.append('id', id);
            formData.append('delta', delta);
            await fetch('index.php', { method: 'POST', body: formData });
        }

        // Reset
        if (target.classList.contains('btn-reset')) {
            const resetVal = prompt("Enter value to reset counter to:", "0");
            if (resetVal === null) return;
            
            const numericReset = parseInt(resetVal) || 0;
            const valEl = document.getElementById(`val-${id}`);
            valEl.innerText = numericReset;

            updateTargetStatus(id, numericReset);

            const formData = new FormData();
            formData.append('action', 'reset');
            formData.append('id', id);
            formData.append('value', numericReset);
            await fetch('index.php', { method: 'POST', body: formData });
        }

        // Delete
        if (target.classList.contains('btn-delete')) {
            if (!confirm("Are you sure you want to delete this counter?")) return;
            
            document.getElementById(`counter-${id}`).remove();

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            await fetch('index.php', { method: 'POST', body: formData });
        }
    });

    function updateTargetStatus(id, currentVal) {
        const card = document.getElementById(`counter-${id}`);
        const targetVal = card.dataset.target;
        const type = card.dataset.type;
        const targetEl = document.getElementById(`target-${id}`);

        if (targetVal !== '') {
            const targetNum = parseInt(targetVal);
            const reached = (type === 'up' && currentVal >= targetNum) || (type === 'down' && currentVal <= targetNum);
            targetEl.className = `counter-target ${reached ? 'reached' : ''}`;
            targetEl.innerText = `Target: ${targetNum} ${reached ? '✓ (Reached)' : ''}`;
        }
    }
});
</script>

</body>
</html>