# Unblocked — Obstacle Recovery & Resourcefulness Engine

A lightweight, single-file PHP & SQLite web application built to convert technical, financial, skill, energy, and time blockers into actionable recovery plans using gamified reflection.

---

## 🎯 Why Log Blockers?

When faced with obstacles (lack of money, missing skills, low energy, technical bugs), the default reaction is often paralysis or frustration. **Unblocked** changes the framework from panic to analytical problem-solving:

1. **Articulate the Problem:** Explicitly stating what is blocking you reduces cognitive overwhelm.
2. **Identify Hope & Leverage:** Reframes constraints by forcing you to answer *why there is still hope* (e.g., free tools, alternative routes, existing networks).
3. **Micro-Action Recovery:** Breaks recovery down into an immediate step small enough to overcome momentum friction.
4. **Gamified Progress:** Earn XP for analyzing problems and bonus XP for resolving them to build a habit of resilience.

---

## 🛠 Features

* **Blocker Categorization:** Tag issues by Cashflow, Technical, Skill Lack, Energy, Time, or Custom parameters.
* **Hope & Action Framework:** Dedicated inputs for finding leverage and defining immediate micro-actions.
* **Gamification Engine:** Real-time XP tracking and level progression based on reflection quality and resolution rates.
* **Dual-Theme Support:** Dark mode and Light mode toggle with instant persistence via `localStorage`.
* **Zero Dependencies:** Single file (`index.php`) backed by local SQLite persistence (`unblock_tracker.sqlite`).

---

## ⚡ Deployment & Setup

### Requirements
* **PHP 7.4+**
* `pdo_sqlite` extension enabled

### Quickstart
1. Place `index.php` into your server root or web directory.
2. Set folder permissions so PHP can write the SQLite file:
   ```bash
   chmod 775 /path/to/your/folder