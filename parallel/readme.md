# Parallel Notes

A minimalist, local-first, dark-mode notes application for developers, founders, writers, and anyone who works on multiple projects at once. Think **Obsidian meets a VS Code split editor** — but radically simpler.

No login. No cloud. No accounts. No build step. One PHP file, one SQLite database, zero dependencies.

---

## Features

- **Single-file architecture** — the entire app (database bootstrap, API, HTML, CSS, JS) lives in `index.php`.
- **Auto-created SQLite database** — nothing to configure, just run and go.
- **Two workspace layouts**
  - **Parallel** — two (or more, wrapped) project editors side by side.
  - **Stack** — project editors stacked vertically.
  - Your last-used layout is remembered.
- **Instant client-side search** — filters the project list as you type, matching both title and note contents.
- **Autosave** — every keystroke is debounced 700ms and saved silently in the background, with a `Saving…` → `Saved ✓` indicator in the toolbar.
- **Inline rename** — double-click any project title (sidebar or editor pane) to rename it in place. `Enter` saves, `Esc` cancels.
- **Focus history** — every time you open a project, a timestamped row is recorded in the `history` table, so you can later analyze what you actually worked on.
- **Remembers state** — last opened projects, last selected layout, and last active project all persist across restarts.
- **Fully responsive, mobile-first** — sidebar collapses into an off-canvas drawer on small screens; editors stack automatically.
- **No frameworks, no build tools** — vanilla PHP, vanilla JS, plain CSS. Nothing to `npm install`.
- **Scales comfortably to 1000+ projects** — projects are loaded once, all further requests are lightweight AJAX calls, sidebar rendering is delegated (not per-row listeners), and search is debounced.

---

## Requirements

- PHP **8.0+** with the `pdo_sqlite` extension enabled (bundled by default in virtually all PHP installations).
- No Composer, no npm, no external services.
- Works on Linux, macOS, and Windows.

---

## Installation

1. Download / clone this repository.
2. Make sure the folder containing `index.php` is **writable** by the PHP process (the SQLite database file `notes.db` will be created there automatically on first run).

That's it — there is nothing to install.

---

## Running Locally

From the project directory, start PHP's built-in development server:

```bash
php -S localhost:8000
```

Then open your browser at: