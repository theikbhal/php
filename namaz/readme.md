# 🌙 Minimalist Single-File Namaz Tracker

> A mobile-first, dark-mode daily prayer tracker designed to turn spiritual intent into consistent habit loops. Built as a zero-dependency single-file PHP app backed by SQLite.

---

## 📌 Executive Summary & Stakeholder Perspectives

This project balances lightweight technical execution with habit-building product strategies:

* **Product & Habit Design:** Solves the consistency gap by utilizing micro-feedback loops. Completing all 5 daily Faraz prayers increments a persistent streak meter and updates the monthly consistency heatmap.
* **UI/UX Strategy:** Minimalist, OLED dark mode design (`#0F172A` palette) engineered specifically to eliminate eye strain during late-night (Isha) and early-morning (Fajr) check-ins.
* **Architecture & Dev Ops:** Zero build steps, zero npm/composer dependencies, and zero maintenance overhead. A single `index.php` handles everything—routing, database schema auto-migrations, server-side processing, and UI rendering.
* **Business & Efficiency:** Runs on minimal hardware (a $2.50/month VPS, local NAS, or Raspberry Pi) with near-zero resource consumption. Data ownership remains 100% private and portable.

---

## 🎯 Strategic Context

### Why Track?
Habits thrive on visibility. Tracking turns intangible spiritual goals into measurable consistency without relying on memory or fleeting motivation.

### Why Namaz?
Namaz (Salah) serves as a daily spiritual anchor—five structured moments throughout the day to pause, reset mental focus, and recalibrate priorities.

### How It Works
1. **Daily Check:** Log Faraz, Sunnah, Nafl, and Du'a for each prayer with optional contextual notes.
2. **Build Streaks:** Retain momentum as daily 5/5 Faraz completions feed your active streak.
3. **Analyze & Reflect:** Monitor monthly performance via grid-based completion heatmaps and summary metrics.

---

## ✨ Features

* 📱 **Mobile-First Responsive Layout:** Designed for quick, single-hand touch interactions.
* 🌙 **OLED Dark Mode Aesthetics:** High contrast, minimal visual noise, and subtle accent lighting using Tailwind CSS.
* 🔥 **Dynamic Streak Counter:** Tracks consecutive days where all 5 Faraz prayers are completed.
* 📊 **Monthly Consistency Grid:** Heatmap visualization showing prayer intensity across the month.
* 📝 **Flexible Prayer Logging:** Granular checkable options for **Faraz**, **Sunnah**, **Nafl**, and **Du'a**, along with custom reflection notes per prayer.
* 💾 **Portable Database:** Built-in SQLite PDO auto-initialization.

---

## 🚀 Quick Start

### System Requirements
* PHP 7.4 or higher
* PHP PDO with SQLite3 extension enabled (`php-sqlite3`)

### Installation & Execution

1. **Clone or Copy:** Place `index.php` into your web server's public directory (e.g., Apache, Nginx, or cPanel public_html).
2. **Run Locally (Alternative):** Navigate to the folder containing `index.php` and run PHP's built-in web server:
   ```bash
   php -S localhost:8000