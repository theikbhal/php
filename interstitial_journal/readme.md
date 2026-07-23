# Interstitial Journal

> A minimal, dark-mode, mobile-first interstitial journaling app built for ADHD brains. Single file, zero dependencies, SQLite-backed.

## What is Interstitial Journaling?

Interstitial journaling is the practice of writing 2-4 sentences between tasks throughout your day. Each entry is timestamped and captures:
1. What you just finished
2. How you feel (optional)
3. What's next

It was coined by Tony Stubblebine (2017) and is widely regarded as one of the best productivity methods for ADHD because it requires no pre-planning, no maintenance, and the trigger is external (task transitions).

## Why This App?

Most productivity tools fail ADHD users because they add cognitive overhead. This app is designed with these principles:

- **Zero friction**: Open → Type → Done. Auto-timestamped. No folders, no tags, no templates.
- **Mobile-first**: Works perfectly on your phone. One-handed use.
- **Dark mode by default**: Reduces eye strain and sensory overload.
- **Single file**: `index.php` is the entire app. SQLite database auto-created.
- **No signup, no cloud**: Privacy-first. Your data stays on your device/server.
- **Append-only**: No editing old entries. This prevents the "perfecting" trap.

## Features

| Feature | Why It Matters for ADHD |
|---------|------------------------|
| Auto-timestamp every entry | Eliminates time-blindness; no manual typing |
| One continuous stream | No "where does this go" decision fatigue |
| One-tap mood/energy tags | Quick emotional state capture without typing |
| Daily summary view | Pattern recognition for time blindness |
| Export to Markdown | Portable, future-proof data |
| Keyboard-first input | `Enter` to submit, minimal mouse use |
| No notifications | No dopamine hijacking or distraction |
| Instant load | No spinners, no waiting |

## Installation

### Option 1: PHP Built-in Server (Local)

```bash
cd interstitial-journal
php -S localhost:8080
# Open http://localhost:8080
```

### Option 2: Any Shared Hosting

Upload `index.php` to any PHP-enabled web host. SQLite will auto-create `journal.db`.

### Option 3: Docker

```bash
docker run -p 8080:80 -v $(pwd):/var/www/html php:8.2-apache
```

## Tech Stack

- **Backend**: PHP 8.1+ (no frameworks)
- **Database**: SQLite3 (auto-migrated)
- **Frontend**: Vanilla CSS/JS (no build step, no CDN dependencies)
- **Design**: Mobile-first, dark mode, responsive

## File Structure

```
interstitial-journal/
├── index.php      # The entire application
├── journal.db     # Auto-created SQLite database
└── README.md      # This file
```

## ADHD Design Decisions

1. **No edit/delete**: Prevents perfectionism spirals and keeps the log honest.
2. **No categories/tags**: Decision fatigue is the enemy. Time is the only organizer.
3. **Large touch targets**: Minimum 44px for all interactive elements.
4. **High contrast text**: WCAG AAA compliant for readability.
5. **No animations**: Reduces cognitive load and motion sensitivity.
6. **Instant feedback**: Entries appear immediately without page reload.
7. **Offline-ready**: Service worker caches the app for use without network.

## Usage

1. Open the app
2. Tap the input field
3. Write 2-4 sentences about your transition
4. Hit Enter (or tap the arrow)
5. See your entry appear with an auto-timestamp

### Example Entries

```
09:14  Finished email to client. Feeling relieved but still worried about the deadline.
       Next: Deep work on the API documentation for 45 min.

10:52  Got distracted by Twitter for 15 min. Caught myself.
       Next: Back to API docs. Phone in another room.

12:30  Lunch break. Energy at 6/10.
       Next: Review PRs after eating.
```

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Enter` | Submit entry |
| `Shift + Enter` | New line in entry |
| `Ctrl + /` | Focus input |
| `Esc` | Blur input |

## Data Export

Click "Export" to download all entries as a Markdown file. Format:

```markdown
# Interstitial Journal Export

## 2026-07-23

**09:14** — Finished email to client. Feeling relieved but still worried about the deadline. Next: Deep work on the API documentation for 45 min.

**10:52** — Got distracted by Twitter for 15 min. Caught myself. Next: Back to API docs. Phone in another room.
```

## Privacy

- No analytics, no tracking, no cookies
- No external requests (all assets inline)
- SQLite database stored locally on your server
- No cloud sync (by design — reduces complexity and trust issues)

## Contributing

This is intentionally minimal. If you want to fork and extend:

- Keep it single-file
- Keep it dependency-free
- Every feature must justify its cognitive cost for ADHD users

## License

MIT — Use it, fork it, host it for yourself or your team.

## Credits

- Method: [Tony Stubblebine](https://betterhumans.pub/replace-your-to-do-list-with-interstitial-journaling-4aac5ad790c4)
- ADHD framing: [Novie by the Sea](https://www.youtube.com/watch?v=UFidZJhxz84)
- Research: Sophie Leroy (Attention Residue, 2009), Di Stefano et al. (Harvard, 2014)