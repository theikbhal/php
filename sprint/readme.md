# SprintPulse Studio - Single File Sprint & Pomodoro Workstation

SprintPulse Studio is a zero-dependency, single-file PHP application for agile daily sprint planning and Pomodoro focus management.

## Key Features
* **Auto-Naming Sprints**: Uses 2-word combinations (Adjective + Animal/Fruit/Plant) with auto-incrementing IDs.
* **Flexible Durations**: Sprints default to 2 hours, but support 30m, 40m, or 1h slots.
* **Pomodoro Engine**: 15m/3m default ratio, with quick presets for 25/5, 50/10, and 140/20.
* **Mindful Break Rituals**: Native prompts for Wudu, Namaz, Zikir, eye-palming, and short walks.
* **SQLite Backend**: Embedded local SQLite database (`sprint_pomo.sqlite`) initialized automatically.

## How to Run
```bash
# Start PHP built-in server on port 8021
php -S localhost:8021 index.php

# Or run on port 8022
php -S localhost:8022 index.php