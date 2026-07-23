# Minimalistic PHP Multi-Counter App

A lightweight, single-file PHP counter dashboard backed by an SQLite database. Supports creating multiple named counters with custom start values, increment/decrement, dynamic reset, target tracking, and count up/down modes.

---

## 👥 Multi-Role Stakeholder Value & UI/UX Design Intent

This application was structured to solve key friction points across various tech roles:

* **Product Manager (PM):** 
  * High feature-density in a zero-bloat architecture.
  * Delivers target reach indicators to track goal completion metrics out-of-the-box.
* **UI/UX Designer:** 
  * Dark mode design using accessible dark slate colors (`#0f172a`, `#1e293b`).
  * High-contrast visual hierarchy (monospace-like large count values, target indicators).
  * Responsive flex and grid layout designed for desktop and mobile touch targets.
* **QA / Testers:** 
  * Standard HTML semantics (`<button>`, `<form>`, `<label>`).
  * Explicit UI state updates on click for instant verification.
  * Single file state isolation makes reproducing bugs predictable.
* **Automation & DevOps Engineers:** 
  * Zero build tools, zero node_modules, zero external dependencies.
  * Auto-creates SQLite table on initial request (`counters.db`).
  * Easy end-to-end (E2E) testing compatibility using deterministic DOM selectors (`#val-{id}`, `.btn-inc`).

---

## 🚀 Quick Start

### Prerequisites
* PHP 7.4 or higher
* `php-sqlite3` driver enabled

### Running locally

1. Place `index.php` in any folder.
2. Open terminal in that directory and start built-in server:
   ```bash
   php -S localhost:8000