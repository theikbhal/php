# PHP Site Manager (single-file edition)

Everything — SQLite bootstrap, JSON API, and UI (HTML/CSS/JS) — lives in
one file: **`index.php`**. No dependencies beyond PHP itself.

## Requirements
- PHP 7.4+ (8.x recommended) with the `pdo_sqlite` extension

```bash
php -v
php -m | grep -i sqlite   # confirm pdo_sqlite is enabled
```

## Run it

```bash
php -S 127.0.0.1:8888
```

Open **http://127.0.0.1:8888**. On first load it creates `data/sites.db`
(SQLite) automatically — nothing else to set up.

## How the single file works

- Any request to `index.php` **with** an `?action=...` param (or POST
  `action`) is treated as the JSON API — `list`, `add`, `edit`, `delete`,
  `start`, `stop`, `status`, `scan`, `find_port` — and returns JSON.
- Any request **without** `action` renders the full HTML page (CSS and JS
  are inlined in `<style>`/`<script>` tags in the same file).
- The frontend JS calls back into `index.php?action=...` for everything,
  so the whole app is one self-contained file plus its SQLite data file.

## Features
- Track local PHP projects: name, folder path, port, description
- Auto-assigns a free port (8000–8999) if you leave it blank
- Start/stop spawns/kills `php -S 127.0.0.1:<port> -t <path>` and tracks the real PID
- Live status polling (every 5s)
- **Scan folder** auto-detects projects (folders with `index.php`, `composer.json`, or `.htaccess`)
- Search, edit, delete
- Minimalist dark UI, no build step, no JS framework

## Notes
- Must run on the same machine as the projects it manages (it starts local
  processes and reads local folders).
- No authentication — intended for local dev use only, not for exposing
  on a shared network or the public internet.
- Deleting a site stops its server first, then removes the DB row. It
  never touches files on disk.
- Edit `PORT_RANGE_START` / `PORT_RANGE_END` near the top of `index.php`
  to change the port range. Edit `scanFolder()` to change scan heuristics.