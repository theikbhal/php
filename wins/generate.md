# 🤖 WinsFeed Prompt Context File

Use the prompt context below when passing this codebase to an AI assistant (LLM) for features, refactoring, or extensions.

---

### 📝 System Context & Architecture Constraints

```text
You are working on WinsFeed — a minimalist, zero-dependency, single-file web application ("index.php") designed as a Twitter-style wins journal.

CRITICAL ARCHITECTURAL RULES:
1. SINGLE-FILE MONOLITH: All code (PHP PDO logic, HTML, CSS, JavaScript, and database initialization) MUST remain entirely inside `index.php`. Do not split into modular files or create subdirectories.
2. ZERO BUILD TOOLS: Rely strictly on PHP 8.x native capabilities and CDN scripts (Tailwind CSS CDN, Canvas Confetti). Do NOT introduce Composer dependencies, NPM packages, or build steps.
3. SQLITE PERSISTENCE: Use native PDO with SQLite (`wins.db`). Table schema includes: `id`, `content`, `category`, `likes`, and `created_at`.
4. UX & DESIGN DIRECTION:
   - Primary Theme: Pure dark mode (`#09090b` background) with high-contrast text and warm amber accents (`#f59e0b`).
   - Layout: Twitter/X micro-feed timeline layout (`max-w-2xl`).
   - Feedback Loops: Maintain celebration interactions (Canvas Confetti on win creation and post reactions).
5. DATA EXPORTABILITY: Retain the built-in HTTP export handlers (`?export=json`, `?export=md`, `?export=txt`).


https://gemini.google.com/app/a5827a0366c40eeb