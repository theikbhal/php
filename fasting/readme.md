# 🌙 Sunnah Fasting (Saum) Tracker

A minimalist, single-file PHP application paired with SQLite for logging and planning Sunnah fasting goals with a Twitter/X-style feed interface.

## ✨ Features

- **Single-File Architecture:** Entire frontend, backend, database migrations, and styling contained inside a single `index.php`.
- **SQLite Database:** Automatically creates `fasting_tracker.sqlite` upon first initialization—no manual SQL import required.
- **Goal & Target Analytics:**
  - **10 Days Goal / Month Target** counter.
  - **Minimum Pass Threshold (1 fast/month)** indicator.
  - **Weekly Average Speedometer** calculation.
- **Calendar Month View:** Interactive overview with status indicators (completed vs. planned).
- **Fasting Categories:**
  - Mon / Thu Sunnah Fasting
  - 13, 14, 15 Lunar Days (*Ayyam al-Bidh*)
  - Farz / Qaza Fasting
  - Voluntary / Other
- **Twitter-Style Feed:** Timeline layout displaying status, Sehri & Iftar times, notes, and Ikhlas ratings.
- **Spiritual Reflection:** Tracking tools for *Ikhlas* (Sincerity), *Taqwa* (Mindfulness), *Tawakkul* (Trust), and *Tawajjuh* (Focus).
- **Dark/Light Mode:** Toggleable theme with local storage retention.

## 🚀 Quickstart

1. Clone or copy `index.php` into your web server directory.
2. Ensure PHP and `pdo_sqlite` extension are enabled.
3. Start local development server:
   ```bash
   php -S localhost:8000