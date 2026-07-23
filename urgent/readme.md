# ⚡ Execution & Focus Dashboard

A single-file, zero-dependency PHP + SQLite productivity engine designed for rapid task capture, priority ranking, and gamified execution.

## 🚀 Features

* **Categorized Lists**: Separate tasks into *Urgent*, *Organized*, *Unorganized*, and *Extra* lists.
* **Priority Ranking**: Tag items as *Top 1 Pick*, *Top 2 Mark*, *Top 3*, *Top 5*, or *Top 10 Mark*.
* **Timeframe Assignments**: Assign tasks to *Now*, *Today*, *This Week*, or *This Month*.
* **Gamified Pick Mechanism**: Instant "Pick Urgent Task" modal to break analysis paralysis and highlight a single action item.
* **Micro-Celebrations**: Trigger confetti animations on task completion using `canvas-confetti`.
* **Dark Mode UI**: Clean, Twitter-style card feed with a subtle grid pattern background.
* **Search & Filter**: Instant filtering by task category, timeframes, or keyword search.

## 🛠️ Stack & Requirements

* **Language**: PHP 7.4+ or PHP 8.x
* **Database**: SQLite3 (Native PDO driver)
* **Frontend**: Vanilla CSS & JavaScript (Embedded Canvas Confetti)
* **Dependencies**: None (Runs completely standalone)

## 🏃 Quick Start

Run the built-in PHP server from your terminal:

```bash
php -S localhost:8000