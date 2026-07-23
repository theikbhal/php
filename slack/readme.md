# Slack Mini

A single-file, self-hosted, dark-mode Slack clone. PHP + SQLite. Zero dependencies.

![Dark mode](https://img.shields.io/badge/theme-dark-1a1d21)
![Single file](https://img.shields.io/badge/files-1-blue)
![Dependencies](https://img.shields.io/badge/deps-0-brightgreen)

## ✨ Features

**Channels**
- Create / delete channels
- Sidebar with active-channel highlight
- Names auto-normalized (lowercase, dashes)

**Messages**
- Post messages with your display name
- Inline edit (✎) with Enter-to-save, Esc-to-cancel
- Delete with confirmation
- "(edited)" indicator + timestamps
- Auto-scroll to newest message

**Keyboard-first UX**
- `Ctrl`/`Cmd` + `K` — jump-to-channel modal with fuzzy search
- `↑` `↓` to navigate, `Enter` to open, `Esc` to close
- `Enter` to send, `Shift`+`Enter` for newline
- Auto-resizing composer

**Polish**
- Slack-inspired dark theme
- Avatars with gradient + initial
- Toast notifications
- Empty states for channels & messages
- Persistent user name (session)
- Mobile-friendly (collapses sidebar < 700px)

## 🚀 Install

### Option 1 — PHP built-in server (fastest)
```bash
# Drop index.php in a folder, then:
php -S localhost:8000
# Open http://localhost:8000
```

### Option 2 — Apache / Nginx / any PHP host
Just drop `index.php` into your web root. SQLite file `slackmini.db` is auto-created next to it.

### Option 3 — Docker
```bash
docker run -d -p 8080:80 -v $(pwd):/var/www/html php:8.2-apache
```

## 🎯 Usage

1. Open the app — you'll see the welcome screen.
2. Click **Create channel** (or the `＋` in the sidebar).
3. Start typing messages. Press `Enter` to send.
4. Press `Ctrl+K` anytime to jump between channels.
5. Hover any message to edit or delete.
6. Click the `✎` next to your name in the sidebar to change your display name.

## 🧠 Design Decisions

| Concern | Decision |
|---|---|
| **Storage** | SQLite — zero config, single file, portable |
| **Architecture** | Single file — easy to audit, deploy, fork |
| **Rendering** | Server-rendered HTML + minimal JS fetch — fast, SEO-friendly, no framework lock-in |
| **UX** | Keyboard-first — power users never touch the mouse |
| **Security** | PDO prepared statements, `htmlspecialchars` on all output, `PRAGMA foreign_keys = ON` |
| **Privacy** | Fully self-hosted — your data never leaves your server |
| **Scale** | Good for small teams (≤ ~50 active users, ≤ 100k messages). For more, swap SQLite for MySQL/Postgres — schema is standard. |

## 📐 Schema

```sql
channels(id, name UNIQUE, created_at)
messages(id, channel_id FK, author, content, created_at, edited_at)
```

## 🔒 Security Notes

- No authentication (intentional — pair with HTTP basic auth or a reverse proxy for production).
- All DB writes use prepared statements.
- All HTML output is escaped.
- Channel names are sanitized to `[a-z0-9_-]`.

## 🧪 Ideas for extension

- [ ] File uploads (drag & drop)
- [ ] @mentions + notifications
- [ ] Threads / replies
- [ ] Emoji reactions
- [ ] Markdown rendering
- [ ] User auth (login / register)
- [ ] WebSocket for real-time updates
- [ ] Export channel to JSON / CSV
- [ ] Search messages (add to Ctrl+K)

## 📜 License

MIT — do whatever you want. Build on it, sell it, ship it.

## 🙏 Built with

- PHP 7.4+
- SQLite3 (PDO)
- Vanilla JS
- Zero npm packages. Zero build step. Zero excuses.