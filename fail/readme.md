# BounceBack — Failure & Reflection Tracker

A minimal, single-file PHP and SQLite application with a Twitter-style feed designed to log failures, track spiritual progression (Namaaz/Duwa), and analyze personal bounce-back speed.

---

##  Why Track Failures?

> *"Failure is simply the opportunity to begin again, this time more intelligently."* — Henry Ford

Most productivity applications focus purely on successful streaks, which creates toxic pressure and avoidance behavior. Tracking failure directly transforms setbacks into raw data.

1. **Eliminates Stigma:** Normalizes mistakes by giving them an immediate output channel.
2. **Accelerates Root-Cause Analysis:** Capturing what failed while it is fresh reveals systemic patterns (e.g., fatigue, lack of structure, missed daily routines).
3. **Builds a Bounce-Back Ledger:** Logging how you overcome each failure creates a personal playbook for handling future obstacles.

---

## ☪ Spiritual Anchor (Namaaz & Reflection)

Integrating prayer (Faraz, Sunnat, Nafil, Duwa) alongside habit tracking aligns daily discipline with personal reflection:
* **Faraz (Compulsory):** Non-negotiable foundation for structure.
* **Sunath & Nafil (Voluntary):** Indicators of surplus energy and spiritual momentum.
* **Duwa (Supplication):** Intentional space for humility, focus, and mindset alignment.

---

## ⚡ Deployment & Quickstart

### Prerequisites
* Any web server with **PHP 7.4+** installed.
* **PHP PDO SQLite** extension enabled (`php-sqlite3`).

### Setup Instructions
1. Place `index.php` in your web server directory (e.g., `/var/www/html/` or your local server folder).
2. Ensure directory write permissions so PHP can create `tracker.sqlite`:
   ```bash
   chmod 775 /path/to/your/folder