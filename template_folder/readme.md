# PHP CLI & Web Dashboard Setup

Minimalist single-stack toolkit providing both CLI and Web-based workflow helpers with built-in SQLite persistence.

## Files

- **`cli.php`**: Command-line interface for subfolder creation and duplicate validation.
- **`index.php`**: Single-file dark mode web interface with folder generator and SQLite CRUD.
- **`app.sqlite`**: Automatically initialized SQLite database file.

---

## Usage Instructions

### 1. Running from Terminal (CLI Mode)
To create new website subfolders interactively via terminal:
```bash
php cli.php