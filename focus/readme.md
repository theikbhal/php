# Focus

A minimalist **single-file PHP + SQLite** website for tracking your current focus.

No framework. No dependencies. Mobile-first. Dark mode.

---

# Features

* Single `index.php` file
* SQLite database (auto-created)
* Mobile-first UI
* Dark mode
* Minimalist design
* Current focus
* Focus history
* Celebration animation
* Responsive
* Zero configuration

---

# Requirements

* PHP 8.0+
* SQLite3 extension enabled

Verify PHP:

```bash
php -v
```

Verify SQLite:

```bash
php -m | grep sqlite
```

Expected output:

```
sqlite3
```

---

# Project Structure

```
focus/
└── index.php
```

After the first run:

```
focus/
├── index.php
└── focus.db
```

---

# Installation

Create a project folder.

```bash
mkdir focus
```

Go into the folder.

```bash
cd focus
```

Create the PHP file.

```bash
touch index.php
```

Paste the source code into `index.php`.

---

# Run Locally

Start the PHP development server.

```bash
php -S localhost:8000
```

Open your browser.

```
http://localhost:8000
```

The database is created automatically on the first visit.

---

# Database

SQLite file:

```
focus.db
```

Table:

```sql
focus_history
```

Columns:

| Column     | Type     |
| ---------- | -------- |
| id         | INTEGER  |
| focus      | TEXT     |
| created_at | DATETIME |

---

# Usage

1. Open the website.
2. Enter your current focus.
3. Click **Set Focus**.
4. A celebration message appears.
5. The newest focus becomes the current focus.
6. Older focuses remain in the history.

Example:

```
Current Focus

Build Landing Page
```

History

```
Deploy API
Write README
Fix Login
Buy Domain
```

---

# Development

Start local server:

```bash
php -S localhost:8000
```

Stop server:

```
Ctrl + C
```

---

# Deployment

Upload only:

```
index.php
```

to any PHP hosting with SQLite enabled.

The application automatically creates:

```
focus.db
```

No configuration is required.

---

# Technology

* PHP
* SQLite
* HTML
* CSS
* JavaScript

No frameworks.

No libraries.

No Composer.

No npm.

No build step.

---

# Future Ideas

* Daily streak
* Pomodoro timer
* Focus categories
* Search
* Export CSV
* Export Markdown
* PWA support
* Keyboard shortcuts
* Notes for each focus
* Edit/Delete focus
* Statistics dashboard
* Dark/Light theme toggle

---

# License

MIT License
