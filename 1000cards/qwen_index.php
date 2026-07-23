<?php
/* =========================================================================
 * FlashDeck — single-file PHP flashcard app
 * SQLite, mobile-first, responsive, searchable, groups, pomodoro, calendar
 * ========================================================================= */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

define('APP_VERSION', '1.0.0');
define('DB_FILE', __DIR__ . '/flashdeck.sqlite');

/* ---------- Database ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $firstRun = !file_exists(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    if ($firstRun) seed_schema($pdo);
    return $pdo;
}

function seed_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            color TEXT DEFAULT '#6366f1',
            sort_order INTEGER DEFAULT 0,
            is_calendar_default INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            word TEXT NOT NULL,
            five_words TEXT DEFAULT '[]',
            notes TEXT DEFAULT '',
            group_id INTEGER,
            has_checkbox INTEGER DEFAULT 0,
            checkbox_value INTEGER DEFAULT 0,
            has_counter INTEGER DEFAULT 0,
            counter_value INTEGER DEFAULT 0,
            has_text INTEGER DEFAULT 0,
            text_value TEXT DEFAULT '',
            has_list INTEGER DEFAULT 0,
            list_value TEXT DEFAULT '[]',
            has_date INTEGER DEFAULT 0,
            date_value TEXT DEFAULT '',
            has_datetime INTEGER DEFAULT 0,
            datetime_value TEXT DEFAULT '',
            calendar_start TEXT DEFAULT '',
            calendar_end TEXT DEFAULT '',
            calendar_group_ids TEXT DEFAULT '[]',
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS pomodoro_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_id INTEGER,
            title TEXT DEFAULT '',
            sort_order INTEGER DEFAULT 0,
            completed INTEGER DEFAULT 0,
            pomodoro_count INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY(card_id) REFERENCES cards(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS pomodoro_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER,
            type TEXT DEFAULT 'work',
            duration_sec INTEGER DEFAULT 0,
            started_at TEXT DEFAULT (datetime('now')),
            ended_at TEXT,
            FOREIGN KEY(task_id) REFERENCES pomodoro_tasks(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_cards_group ON cards(group_id);
        CREATE INDEX IF NOT EXISTS idx_cards_word ON cards(word);
    ");
    // default group
    $c = $pdo->query("SELECT COUNT(*) c FROM groups")->fetch();
    if ((int)$c['c'] === 0) {
        $pdo->exec("INSERT INTO groups(name, description, is_calendar_default) VALUES('Inbox','Default group',1)");
    }
    // seed a few demo cards
    $c = $pdo->query("SELECT COUNT(*) c FROM cards")->fetch();
    if ((int)$c['c'] === 0) {
        $stmt = $pdo->prepare("INSERT INTO cards(word, five_words, notes, group_id) VALUES(?,?,?,1)");
        $demos = [
            ['Serendipity', '["luck","chance","happy","accident","find"]', 'A happy accident.'],
            ['Ephemeral', '["brief","short","fleeting","transient","momentary"]', 'Lasting a short time.'],
            ['Mellifluous', '["sweet","smooth","musical","pleasant","tuneful"]', 'Sweet-sounding.'],
        ];
        foreach ($demos as $d) $stmt->execute($d);
    }
}

/* ---------- Helpers ---------- */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
function req_json(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function ok($data = ['ok' => true]) { json_out($data); }
function err(string $msg, int $code = 400) { json_out(['error' => $msg], $code); }
function now_iso(): string { return date('c'); }

/* ---------- API Router ---------- */
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

if ($action !== null || $method === 'POST') {
    $db = db();
    try {
        switch ($action) {
            /* ----- Groups ----- */
            case 'groups.list':
                $rows = $db->query("SELECT * FROM groups ORDER BY sort_order ASC, id ASC")->fetchAll();
                foreach ($rows as &$r) {
                    $r['card_count'] = (int)$db->query("SELECT COUNT(*) FROM cards WHERE group_id=".(int)$r['id'])->fetchColumn();
                }
                ok(['groups' => $rows]);

            case 'groups.create':
                $j = req_json();
                $name = trim($j['name'] ?? '');
                if ($name === '') err('name required');
                $st = $db->prepare("INSERT INTO groups(name, description, color, sort_order, is_calendar_default) VALUES(?,?,?,?,?)");
                $st->execute([$name, $j['description'] ?? '', $j['color'] ?? '#6366f1', (int)($j['sort_order'] ?? 0), !empty($j['is_calendar_default']) ? 1 : 0]);
                ok(['id' => (int)$db->lastInsertId()]);

            case 'groups.update':
                $j = req_json();
                $id = (int)($j['id'] ?? 0);
                if (!$id) err('id required');
                $db->prepare("UPDATE groups SET name=?, description=?, color=?, sort_order=?, is_calendar_default=?, updated_at=? WHERE id=?")
                   ->execute([$j['name'] ?? '', $j['description'] ?? '', $j['color'] ?? '#6366f1', (int)($j['sort_order'] ?? 0), !empty($j['is_calendar_default'])?1:0, now_iso(), $id]);
                ok();

            case 'groups.delete':
                $j = req_json();
                $id = (int)($j['id'] ?? 0);
                if (!$id) err('id required');
                $db->prepare("UPDATE cards SET group_id=NULL WHERE group_id=?")->execute([$id]);
                $db->prepare("DELETE FROM groups WHERE id=?")->execute([$id]);
                ok();

            case 'groups.reorder':
                $j = req_json();
                $order = $j['order'] ?? [];
                foreach ($order as $i => $id) {
                    $db->prepare("UPDATE groups SET sort_order=? WHERE id=?")->execute([(int)$i, (int)$id]);
                }
                ok();

            /* ----- Cards ----- */
            case 'cards.list':
                $gid = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;
                $q = trim($_GET['q'] ?? '');
                $page = max(1, (int)($_GET['page'] ?? 1));
                $per = min(200, max(1, (int)($_GET['per'] ?? 50)));
                $sort = $_GET['sort'] ?? 'updated';
                $where = []; $params = [];
                if ($gid !== null) { $where[] = 'group_id = ?'; $params[] = $gid; }
                if ($q !== '') { $where[] = '(word LIKE ? OR notes LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
                $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
                $order = match($sort) {
                    'word' => 'word ASC',
                    'created' => 'created_at DESC',
                    default => 'updated_at DESC',
                };
                $total = (int)$db->query("SELECT COUNT(*) FROM cards $wsql", $params ?: null)->fetchColumn();
                // Re-run with params properly
                $cntSt = $db->prepare("SELECT COUNT(*) FROM cards $wsql");
                $cntSt->execute($params);
                $total = (int)$cntSt->fetchColumn();
                $st = $db->prepare("SELECT * FROM cards $wsql ORDER BY $order LIMIT $per OFFSET " . (($page-1)*$per));
                $st->execute($params);
                $rows = $st->fetchAll();
                foreach ($rows as &$r) {
                    $r['five_words'] = json_decode($r['five_words'] ?: '[]', true);
                    $r['list_value'] = json_decode($r['list_value'] ?: '[]', true);
                    $r['calendar_group_ids'] = json_decode($r['calendar_group_ids'] ?: '[]', true);
                }
                ok(['cards' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);

            case 'cards.get':
                $id = (int)($_GET['id'] ?? 0);
                $st = $db->prepare("SELECT * FROM cards WHERE id=?");
                $st->execute([$id]);
                $r = $st->fetch();
                if (!$r) err('not found', 404);
                $r['five_words'] = json_decode($r['five_words'] ?: '[]', true);
                $r['list_value'] = json_decode($r['list_value'] ?: '[]', true);
                $r['calendar_group_ids'] = json_decode($r['calendar_group_ids'] ?: '[]', true);
                ok(['card' => $r]);

            case 'cards.create':
                $j = req_json();
                $word = trim($j['word'] ?? '');
                if ($word === '') err('word required');
                $fw = $j['five_words'] ?? [];
                if (!is_array($fw)) $fw = array_slice(explode(',', (string)$fw), 0, 5);
                $fw = array_values(array_filter(array_map('trim', $fw)));
                $fw = array_slice($fw, 0, 5);
                $list = $j['list_value'] ?? [];
                if (!is_array($list)) $list = array_values(array_filter(array_map('trim', explode("\n", (string)$list))));
                $calG = $j['calendar_group_ids'] ?? [];
                if (!is_array($calG)) $calG = [];
                $st = $db->prepare("INSERT INTO cards
                    (word, five_words, notes, group_id,
                     has_checkbox, checkbox_value, has_counter, counter_value,
                     has_text, text_value, has_list, list_value,
                     has_date, date_value, has_datetime, datetime_value,
                     calendar_start, calendar_end, calendar_group_ids)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([
                    $word, json_encode($fw), $j['notes'] ?? '', (int)($j['group_id'] ?? 0) ?: null,
                    !empty($j['has_checkbox'])?1:0, (int)($j['checkbox_value'] ?? 0),
                    !empty($j['has_counter'])?1:0, (int)($j['counter_value'] ?? 0),
                    !empty($j['has_text'])?1:0, $j['text_value'] ?? '',
                    !empty($j['has_list'])?1:0, json_encode($list),
                    !empty($j['has_date'])?1:0, $j['date_value'] ?? '',
                    !empty($j['has_datetime'])?1:0, $j['datetime_value'] ?? '',
                    $j['calendar_start'] ?? '', $j['calendar_end'] ?? '', json_encode($calG),
                ]);
                ok(['id' => (int)$db->lastInsertId()]);

            case 'cards.update':
                $j = req_json();
                $id = (int)($j['id'] ?? 0);
                if (!$id) err('id required');
                $fw = $j['five_words'] ?? [];
                if (!is_array($fw)) $fw = array_slice(explode(',', (string)$fw), 0, 5);
                $fw = array_values(array_filter(array_map('trim', $fw)));
                $fw = array_slice($fw, 0, 5);
                $list = $j['list_value'] ?? [];
                if (!is_array($list)) $list = array_values(array_filter(array_map('trim', explode("\n", (string)$list))));
                $calG = $j['calendar_group_ids'] ?? [];
                if (!is_array($calG)) $calG = [];
                $st = $db->prepare("UPDATE cards SET
                    word=?, five_words=?, notes=?, group_id=?,
                    has_checkbox=?, checkbox_value=?, has_counter=?, counter_value=?,
                    has_text=?, text_value=?, has_list=?, list_value=?,
                    has_date=?, date_value=?, has_datetime=?, datetime_value=?,
                    calendar_start=?, calendar_end=?, calendar_group_ids=?,
                    updated_at=? WHERE id=?");
                $st->execute([
                    $j['word'] ?? '', json_encode($fw), $j['notes'] ?? '', (int)($j['group_id'] ?? 0) ?: null,
                    !empty($j['has_checkbox'])?1:0, (int)($j['checkbox_value'] ?? 0),
                    !empty($j['has_counter'])?1:0, (int)($j['counter_value'] ?? 0),
                    !empty($j['has_text'])?1:0, $j['text_value'] ?? '',
                    !empty($j['has_list'])?1:0, json_encode($list),
                    !empty($j['has_date'])?1:0, $j['date_value'] ?? '',
                    !empty($j['has_datetime'])?1:0, $j['datetime_value'] ?? '',
                    $j['calendar_start'] ?? '', $j['calendar_end'] ?? '', json_encode($calG),
                    now_iso(), $id,
                ]);
                ok();

            case 'cards.delete':
                $j = req_json();
                $id = (int)($j['id'] ?? 0);
                if (!$id) err('id required');
                $db->prepare("DELETE FROM cards WHERE id=?")->execute([$id]);
                ok();

            case 'cards.move':
                $j = req_json();
                $ids = (array)($j['ids'] ?? []);
                $gid = (int)($j['group_id'] ?? 0) ?: null;
                $copy = !empty($j['copy']);
                if (!$ids) err('ids required');
                if ($copy) {
                    foreach ($ids as $id) {
                        $st = $db->prepare("SELECT * FROM cards WHERE id=?");
                        $st->execute([(int)$id]);
                        $r = $st->fetch();
                        if (!$r) continue;
                        $in = $db->prepare("INSERT INTO cards
                            (word,five_words,notes,group_id,has_checkbox,checkbox_value,has_counter,counter_value,has_text,text_value,has_list,list_value,has_date,date_value,has_datetime,datetime_value,calendar_start,calendar_end,calendar_group_ids)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $in->execute([
                            $r['word'].' (copy)', $r['five_words'], $r['notes'], $gid,
                            $r['has_checkbox'],$r['checkbox_value'],$r['has_counter'],$r['counter_value'],
                            $r['has_text'],$r['text_value'],$r['has_list'],$r['list_value'],
                            $r['has_date'],$r['date_value'],$r['has_datetime'],$r['datetime_value'],
                            $r['calendar_start'],$r['calendar_end'],$r['calendar_group_ids'],
                        ]);
                    }
                } else {
                    $st = $db->prepare("UPDATE cards SET group_id=?, updated_at=? WHERE id=?");
                    foreach ($ids as $id) $st->execute([$gid, now_iso(), (int)$id]);
                }
                ok();

            case 'cards.export':
                $rows = $db->query("SELECT * FROM cards ORDER BY id")->fetchAll();
                foreach ($rows as &$r) {
                    $r['five_words'] = json_decode($r['five_words'] ?: '[]', true);
                    $r['list_value'] = json_decode($r['list_value'] ?: '[]', true);
                    $r['calendar_group_ids'] = json_decode($r['calendar_group_ids'] ?: '[]', true);
                }
                $groups = $db->query("SELECT * FROM groups ORDER BY id")->fetchAll();
                header('Content-Disposition: attachment; filename="flashdeck-export.json"');
                json_out(['version' => APP_VERSION, 'exported_at' => now_iso(), 'groups' => $groups, 'cards' => $rows]);

            case 'cards.import':
                $j = req_json();
                $cards = $j['cards'] ?? [];
                $groups = $j['groups'] ?? [];
                $db->beginTransaction();
                $gidMap = [];
                foreach ($groups as $g) {
                    $old = (int)($g['id'] ?? 0);
                    $db->prepare("INSERT INTO groups(name, description, color, sort_order, is_calendar_default) VALUES(?,?,?,?,?)")
                       ->execute([$g['name'] ?? 'Imported', $g['description'] ?? '', $g['color'] ?? '#6366f1', (int)($g['sort_order'] ?? 0), !empty($g['is_calendar_default'])?1:0]);
                    $gidMap[$old] = (int)$db->lastInsertId();
                }
                $imported = 0;
                foreach ($cards as $c) {
                    $fw = $c['five_words'] ?? [];
                    if (!is_array($fw)) $fw = [];
                    $list = $c['list_value'] ?? [];
                    if (!is_array($list)) $list = [];
                    $calG = $c['calendar_group_ids'] ?? [];
                    if (!is_array($calG)) $calG = [];
                    $gid = isset($c['group_id']) && isset($gidMap[(int)$c['group_id']]) ? $gidMap[(int)$c['group_id']] : null;
                    $db->prepare("INSERT INTO cards
                        (word,five_words,notes,group_id,has_checkbox,checkbox_value,has_counter,counter_value,has_text,text_value,has_list,list_value,has_date,date_value,has_datetime,datetime_value,calendar_start,calendar_end,calendar_group_ids)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([
                           $c['word'] ?? '', json_encode($fw), $c['notes'] ?? '', $gid,
                           !empty($c['has_checkbox'])?1:0, (int)($c['checkbox_value'] ?? 0),
                           !empty($c['has_counter'])?1:0, (int)($c['counter_value'] ?? 0),
                           !empty($c['has_text'])?1:0, $c['text_value'] ?? '',
                           !empty($c['has_list'])?1:0, json_encode($list),
                           !empty($c['has_date'])?1:0, $c['date_value'] ?? '',
                           !empty($c['has_datetime'])?1:0, $c['datetime_value'] ?? '',
                           $c['calendar_start'] ?? '', $c['calendar_end'] ?? '', json_encode($calG),
                       ]);
                    $imported++;
                }
                $db->commit();
                ok(['imported' => $imported]);

            /* ----- Pomodoro ----- */
            case 'tasks.list':
                $rows = $db->query("SELECT t.*, c.word AS card_word FROM pomodoro_tasks t LEFT JOIN cards c ON c.id=t.card_id ORDER BY t.sort_order ASC, t.id ASC")->fetchAll();
                ok(['tasks' => $rows]);

            case 'tasks.create':
                $j = req_json();
                $st = $db->prepare("INSERT INTO pomodoro_tasks(card_id,title,sort_order) VALUES(?,?,?)");
                $st->execute([(int)($j['card_id'] ?? 0) ?: null, $j['title'] ?? '', (int)($j['sort_order'] ?? 0)]);
                ok(['id' => (int)$db->lastInsertId()]);

            case 'tasks.update':
                $j = req_json();
                $id = (int)($j['id'] ?? 0);
                if (!$id) err('id required');
                $db->prepare("UPDATE pomodoro_tasks SET card_id=?, title=?, sort_order=?, completed=?, pomodoro_count=? WHERE id=?")
                   ->execute([(int)($j['card_id'] ?? 0) ?: null, $j['title'] ?? '', (int)($j['sort_order'] ?? 0), !empty($j['completed'])?1:0, (int)($j['pomodoro_count'] ?? 0), $id]);
                ok();

            case 'tasks.delete':
                $j = req_json();
                $db->prepare("DELETE FROM pomodoro_tasks WHERE id=?")->execute([(int)($j['id'] ?? 0)]);
                ok();

            case 'tasks.reorder':
                $j = req_json();
                foreach (($j['order'] ?? []) as $i => $id) {
                    $db->prepare("UPDATE pomodoro_tasks SET sort_order=? WHERE id=?")->execute([(int)$i, (int)$id]);
                }
                ok();

            case 'tasks.top':
                // return top task for pomodoro pick
                $r = $db->query("SELECT * FROM pomodoro_tasks WHERE completed=0 ORDER BY sort_order ASC, id ASC LIMIT 1")->fetch();
                ok(['task' => $r ?: null]);

            /* ----- Calendar ----- */
            case 'calendar.range':
                $start = $_GET['start'] ?? date('Y-m-01');
                $end = $_GET['end'] ?? date('Y-m-t');
                $st = $db->prepare("SELECT * FROM cards WHERE
                    (has_date=1 AND date_value BETWEEN ? AND ?) OR
                    (has_datetime=1 AND datetime_value BETWEEN ? AND ?) OR
                    (calendar_start<>'' AND calendar_start <= ? AND calendar_end >= ?)");
                $st->execute([$start, $end, $start, $end, $end, $start]);
                $rows = $st->fetchAll();
                foreach ($rows as &$r) {
                    $r['five_words'] = json_decode($r['five_words'] ?: '[]', true);
                    $r['calendar_group_ids'] = json_decode($r['calendar_group_ids'] ?: '[]', true);
                }
                ok(['cards' => $rows]);

            /* ----- Settings ----- */
            case 'settings.get':
                $rows = $db->query("SELECT * FROM settings")->fetchAll();
                $out = [];
                foreach ($rows as $r) $out[$r['key']] = $r['value'];
                ok(['settings' => $out]);

            case 'settings.set':
                $j = req_json();
                foreach (($j['settings'] ?? []) as $k => $v) {
                    $db->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
                       ->execute([$k, (string)$v]);
                }
                ok();

            default:
                err('unknown action', 404);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        err($e->getMessage(), 500);
    }
}

/* ---------- HTML UI ---------- */
$groups = db()->query("SELECT * FROM groups ORDER BY sort_order ASC, id ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>FlashDeck · <?= APP_VERSION ?></title>
<style>
:root{
  --bg:#0b1020; --bg2:#111936; --fg:#e8ecff; --muted:#9aa3c7;
  --accent:#6366f1; --accent2:#22d3ee; --ok:#10b981; --warn:#f59e0b; --err:#ef4444;
  --card:#161e3f; --card2:#1c2550; --border:#2a3366; --shadow:0 10px 30px rgba(0,0,0,.35);
  --radius:14px;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--fg);font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Inter,sans-serif}
body{
  min-height:100vh;
  background:
    radial-gradient(1200px 600px at 10% -10%, rgba(99,102,241,.25), transparent 60%),
    radial-gradient(900px 500px at 110% 10%, rgba(34,211,238,.18), transparent 60%),
    linear-gradient(180deg, var(--bg), var(--bg2));
  overflow-x:hidden;
}
/* animated background pattern */
.bg-pattern{position:fixed;inset:0;pointer-events:none;z-index:0;opacity:.35}
.bg-pattern::before{
  content:"";position:absolute;inset:-50%;
  background-image:
    radial-gradient(circle at 20% 30%, rgba(255,255,255,.08) 0 2px, transparent 3px),
    radial-gradient(circle at 70% 60%, rgba(255,255,255,.06) 0 2px, transparent 3px),
    radial-gradient(circle at 40% 80%, rgba(255,255,255,.07) 0 2px, transparent 3px);
  background-size: 120px 120px, 180px 180px, 220px 220px;
  animation: drift 60s linear infinite;
}
@keyframes drift{to{transform:translate(120px,120px)}}

.app{position:relative;z-index:1;max-width:1200px;margin:0 auto;padding:16px}
header.top{
  display:flex;gap:10px;align-items:center;flex-wrap:wrap;
  padding:10px 12px;background:rgba(22,30,63,.7);backdrop-filter:blur(8px);
  border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);
  position:sticky;top:8px;z-index:5
}
.logo{display:flex;align-items:center;gap:8px;font-weight:700;letter-spacing:.3px}
.logo .dot{width:14px;height:14px;border-radius:50%;background:conic-gradient(from 0deg,var(--accent),var(--accent2),var(--accent));animation:spin 6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.spacer{flex:1}
button,.btn{
  background:var(--card);color:var(--fg);border:1px solid var(--border);
  padding:8px 12px;border-radius:10px;cursor:pointer;font:inherit;
  transition:transform .08s ease, background .2s, border-color .2s;
  display:inline-flex;align-items:center;gap:6px
}
button:hover,.btn:hover{background:var(--card2);border-color:#3b4694}
button:active{transform:translateY(1px)}
button.primary{background:linear-gradient(135deg,var(--accent),#8b5cf6);border-color:transparent}
button.ghost{background:transparent}
button.danger{background:transparent;border-color:#7a2a2a;color:#fca5a5}
input,textarea,select{
  background:#0e1532;color:var(--fg);border:1px solid var(--border);
  border-radius:10px;padding:9px 11px;font:inherit;width:100%
}
input:focus,textarea:focus,select:focus{outline:2px solid var(--accent);border-color:transparent}
label.row{display:flex;align-items:center;gap:8px}
.grid{display:grid;gap:12px}
@media(min-width:860px){.grid.cols-2{grid-template-columns:1fr 1fr}.grid.cols-3{grid-template-columns:repeat(3,1fr)}}

/* Tabs */
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0}
.tab{padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:var(--card);cursor:pointer}
.tab.active{background:linear-gradient(135deg,var(--accent),#8b5cf6);border-color:transparent}

/* Cards */
.card{
  background:linear-gradient(180deg,var(--card),var(--card2));
  border:1px solid var(--border);border-radius:var(--radius);padding:14px;
  box-shadow:var(--shadow);transition:transform .15s ease, box-shadow .2s;
  position:relative;overflow:hidden
}
.card .word{font-size:22px;font-weight:700;letter-spacing:.2px}
.card .sub{color:var(--muted);font-size:13px;margin-top:4px;min-height:18px}
.card .five{display:none;margin-top:8px;gap:6px;flex-wrap:wrap}
.card:hover .five,.card.show .five{display:flex}
.chip{background:#0e1532;border:1px solid var(--border);padding:4px 8px;border-radius:999px;font-size:12px;color:#c7cff5}
.card .actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.card .group-badge{position:absolute;top:10px;right:10px;font-size:11px;padding:3px 8px;border-radius:999px;background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.4);color:#c7cff5}
.card.focused{outline:2px solid var(--accent2);box-shadow:0 0 0 4px rgba(34,211,238,.15), var(--shadow)}

/* Modal */
.modal{position:fixed;inset:0;background:rgba(3,6,20,.7);display:none;align-items:flex-start;justify-content:center;z-index:50;padding:40px 12px;overflow:auto}
.modal.open{display:flex}
.modal .box{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);width:min(720px,100%);padding:16px;box-shadow:var(--shadow)}
.modal h3{margin:0 0 10px}
.close-x{float:right;background:transparent;border:none;color:var(--muted);font-size:20px;cursor:pointer}

/* Carousel / focus */
.carousel{display:none;position:fixed;inset:0;background:rgba(3,6,20,.85);z-index:40;align-items:center;justify-content:center;padding:20px}
.carousel.open{display:flex}
.carousel .stage{width:min(560px,100%);text-align:center}
.carousel .stage .big{font-size:44px;font-weight:800;letter-spacing:.5px}
.carousel .stage .five{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px}
.carousel .hint{color:var(--muted);margin-top:14px;font-size:13px}

/* Pomodoro */
.pomo{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.pomo .time{font-variant-numeric:tabular-nums;font-size:34px;font-weight:800;letter-spacing:1px}
.ring{--p:0;--size:160px;width:var(--size);height:var(--size);border-radius:50%;
  background:conic-gradient(var(--accent) calc(var(--p)*1%), #1c2550 0);
  display:flex;align-items:center;justify-content:center;position:relative}
.ring::after{content:"";position:absolute;inset:10px;border-radius:50%;background:var(--bg2)}
.ring .inner{position:relative;z-index:1;text-align:center}

/* Calendar */
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
.cal .dow{text-align:center;color:var(--muted);font-size:12px;padding:4px}
.cal .day{background:var(--card);border:1px solid var(--border);border-radius:10px;min-height:86px;padding:6px;font-size:12px;position:relative}
.cal .day .num{color:var(--muted);font-weight:700}
.cal .day.today{outline:2px solid var(--accent2)}
.cal .day .ev{margin-top:4px;background:#0e1532;border:1px solid var(--border);border-radius:6px;padding:2px 5px;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* Stopwatch */
.stopwatch{font-variant-numeric:tabular-nums;font-size:40px;font-weight:800}

/* Toast */
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#0e1532;border:1px solid var(--border);padding:10px 14px;border-radius:10px;z-index:100;opacity:0;transition:opacity .25s, transform .25s}
.toast.show{opacity:1;transform:translateX(-50%) translateY(-4px)}

/* Confetti */
.confetti{position:fixed;inset:0;pointer-events:none;z-index:90;overflow:hidden}
.confetti i{position:absolute;width:8px;height:14px;top:-20px;opacity:.9;animation:fall 1.6s linear forwards}
@keyframes fall{to{transform:translateY(110vh) rotate(720deg);opacity:0}}

/* Onboarding */
.steps{display:flex;gap:8px;margin:10px 0}
.step{flex:1;height:4px;background:#1c2550;border-radius:4px}
.step.active{background:linear-gradient(90deg,var(--accent),var(--accent2))}

/* Help */
.kbd{background:#0e1532;border:1px solid var(--border);padding:1px 6px;border-radius:6px;font-family:ui-monospace,Menlo,monospace;font-size:12px}

/* Mobile */
@media(max-width:520px){
  .card .word{font-size:18px}
  .cal .day{min-height:64px}
  header.top{position:static}
}
</style>
</head>
<body>
<div class="bg-pattern" aria-hidden="true"></div>

<div class="app">
  <header class="top">
    <div class="logo"><span class="dot"></span> FlashDeck</div>
    <div class="spacer"></div>
    <button id="btnSearch" title="Search (Ctrl+K)">🔎 <span class="kbd">Ctrl</span>+<span class="kbd">K</span></button>
    <button id="btnPomodoro" title="Pomodoro">🍅</button>
    <button id="btnStopwatch" title="Stopwatch">⏱</button>
    <button id="btnCalendar" title="Calendar">📅</button>
    <button id="btnGroups" title="Groups">🗂</button>
    <button id="btnImport" title="Import">⬇</button>
    <button id="btnExport" title="Export">⬆</button>
    <button id="btnHelp" title="Help">❔</button>
  </header>

  <div class="tabs" id="tabs">
    <div class="tab active" data-view="cards">Cards</div>
    <div class="tab" data-view="tasks">Pomodoro Tasks</div>
    <div class="tab" data-view="calendar">Calendar</div>
  </div>

  <!-- Cards view -->
  <section id="view-cards">
    <div class="grid cols-3" style="margin-bottom:12px">
      <div>
        <label>Group</label>
        <select id="filterGroup"><option value="">All groups</option></select>
      </div>
      <div>
        <label>Sort</label>
        <select id="sortSel">
          <option value="updated">Recently updated</option>
          <option value="created">Recently created</option>
          <option value="word">Word A→Z</option>
        </select>
      </div>
      <div style="display:flex;align-items:end;gap:8px">
        <button class="primary" id="btnNewCard">＋ New card</button>
        <button id="btnBulk">Bulk</button>
      </div>
    </div>
    <div id="cardsGrid" class="grid cols-3"></div>
    <div id="pager" style="display:flex;gap:8px;justify-content:center;margin-top:14px"></div>
  </section>

  <!-- Tasks view -->
  <section id="view-tasks" style="display:none">
    <div class="grid cols-2">
      <div class="card">
        <h3 style="margin-top:0">Task queue</h3>
        <div id="taskList"></div>
        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
          <input id="newTaskTitle" placeholder="New task title…">
          <select id="newTaskCard" style="max-width:200px"></select>
          <button class="primary" id="btnAddTask">Add</button>
        </div>
      </div>
      <div class="card">
        <h3 style="margin-top:0">Pomodoro</h3>
        <div class="pomo" style="justify-content:center">
          <div class="ring" id="pomoRing"><div class="inner"><div class="time" id="pomoTime">25:00</div><div id="pomoLabel" style="color:var(--muted);font-size:12px">work</div></div></div>
        </div>
        <div style="display:flex;gap:6px;justify-content:center;margin-top:10px;flex-wrap:wrap">
          <button id="pomoStart">▶ Start</button>
          <button id="pomoPause">⏸ Pause</button>
          <button id="pomoReset">↺ Reset</button>
          <button id="pomoSkip">⏭ Skip</button>
        </div>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:8px;flex-wrap:wrap">
          <label>Work <input id="pomoWork" type="number" value="25" min="1" max="120" style="width:80px"></label>
          <label>Short <input id="pomoShort" type="number" value="3" min="1" max="30" style="width:80px"></label>
          <label>Long <input id="pomoLong" type="number" value="15" min="1" max="60" style="width:80px"></label>
        </div>
        <div id="pomoCurrent" style="margin-top:10px;color:var(--muted);font-size:13px"></div>
      </div>
    </div>
  </section>

  <!-- Calendar view -->
  <section id="view-calendar" style="display:none">
    <div class="card">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button id="calPrev">◀</button>
        <div id="calTitle" style="font-weight:700"></div>
        <button id="calNext">▶</button>
        <div class="spacer"></div>
        <button id="btnNewCalCard">＋ Schedule card</button>
      </div>
      <div class="cal" id="calGrid" style="margin-top:10px"></div>
    </div>
  </section>
</div>

<!-- Search modal -->
<div class="modal" id="searchModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3>Search</h3>
    <input id="searchInput" placeholder="Type to search… (Esc to close)" autofocus>
    <div id="searchResults" class="grid cols-2" style="margin-top:10px;max-height:50vh;overflow:auto"></div>
  </div>
</div>

<!-- Card editor modal -->
<div class="modal" id="cardModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3 id="cardModalTitle">New card</h3>
    <div class="grid cols-2">
      <div><label>Word</label><input id="f_word"></div>
      <div><label>Group</label><select id="f_group"></select></div>
      <div style="grid-column:1/-1"><label>5 related words (comma separated)</label><input id="f_five" placeholder="one, two, three, four, five"></div>
      <div style="grid-column:1/-1"><label>Notes</label><textarea id="f_notes" rows="3"></textarea></div>

      <div><label class="row"><input type="checkbox" id="f_has_checkbox"> Checkbox</label><input id="f_checkbox_value" type="number" value="0" style="margin-top:6px" disabled></div>
      <div><label class="row"><input type="checkbox" id="f_has_counter"> Counter</label><input id="f_counter_value" type="number" value="0" style="margin-top:6px" disabled></div>
      <div><label class="row"><input type="checkbox" id="f_has_text"> Text box</label><input id="f_text_value" style="margin-top:6px" disabled></div>
      <div><label class="row"><input type="checkbox" id="f_has_list"> List of text</label><textarea id="f_list_value" rows="2" style="margin-top:6px" disabled></textarea></div>
      <div><label class="row"><input type="checkbox" id="f_has_date"> Date</label><input id="f_date_value" type="date" style="margin-top:6px" disabled></div>
      <div><label class="row"><input type="checkbox" id="f_has_datetime"> Date & time</label><input id="f_datetime_value" type="datetime-local" style="margin-top:6px" disabled></div>

      <div><label class="row"><input type="checkbox" id="f_has_calrange"> Calendar range</label></div>
      <div></div>
      <div><label>Start</label><input id="f_cal_start" type="date" disabled></div>
      <div><label>End</label><input id="f_cal_end" type="date" disabled></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
      <button class="danger" id="cardDelete" style="display:none">Delete</button>
      <button data-close>Cancel</button>
      <button class="primary" id="cardSave">Save</button>
    </div>
  </div>
</div>

<!-- Groups modal -->
<div class="modal" id="groupsModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3>Groups</h3>
    <div style="display:flex;gap:6px;margin-bottom:10px">
      <input id="g_name" placeholder="Group name">
      <input id="g_desc" placeholder="Description (optional)">
      <input id="g_color" type="color" value="#6366f1" style="width:60px">
      <button class="primary" id="g_add">Add</button>
    </div>
    <div id="g_list" class="grid"></div>
  </div>
</div>

<!-- Pomodoro modal (shortcut) -->
<div class="modal" id="pomoModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3>Pomodoro</h3>
    <div class="pomo" style="justify-content:center">
      <div class="ring" id="pomoRing2"><div class="inner"><div class="time" id="pomoTime2">25:00</div><div id="pomoLabel2" style="color:var(--muted);font-size:12px">work</div></div></div>
    </div>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:10px">
      <button id="pomoStart2">▶ Start</button>
      <button id="pomoPause2">⏸ Pause</button>
      <button id="pomoReset2">↺ Reset</button>
      <button id="pomoSkip2">⏭ Skip</button>
    </div>
    <div id="pomoCurrent2" style="margin-top:10px;color:var(--muted);font-size:13px;text-align:center"></div>
  </div>
</div>

<!-- Stopwatch modal -->
<div class="modal" id="swModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3>Stopwatch</h3>
    <div class="stopwatch" id="swTime" style="text-align:center">00:00.0</div>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:10px">
      <button id="swStart">▶ Start</button>
      <button id="swPause">⏸ Pause</button>
      <button id="swReset">↺ Reset</button>
      <button id="swLap">🏁 Lap</button>
    </div>
    <div id="swLaps" style="margin-top:10px;max-height:30vh;overflow:auto"></div>
  </div>
</div>

<!-- Calendar card schedule modal -->
<div class="modal" id="calCardModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3>Schedule card on calendar</h3>
    <div class="grid cols-2">
      <div><label>Card</label><select id="cc_card"></select></div>
      <div><label>Group(s)</label><select id="cc_groups" multiple size="5"></select></div>
      <div><label>Start date</label><input id="cc_start" type="date"></div>
      <div><label>End date</label><input id="cc_end" type="date"></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
      <button data-close>Cancel</button>
      <button class="primary" id="cc_save">Save</button>
    </div>
  </div>
</div>

<!-- Bulk modal -->
<div class="modal" id="bulkModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3>Bulk actions</h3>
    <p style="color:var(--muted)">Click cards to select. Then choose an action.</p>
    <div id="bulkSelected" style="color:var(--muted);font-size:13px">Selected: 0</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
      <button id="bulkMove">Move to group…</button>
      <button id="bulkCopy">Copy to group…</button>
      <button id="bulkDelete">Delete selected</button>
      <button id="bulkSelectAll">Select all on page</button>
      <button id="bulkSelectNone">Clear</button>
      <button id="bulkRandom">Random select 5</button>
    </div>
    <div style="margin-top:10px"><label>Target group</label><select id="bulkGroup"></select></div>
  </div>
</div>

<!-- Help modal -->
<div class="modal" id="helpModal">
  <div class="box">
    <button class="close-x" data-close>×</button>
    <h3>Help & Shortcuts</h3>
    <ul style="line-height:1.9">
      <li><span class="kbd">Ctrl</span>+<span class="kbd">K</span> — search</li>
      <li><span class="kbd">N</span> — new card</li>
      <li><span class="kbd">←</span> <span class="kbd">→</span> or <span class="kbd">A</span> <span class="kbd">D</span> — navigate cards</li>
      <li><span class="kbd">W</span> <span class="kbd">S</span> — up/down in grid</li>
      <li><span class="kbd">Enter</span> / <span class="kbd">Space</span> — open focused card</li>
      <li><span class="kbd">E</span> — edit focused card</li>
      <li><span class="kbd">Del</span> — delete focused card</li>
      <li><span class="kbd">Esc</span> — close modal / exit focus</li>
      <li>Hover a card to peek at its 5 words; tap/click to reveal.</li>
      <li>API: <code>?action=cards.list</code>, <code>cards.create</code>, <code>cards.update</code>, <code>cards.delete</code>, <code>cards.move</code>, <code>cards.export</code>, <code>cards.import</code>, <code>groups.*</code>, <code>tasks.*</code>, <code>calendar.range</code>, <code>settings.*</code>. POST JSON body.</li>
    </ul>
  </div>
</div>

<!-- Onboarding modal -->
<div class="modal" id="onboardModal">
  <div class="box">
    <h3 id="obTitle">Welcome to FlashDeck 🎉</h3>
    <div class="steps" id="obSteps"></div>
    <div id="obBody" style="min-height:120px"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
      <button id="obSkip" class="ghost">Skip</button>
      <button id="obNext" class="primary">Next</button>
    </div>
  </div>
</div>

<!-- Carousel (focus) -->
<div class="carousel" id="carousel">
  <div class="stage">
    <div class="big" id="cWord"></div>
    <div class="five" id="cFive"></div>
    <div id="cNotes" style="margin-top:14px;color:var(--muted)"></div>
    <div class="hint">← → to navigate · E edit · Esc close</div>
  </div>
</div>

<div class="toast" id="toast"></div>
<div class="confetti" id="confetti"></div>

<script>
/* =========================================================
 * FlashDeck client
 * =======================================================*/
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const api = async (action, body=null, qs='') => {
  const url = 'index.php?action=' + encodeURIComponent(action) + (qs ? '&' + qs : '');
  const opt = { method: body ? 'POST' : 'GET', headers: {'Content-Type':'application/json'} };
  if (body) opt.body = JSON.stringify(body);
  const r = await fetch(url, opt);
  const j = await r.json();
  if (!r.ok) throw new Error(j.error || 'request failed');
  return j;
};
const toast = (msg, ms=1800) => {
  const t = $('#toast'); t.textContent = msg; t.classList.add('show');
  clearTimeout(toast._t); toast._t = setTimeout(()=>t.classList.remove('show'), ms);
};
const celebrate = () => {
  const c = $('#confetti'); c.innerHTML = '';
  const colors = ['#6366f1','#22d3ee','#10b981','#f59e0b','#ef4444','#8b5cf6'];
  for (let i=0;i<60;i++){
    const s = document.createElement('i');
    s.style.left = Math.random()*100 + 'vw';
    s.style.background = colors[i%colors.length];
    s.style.animationDelay = (Math.random()*.4)+'s';
    s.style.transform = `rotate(${Math.random()*360}deg)`;
    c.appendChild(s);
  }
  setTimeout(()=>c.innerHTML='', 2200);
};
const openModal = id => $('#'+id).classList.add('open');
const closeModal = id => $('#'+id).classList.remove('open');
document.addEventListener('click', e => {
  if (e.target.matches('[data-close]')) {
    const m = e.target.closest('.modal'); if (m) m.classList.remove('open');
  }
});

/* ---------- State ---------- */
const state = {
  groups: [],
  cards: [],
  total: 0,
  page: 1,
  per: 30,
  sort: 'updated',
  groupFilter: null,
  focusIdx: -1,
  bulk: new Set(),
  bulkMode: false,
  tasks: [],
  pomo: { running:false, remaining:25*60, phase:'work', work:25, short:3, long:15, currentTask:null, interval:null, count:0 },
  sw: { running:false, start:0, elapsed:0, interval:null, laps:[] },
  cal: { cursor: new Date() },
  onboardSeen: localStorage.getItem('fd_onboard_v1') === '1',
};

/* ---------- Groups ---------- */
async function loadGroups(){
  const j = await api('groups.list');
  state.groups = j.groups;
  // populate selects
  const fill = (sel, includeAll=false) => {
    sel.innerHTML = '';
    if (includeAll) sel.appendChild(Object.assign(document.createElement('option'),{value:'',textContent:'All groups'}));
    state.groups.forEach(g => sel.appendChild(Object.assign(document.createElement('option'),{value:g.id,textContent:g.name+' ('+g.card_count+')'})));
  };
  fill($('#filterGroup'), true);
  fill($('#f_group'));
  fill($('#bulkGroup'));
  const ccg = $('#cc_groups'); ccg.innerHTML='';
  state.groups.forEach(g => ccg.appendChild(Object.assign(document.createElement('option'),{value:g.id,textContent:g.name})));
}

/* ---------- Cards ---------- */
async function loadCards(){
  const qs = new URLSearchParams({page:state.page, per:state.per, sort:state.sort});
  if (state.groupFilter) qs.set('group_id', state.groupFilter);
  const j = await api('cards.list', null, qs.toString());
  state.cards = j.cards; state.total = j.total;
  renderCards();
  renderPager();
}
function renderCards(){
  const grid = $('#cardsGrid'); grid.innerHTML = '';
  state.cards.forEach((c, i) => {
    const g = state.groups.find(x => x.id === c.group_id);
    const el = document.createElement('div');
    el.className = 'card' + (i === state.focusIdx ? ' focused' : '');
    el.dataset.idx = i;
    el.innerHTML = `
      ${g ? `<div class="group-badge" style="background:${g.color}22;border-color:${g.color}66;color:${g.color}">${escapeHtml(g.name)}</div>` : ''}
      <div class="word">${escapeHtml(c.word)}</div>
      <div class="sub">${escapeHtml((c.five_words||[]).slice(0,2).join(' · '))}</div>
      <div class="five">${(c.five_words||[]).map(w=>`<span class="chip">${escapeHtml(w)}</span>`).join('')}</div>
      <div class="actions">
        <button data-act="open">Open</button>
        <button data-act="edit">Edit</button>
        <button data-act="select">Select</button>
      </div>`;
    el.addEventListener('click', e => {
      const act = e.target.dataset.act;
      if (act === 'open') openCarousel(i);
      else if (act === 'edit') openCardEditor(c);
      else if (act === 'select') toggleBulk(c.id);
      else { el.classList.toggle('show'); }
    });
    grid.appendChild(el);
  });
  $('#bulkSelected').textContent = 'Selected: ' + state.bulk.size;
}
function renderPager(){
  const p = $('#pager'); p.innerHTML = '';
  const pages = Math.max(1, Math.ceil(state.total / state.per));
  const add = (label, page, disabled=false) => {
    const b = document.createElement('button'); b.textContent = label; b.disabled = disabled;
    b.onclick = () => { state.page = page; loadCards(); };
    p.appendChild(b);
  };
  add('◀', state.page-1, state.page<=1);
  for (let i=1;i<=pages;i++) if (pages<=9 || i===1 || i===pages || Math.abs(i-state.page)<=1) add(String(i), i);
  add('▶', state.page+1, state.page>=pages);
}

/* ---------- Card editor ---------- */
let editingId = null;
function openCardEditor(card=null){
  editingId = card ? card.id : null;
  $('#cardModalTitle').textContent = card ? 'Edit card' : 'New card';
  $('#cardDelete').style.display = card ? '' : 'none';
  const f = {
    word:'', five_words:[], notes:'', group_id: state.groupFilter || (state.groups[0]?.id ?? ''),
    has_checkbox:0, checkbox_value:0, has_counter:0, counter_value:0,
    has_text:0, text_value:'', has_list:0, list_value:[],
    has_date:0, date_value:'', has_datetime:0, datetime_value:'',
    has_calrange:0, cal_start:'', cal_end:'', calendar_group_ids:[]
  };
  if (card) Object.assign(f, card, { has_calrange: (card.calendar_start||card.calendar_end) ? 1 : 0 });
  $('#f_word').value = f.word;
  $('#f_five').value = (f.five_words||[]).join(', ');
  $('#f_notes').value = f.notes || '';
  $('#f_group').value = f.group_id || '';
  const bind = (id, key, type='text') => {
    const el = $(id);
    if (type === 'check') { el.checked = !!f[key]; el.onchange = () => { const dis = !el.checked; const tgt = id.replace('has_','f_'); if ($(tgt)) $(tgt).disabled = dis; }; }
    else if (type === 'num') el.value = f[key] ?? 0;
    else el.value = f[key] ?? '';
  };
  bind('#f_has_checkbox','has_checkbox','check'); $('#f_checkbox_value').value = f.checkbox_value; $('#f_checkbox_value').disabled = !f.has_checkbox;
  bind('#f_has_counter','has_counter','check'); $('#f_counter_value').value = f.counter_value; $('#f_counter_value').disabled = !f.has_counter;
  bind('#f_has_text','has_text','check'); $('#f_text_value').value = f.text_value; $('#f_text_value').disabled = !f.has_text;
  bind('#f_has_list','has_list','check'); $('#f_list_value').value = (f.list_value||[]).join('\n'); $('#f_list_value').disabled = !f.has_list;
  bind('#f_has_date','has_date','check'); $('#f_date_value').value = f.date_value; $('#f_date_value').disabled = !f.has_date;
  bind('#f_has_datetime','has_datetime','check'); $('#f_datetime_value').value = f.datetime_value; $('#f_datetime_value').disabled = !f.has_datetime;
  $('#f_has_calrange').checked = !!f.has_calrange;
  $('#f_cal_start').value = f.cal_start || f.calendar_start || ''; $('#f_cal_start').disabled = !f.has_calrange;
  $('#f_cal_end').value = f.cal_end || f.calendar_end || ''; $('#f_cal_end').disabled = !f.has_calrange;
  $('#f_has_calrange').onchange = () => { $('#f_cal_start').disabled = $('#f_cal_end').disabled = !$('#f_has_calrange').checked; };
  openModal('cardModal');
}
$('#cardSave').onclick = async () => {
  const body = {
    word: $('#f_word').value.trim(),
    five_words: $('#f_five').value.split(',').map(s=>s.trim()).filter(Boolean).slice(0,5),
    notes: $('#f_notes').value,
    group_id: $('#f_group').value || null,
    has_checkbox: $('#f_has_checkbox').checked, checkbox_value: +$('#f_checkbox_value').value || 0,
    has_counter: $('#f_has_counter').checked, counter_value: +$('#f_counter_value').value || 0,
    has_text: $('#f_has_text').checked, text_value: $('#f_text_value').value,
    has_list: $('#f_has_list').checked, list_value: $('#f_list_value').value.split('\n').map(s=>s.trim()).filter(Boolean),
    has_date: $('#f_has_date').checked, date_value: $('#f_date_value').value,
    has_datetime: $('#f_has_datetime').checked, datetime_value: $('#f_datetime_value').value,
    calendar_start: $('#f_has_calrange').checked ? $('#f_cal_start').value : '',
    calendar_end: $('#f_has_calrange').checked ? $('#f_cal_end').value : '',
    calendar_group_ids: Array.from($('#cc_groups').selectedOptions||[]).map(o=>+o.value),
  };
  if (!body.word) return toast('Word is required');
  try {
    if (editingId) { await api('cards.update', { id: editingId, ...body }); toast('Saved'); celebrate(); }
    else { await api('cards.create', body); toast('Created'); celebrate(); }
    closeModal('cardModal'); loadCards();
  } catch(e){ toast(e.message); }
};
$('#cardDelete').onclick = async () => {
  if (!editingId) return;
  if (!confirm('Delete this card?')) return;
  await api('cards.delete', { id: editingId });
  closeModal('cardModal'); toast('Deleted'); loadCards();
};

/* ---------- Carousel (focus) ---------- */
function openCarousel(idx){
  state.focusIdx = idx;
  renderCarousel();
  $('#carousel').classList.add('open');
}
function renderCarousel(){
  const c = state.cards[state.focusIdx];
  if (!c) return;
  $('#cWord').textContent = c.word;
  $('#cFive').innerHTML = (c.five_words||[]).map(w=>`<span class="chip">${escapeHtml(w)}</span>`).join('');
  $('#cNotes').textContent = c.notes || '';
  $$('#cardsGrid .card').forEach((el,i)=>el.classList.toggle('focused', i===state.focusIdx));
}
$('#carousel').addEventListener('click', e => { if (e.target.id === 'carousel') $('#carousel').classList.remove('open'); });

/* ---------- Bulk ---------- */
function toggleBulk(id){
  if (state.bulk.has(id)) state.bulk.delete(id); else state.bulk.add(id);
  $('#bulkSelected').textContent = 'Selected: ' + state.bulk.size;
}
$('#btnBulk').onclick = () => openModal('bulkModal');
$('#bulkSelectAll').onclick = () => { state.cards.forEach(c => state.bulk.add(c.id)); renderCards(); };
$('#bulkSelectNone').onclick = () => { state.bulk.clear(); renderCards(); };
$('#bulkRandom').onclick = () => {
  state.bulk.clear();
  const arr = [...state.cards].sort(()=>Math.random()-.5).slice(0,5);
  arr.forEach(c => state.bulk.add(c.id)); renderCards();
};
$('#bulkMove').onclick = async () => {
  const gid = +$('#bulkGroup').value || null;
  if (!state.bulk.size) return toast('Select cards first');
  await api('cards.move', { ids: Array.from(state.bulk), group_id: gid, copy:false });
  state.bulk.clear(); toast('Moved'); celebrate(); loadGroups(); loadCards();
};
$('#bulkCopy').onclick = async () => {
  const gid = +$('#bulkGroup').value || null;
  if (!state.bulk.size) return toast('Select cards first');
  await api('cards.move', { ids: Array.from(state.bulk), group_id: gid, copy:true });
  state.bulk.clear(); toast('Copied'); celebrate(); loadGroups(); loadCards();
};
$('#bulkDelete').onclick = async () => {
  if (!state.bulk.size) return toast('Select cards first');
  if (!confirm('Delete '+state.bulk.size+' cards?')) return;
  for (const id of state.bulk) await api('cards.delete', { id });
  state.bulk.clear(); toast('Deleted'); loadGroups(); loadCards();
};

/* ---------- Groups UI ---------- */
$('#btnGroups').onclick = async () => { await renderGroupsList(); openModal('groupsModal'); };
async function renderGroupsList(){
  const list = $('#g_list'); list.innerHTML = '';
  state.groups.forEach(g => {
    const row = document.createElement('div');
    row.className = 'card';
    row.style.padding = '10px';
    row.innerHTML = `
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span style="width:14px;height:14px;border-radius:50%;background:${g.color}"></span>
        <strong>${escapeHtml(g.name)}</strong>
        <span style="color:var(--muted);font-size:12px">${escapeHtml(g.description||'')}</span>
        <span style="color:var(--muted);font-size:12px">· ${g.card_count} cards</span>
        ${g.is_calendar_default?'<span class="chip">calendar default</span>':''}
        <div class="spacer" style="flex:1"></div>
        <button data-act="edit">Edit</button>
        <button class="danger" data-act="del">Delete</button>
      </div>`;
    row.querySelector('[data-act=edit]').onclick = async () => {
      const name = prompt('Group name', g.name); if (name===null) return;
      const desc = prompt('Description', g.description||''); if (desc===null) return;
      await api('groups.update', { id:g.id, name, description:desc, color:g.color, is_calendar_default:g.is_calendar_default });
      await loadGroups(); renderGroupsList();
    };
    row.querySelector('[data-act=del]').onclick = async () => {
      if (!confirm('Delete group '+g.name+'?')) return;
      await api('groups.delete', { id:g.id });
      await loadGroups(); renderGroupsList(); loadCards();
    };
    list.appendChild(row);
  });
}
$('#g_add').onclick = async () => {
  const name = $('#g_name').value.trim(); if (!name) return toast('Name required');
  await api('groups.create', { name, description: $('#g_desc').value, color: $('#g_color').value });
  $('#g_name').value=''; $('#g_desc').value='';
  await loadGroups(); renderGroupsList(); celebrate();
};

/* ---------- Search ---------- */
$('#btnSearch').onclick = () => { openModal('searchModal'); setTimeout(()=>$('#searchInput').focus(),50); };
let searchTimer;
$('#searchInput').addEventListener('input', e => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    const q = e.target.value.trim();
    if (!q) { $('#searchResults').innerHTML=''; return; }
    const qs = new URLSearchParams({q, per:20});
    const j = await api('cards.list', null, qs.toString());
    const r = $('#searchResults'); r.innerHTML = '';
    j.cards.forEach(c => {
      const el = document.createElement('div'); el.className='card'; el.style.padding='10px';
      el.innerHTML = `<div class="word">${escapeHtml(c.word)}</div><div class="sub">${escapeHtml((c.five_words||[]).join(' · '))}</div>`;
      el.onclick = () => { closeModal('searchModal'); openCardEditor(c); };
      r.appendChild(el);
    });
    if (!j.cards.length) r.innerHTML = '<div style="color:var(--muted)">No results</div>';
  }, 180);
});

/* ---------- Tabs ---------- */
$$('#tabs .tab').forEach(t => t.onclick = () => {
  $$('#tabs .tab').forEach(x => x.classList.remove('active'));
  t.classList.add('active');
  const v = t.dataset.view;
  $('#view-cards').style.display = v==='cards' ? '' : 'none';
  $('#view-tasks').style.display = v==='tasks' ? '' : 'none';
  $('#view-calendar').style.display = v==='calendar' ? '' : 'none';
  if (v==='tasks') loadTasks();
  if (v==='calendar') renderCalendar();
});

/* ---------- Filters ---------- */
$('#filterGroup').onchange = e => { state.groupFilter = e.target.value || null; state.page=1; loadCards(); };
$('#sortSel').onchange = e => { state.sort = e.target.value; state.page=1; loadCards(); };
$('#btnNewCard').onclick = () => openCardEditor();

/* ---------- Pomodoro ---------- */
function fmt(s){ s=Math.max(0,s|0); const m=(s/60)|0, r=s%60; return String(m).padStart(2,'0')+':'+String(r).padStart(2,'0'); }
function pomoRender(){
  const p = state.pomo;
  const total = p.phase==='work' ? p.work*60 : (p.count % 4 === 0 ? p.long*60 : p.short*60);
  const pct = 100 - (p.remaining/total)*100;
  $('#pomoTime').textContent = fmt(p.remaining);
  $('#pomoLabel').textContent = p.phase + (p.phase==='work' ? ' · #'+(p.count+1) : '');
  $('#pomoRing').style.setProperty('--p', pct);
  $('#pomoTime2').textContent = fmt(p.remaining);
  $('#pomoLabel2').textContent = p.phase;
  $('#pomoRing2').style.setProperty('--p', pct);
  const cur = p.currentTask ? `Current: ${escapeHtml(p.currentTask.title||p.currentTask.card_word||'—')}` : 'No task selected — pick from queue';
  $('#pomoCurrent').textContent = cur; $('#pomoCurrent2').textContent = cur;
}
function pomoStart(){
  const p = state.pomo;
  if (p.running) return;
  p.running = true;
  p.interval = setInterval(() => {
    p.remaining--;
    if (p.remaining <= 0) {
      if (p.phase === 'work') {
        p.count++;
        if (p.currentTask) {
          api('tasks.update', { id:p.currentTask.id, pomodoro_count:(p.currentTask.pomodoro_count||0)+1, title:p.currentTask.title, card_id:p.currentTask.card_id, sort_order:p.currentTask.sort_order, completed:p.currentTask.completed }).catch(()=>{});
          p.currentTask.pomodoro_count = (p.currentTask.pomodoro_count||0)+1;
        }
        celebrate();
        p.phase = 'break';
        p.remaining = (p.count % 4 === 0 ? p.long : p.short) * 60;
      } else {
        p.phase = 'work';
        p.remaining = p.work * 60;
        // pick next task
        api('tasks.top').then(j => { p.currentTask = j.task; pomoRender(); }).catch(()=>{});
        celebrate();
      }
    }
    pomoRender();
  }, 1000);
}
function pomoPause(){ state.pomo.running=false; clearInterval(state.pomo.interval); }
function pomoReset(){ pomoPause(); state.pomo.remaining = state.pomo.work*60; state.pomo.phase='work'; pomoRender(); }
function pomoSkip(){
  const p = state.pomo;
  if (p.phase === 'work') { p.phase='break'; p.remaining = (p.count%4===0?p.long:p.short)*60; }
  else { p.phase='work'; p.remaining = p.work*60; }
  pomoRender();
}
['','2'].forEach(s => {
  $('#pomoStart'+s).onclick = pomoStart;
  $('#pomoPause'+s).onclick = pomoPause;
  $('#pomoReset'+s).onclick = pomoReset;
  $('#pomoSkip'+s).onclick = pomoSkip;
});
$('#btnPomodoro').onclick = () => openModal('pomoModal');
['pomoWork','pomoShort','pomoLong'].forEach(id => $('#'+id).onchange = e => {
  state.pomo[id.replace('pomo','').toLowerCase()] = +e.target.value || 1;
  pomoReset();
});

/* ---------- Tasks ---------- */
async function loadTasks(){
  const j = await api('tasks.list'); state.tasks = j.tasks;
  const list = $('#taskList'); list.innerHTML='';
  state.tasks.forEach(t => {
    const row = document.createElement('div');
    row.className = 'card'; row.style.padding='10px';
    row.style.opacity = t.completed ? .6 : 1;
    row.innerHTML = `
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="checkbox" ${t.completed?'checked':''} data-act="toggle">
        <strong>${escapeHtml(t.title || t.card_word || '(untitled)')}</strong>
        <span class="chip">🍅 ${t.pomodoro_count||0}</span>
        <div class="spacer" style="flex:1"></div>
        <button data-act="pick">Pick</button>
        <button class="danger" data-act="del">×</button>
      </div>`;
    row.querySelector('[data-act=toggle]').onchange = async e => {
      await api('tasks.update', { id:t.id, title:t.title, card_id:t.card_id, sort_order:t.sort_order, completed:e.target.checked?1:0, pomodoro_count:t.pomodoro_count });
      loadTasks();
    };
    row.querySelector('[data-act=pick]').onclick = () => { state.pomo.currentTask = t; pomoRender(); toast('Picked'); };
    row.querySelector('[data-act=del]').onclick = async () => { await api('tasks.delete',{id:t.id}); loadTasks(); };
    list.appendChild(row);
  });
  // card select
  const sel = $('#newTaskCard'); sel.innerHTML = '<option value="">(no card)</option>';
  const all = await api('cards.list', null, 'per=500');
  all.cards.forEach(c => sel.appendChild(Object.assign(document.createElement('option'),{value:c.id,textContent:c.word})));
}
$('#btnAddTask').onclick = async () => {
  const title = $('#newTaskTitle').value.trim();
  const card_id = +$('#newTaskCard').value || null;
  if (!title && !card_id) return toast('Title or card required');
  await api('tasks.create', { title, card_id, sort_order: state.tasks.length });
  $('#newTaskTitle').value=''; loadTasks();
};

/* ---------- Stopwatch ---------- */
function swRender(){
  const e = state.sw.elapsed + (state.sw.running ? (Date.now()-state.sw.start) : 0);
  const s = (e/1000);
  const m = (s/60)|0, r = s%60;
  $('#swTime').textContent = String(m).padStart(2,'0')+':'+r.toFixed(1).padStart(4,'0');
}
$('#btnStopwatch').onclick = () => openModal('swModal');
$('#swStart').onclick = () => { if (state.sw.running) return; state.sw.running=true; state.sw.start=Date.now(); state.sw.interval=setInterval(swRender,100); };
$('#swPause').onclick = () => { if (!state.sw.running) return; state.sw.elapsed += Date.now()-state.sw.start; state.sw.running=false; clearInterval(state.sw.interval); swRender(); };
$('#swReset').onclick = () => { state.sw.running=false; clearInterval(state.sw.interval); state.sw.elapsed=0; state.sw.laps=[]; swRender(); $('#swLaps').innerHTML=''; };
$('#swLap').onclick = () => {
  const e = state.sw.elapsed + (state.sw.running ? (Date.now()-state.sw.start) : 0);
  state.sw.laps.push(e);
  const s = e/1000, m=(s/60)|0, r=s%60;
  const d = document.createElement('div'); d.className='chip'; d.style.margin='2px';
  d.textContent = 'Lap '+state.sw.laps.length+': '+String(m).padStart(2,'0')+':'+r.toFixed(2).padStart(5,'0');
  $('#swLaps').appendChild(d);
};

/* ---------- Calendar ---------- */
$('#btnCalendar').onclick = () => { $('#tabs .tab').forEach(x=>x.classList.remove('active')); document.querySelector('.tab[data-view=calendar]').classList.add('active'); $('#view-cards').style.display='none'; $('#view-tasks').style.display='none'; $('#view-calendar').style.display=''; renderCalendar(); };
$('#calPrev').onclick = () => { state.cal.cursor.setMonth(state.cal.cursor.getMonth()-1); renderCalendar(); };
$('#calNext').onclick = () => { state.cal.cursor.setMonth(state.cal.cursor.getMonth()+1); renderCalendar(); };
async function renderCalendar(){
  const d = state.cal.cursor;
  const y = d.getFullYear(), m = d.getMonth();
  $('#calTitle').textContent = d.toLocaleString(undefined,{month:'long',year:'numeric'});
  const first = new Date(y,m,1);
  const startDow = first.getDay();
  const daysInMonth = new Date(y,m+1,0).getDate();
  const start = new Date(y,m,1-startDow);
  const end = new Date(start); end.setDate(start.getDate()+42);
  const iso = x => x.toISOString().slice(0,10);
  const j = await api('calendar.range', null, 'start='+iso(start)+'&end='+iso(end));
  const byDay = {};
  j.cards.forEach(c => {
    const days = [];
    if (c.has_date && c.date_value) days.push(c.date_value);
    if (c.has_datetime && c.datetime_value) days.push(c.datetime_value.slice(0,10));
    if (c.calendar_start && c.calendar_end) {
      let cur = new Date(c.calendar_start);
      const last = new Date(c.calendar_end);
      while (cur <= last) { days.push(iso(cur)); cur.setDate(cur.getDate()+1); }
    }
    days.forEach(dd => { (byDay[dd] ||= []).push(c); });
  });
  const grid = $('#calGrid'); grid.innerHTML = '';
  ['S','M','T','W','T','F','S'].forEach(x => { const d=document.createElement('div'); d.className='dow'; d.textContent=x; grid.appendChild(d); });
  const today = iso(new Date());
  for (let i=0;i<42;i++){
    const cur = new Date(start); cur.setDate(start.getDate()+i);
    const key = iso(cur);
    const cell = document.createElement('div'); cell.className='day' + (key===today?' today':'');
    cell.innerHTML = `<div class="num">${cur.getDate()}</div>` +
      (byDay[key]||[]).slice(0,3).map(c=>`<div class="ev" title="${escapeHtml(c.word)}">${escapeHtml(c.word)}</div>`).join('') +
      ((byDay[key]||[]).length>3?`<div class="ev">+${(byDay[key].length-3)} more</div>`:'');
    cell.onclick = () => { $('#cc_start').value = key; $('#cc_end').value = key; openModal('calCardModal'); };
    grid.appendChild(cell);
  }
}
$('#btnNewCalCard').onclick = async () => {
  const all = await api('cards.list', null, 'per=1000');
  const sel = $('#cc_card'); sel.innerHTML='';
  all.cards.forEach(c => sel.appendChild(Object.assign(document.createElement('option'),{value:c.id,textContent:c.word})));
  openModal('calCardModal');
};
$('#cc_save').onclick = async () => {
  const id = +$('#cc_card').value; if (!id) return toast('Pick a card');
  const start = $('#cc_start').value, end = $('#cc_end').value || start;
  const gids = Array.from($('#cc_groups').selectedOptions).map(o=>+o.value);
  const card = (await api('cards.get', null, 'id='+id)).card;
  await api('cards.update', {
    id, word:card.word, five_words:card.five_words, notes:card.notes, group_id:card.group_id,
    has_checkbox:card.has_checkbox, checkbox_value:card.checkbox_value,
    has_counter:card.has_counter, counter_value:card.counter_value,
    has_text:card.has_text, text_value:card.text_value,
    has_list:card.has_list, list_value:card.list_value,
    has_date:1, date_value: start,
    has_datetime:card.has_datetime, datetime_value:card.datetime_value,
    calendar_start:start, calendar_end:end, calendar_group_ids:gids,
  });
  closeModal('calCardModal'); toast('Scheduled'); celebrate(); renderCalendar(); loadCards();
};

/* ---------- Export / Import ---------- */
$('#btnExport').onclick = async () => {
  const j = await api('cards.export');
  const blob = new Blob([JSON.stringify(j,null,2)], {type:'application/json'});
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
  a.download = 'flashdeck-export.json'; a.click();
};
$('#btnImport').onclick = () => {
  const inp = document.createElement('input'); inp.type='file'; inp.accept='application/json';
  inp.onchange = async () => {
    const f = inp.files[0]; if (!f) return;
    const txt = await f.text();
    try {
      const j = JSON.parse(txt);
      const r = await api('cards.import', j);
      toast('Imported '+r.imported+' cards'); celebrate();
      await loadGroups(); await loadCards();
    } catch(e){ toast('Invalid file'); }
  };
  inp.click();
};

/* ---------- Help ---------- */
$('#btnHelp').onclick = () => openModal('helpModal');

/* ---------- Keyboard ---------- */
document.addEventListener('keydown', e => {
  const tag = (e.target.tagName||'').toLowerCase();
  const typing = tag==='input'||tag==='textarea'||tag==='select';
  if (e.key === 'Escape') {
    $$('.modal.open').forEach(m => m.classList.remove('open'));
    $('#carousel').classList.remove('open');
    return;
  }
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k') { e.preventDefault(); openModal('searchModal'); setTimeout(()=>$('#searchInput').focus(),30); return; }
  if (typing) return;
  if ($('#carousel').classList.contains('open')) {
    if (e.key==='ArrowRight'||e.key.toLowerCase()==='d') { state.focusIdx = Math.min(state.cards.length-1, state.focusIdx+1); renderCarousel(); }
    if (e.key==='ArrowLeft'||e.key.toLowerCase()==='a') { state.focusIdx = Math.max(0, state.focusIdx-1); renderCarousel(); }
    if (e.key.toLowerCase()==='e') { $('#carousel').classList.remove('open'); openCardEditor(state.cards[state.focusIdx]); }
    return;
  }
  if (e.key.toLowerCase()==='n') { openCardEditor(); return; }
  if (e.key==='ArrowRight'||e.key.toLowerCase()==='d') { state.focusIdx = Math.min(state.cards.length-1, (state.focusIdx<0?0:state.focusIdx+1)); renderCards(); }
  if (e.key==='ArrowLeft'||e.key.toLowerCase()==='a') { state.focusIdx = Math.max(0, (state.focusIdx<0?0:state.focusIdx-1)); renderCards(); }
  if (e.key==='ArrowDown'||e.key.toLowerCase()==='s') { state.focusIdx = Math.min(state.cards.length-1, (state.focusIdx<0?0:state.focusIdx+3)); renderCards(); }
  if (e.key==='ArrowUp'||e.key.toLowerCase()==='w') { state.focusIdx = Math.max(0, (state.focusIdx<0?0:state.focusIdx-3)); renderCards(); }
  if (e.key==='Enter'||e.key===' ') { if (state.focusIdx>=0) { e.preventDefault(); openCarousel(state.focusIdx); } }
  if (e.key.toLowerCase()==='e' && state.focusIdx>=0) openCardEditor(state.cards[state.focusIdx]);
  if (e.key==='Delete' && state.focusIdx>=0) {
    const c = state.cards[state.focusIdx];
    if (confirm('Delete "'+c.word+'"?')) api('cards.delete',{id:c.id}).then(()=>{ state.focusIdx=-1; loadCards(); loadGroups(); });
  }
});

/* ---------- Onboarding ---------- */
const OB_STEPS = [
  { t:'Welcome to FlashDeck 🎉', b:'A tiny, powerful flashcard app in a single PHP file. Mobile-first, searchable, with groups, pomodoro, calendar, and more.' },
  { t:'Create cards', b:'Click <b>＋ New card</b> or press <span class="kbd">N</span>. Each card has a main word, up to 5 related words, notes, and optional fields (checkbox, counter, text, list, date, datetime).' },
  { t:'Organize with groups', b:'Click 🗂 to create groups. Assign cards to groups, move/copy in bulk, and set a default calendar group.' },
  { t:'Search anything', b:'Press <span class="kbd">Ctrl</span>+<span class="kbd">K</span> to search across words and notes instantly.' },
  { t:'Pomodoro & tasks', b:'Create tasks linked to cards. Work 25 min, break 3 min, long break 15 min every 4 cycles. The top task is auto-picked.' },
  { t:'Calendar', b:'Schedule cards on date ranges. They appear on the calendar view with group colors.' },
  { t:'Keyboard is king', b:'<span class="kbd">←</span> <span class="kbd">→</span> <span class="kbd">W</span> <span class="kbd">A</span> <span class="kbd">S</span> <span class="kbd">D</span> navigate. <span class="kbd">Enter</span> opens. <span class="kbd">E</span> edits. <span class="kbd">Del</span> deletes.' },
  { t:'You\'re ready!', b:'Click <b>Let\'s go</b>. You can reopen this tour from the help menu. Data lives in <code>flashdeck.sqlite</code> next to this file.' },
];
function runOnboarding(){
  let i = 0;
  const steps = $('#obSteps');
  const render = () => {
    steps.innerHTML = '';
    OB_STEPS.forEach((_,k)=>{ const d=document.createElement('div'); d.className='step'+(k<=i?' active':''); steps.appendChild(d); });
    $('#obTitle').textContent = OB_STEPS[i].t;
    $('#obBody').innerHTML = OB_STEPS[i].b;
    $('#obNext').textContent = i===OB_STEPS.length-1 ? "Let's go" : 'Next';
  };
  render();
  $('#obNext').onclick = () => { if (i<OB_STEPS.length-1) { i++; render(); } else { finish(); } };
  $('#obSkip').onclick = finish;
  function finish(){ localStorage.setItem('fd_onboard_v1','1'); closeModal('onboardModal'); celebrate(); }
  openModal('onboardModal');
}

/* ---------- Utils ---------- */
function escapeHtml(s){ return String(s??'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

/* ---------- Boot ---------- */
(async () => {
  await loadGroups();
  await loadCards();
  pomoRender();
  swRender();
  if (!state.onboardSeen) runOnboarding();
})();
</script>
</body>
</html>