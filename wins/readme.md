# 🚀 WinsFeed — Single-File Wins Micro-Journal

A high-dopamine, Twitter-style micro-journaling web application engineered to track victories, fight burnout, and build momentum. Built with single-file PHP and SQLite for maximum portability and zero deployment friction.

![WinsFeed Dark Mode](https://img.shields.sh/badge/Style-Twitter--Dark-amber)
![Stack](https://img.shields.sh/badge/Stack-PHP_8_•_SQLite3_•_Tailwind_CDN-blue)

---

## ✨ Features

- **Twitter-Style Micro-Feed**: Log wins, code milestones, and business wins in a clean, stream-of-consciousness feed.
- **Micro-Dopamine Celebrations**: Automatic full-screen confetti bursts when posting a win, plus micro-confetti interactions on likes.
- **Dynamic CSS Background Themes**: Instantly toggle between **Grid**, **Dots**, and **Waves** dark-mode patterns (persisted via `localStorage`).
- **Full Inline CRUD**: Add, edit content inline, delete, and increment heart counts.
- **Multi-Format Export**: Export your victory log anytime to `.json`, `.md` (Markdown), or `.txt`.
- **Zero Configuration**: Self-contained single PHP file. Automatically initializes a local `wins.db` SQLite database on first launch.

---

## 🛠️ Quickstart

### Prerequisites
- PHP 8.x with the `pdo_sqlite` extension enabled.

### Running Locally
1. Drop `index.php` into your project directory.
2. Start PHP's built-in web server:

```bash
php -S localhost:8000