---

### 2. `generate.md`

```markdown
# 🤖 Master Generator Prompt

Use this prompt with any LLM to rebuild, extend, or generate new single-file PHP tools following this exact architectural pattern.

---

### **Prompt Template**

```text
Act as a Principal Full-Stack Engineer and UX Specialist. 

Generate a complete, self-contained `index.php` file for a minimalist, single-file web application using native PHP and SQLite (PDO).

#### Requirements & Constraints:
1. Architecture:
   - Must be contained entirely within a single `index.php` file (PHP logic, SQLite schema setup, HTML, CSS, and JS combined).
   - Zero external backend dependencies or NPM packages.
   - Database: Auto-create an `app.sqlite` file in the same directory using native PDO if it doesn't exist.

2. Design & UI/UX Aesthetic:
   - Dark mode default (`#0b0e14` background) with a subtle CSS grid pattern.
   - Minimalist, Twitter/GitHub card-based UI layout with clean typography and rounded borders.
   - Responsive mobile-first layout (max-width container ~720px).

3. Core Features:
   - Full CRUD support (Add, Edit, Inline Toggle/Status update, Delete).
   - Categorization & Tagging (Urgent, Organized, Unorganized, Extra).
   - Priority Levels (Top 1 Pick, Top 2, Top 3, Top 5, Top 10).
   - Timeframe tagging (Now, Today, This Week, This Month).
   - Instant Search & Filter Navigation Tabs.

4. Gamification & Delight:
   - Interactive "Pick Urgent Task" button that randomly selects a high-priority pending task.
   - Canvas Confetti integration (loaded via CDN) triggering on task completion and task selection.

5. Code Quality:
   - Output ONLY clean, executable PHP/HTML/CSS code.
   - Ensure properly escaped string outputs using `htmlspecialchars()`.
   - Prevent any syntax errors, double escaping, or broken backslashes.