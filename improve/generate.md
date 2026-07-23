## 📄 3. AI Specification File: `GENERATE.md`

```markdown
# 🤖 WinsFeed Pro — Prompt Context

Use this context when extending or refactoring this app with an AI model.

---

### 📝 System Architecture Constraints

```text
You are updating WinsFeed Pro — a zero-dependency, single-file PHP web application ("index.php").

CRITICAL ARCHITECTURAL RULES:
1. SINGLE-FILE MONOLITH: All PHP backend logic, SQLite migration logic, HTML, CSS, JavaScript, and asset libraries (Tailwind CDN, Canvas Confetti) MUST remain in `index.php`.
2. DATABASE MIGRATION: Database is SQLite (`wins.db`). Table `wins` must support auto-migration using PRAGMA checks for columns: `id`, `content`, `category`, `priority`, `timeframe`, `tags`, `likes`, `created_at`.
3. PRIORITY STATES: Support 'urgent', 'important', and 'normal'. Highlight 'urgent' items visually using red accents and subtle borders.
4. TIMEFRAME STATES: Support 'now', 'today', 'week', and 'month'.
5. UI STYLING:
   - Primary Theme: Pure Dark Mode (`#09090b`).
   - Accent Colors: Amber (`#f59e0b`) for standard wins, Red (`#ef4444`) for urgent items.
   - Pattern Themes: Grid, Dots, Waves, Mesh (persisted via `localStorage`).