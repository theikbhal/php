
https://gemini.google.com/app/d1f3d4e1c8cd4f69


Act as a Principal Full-Stack Developer and UI/UX Designer. Build a complete, production-ready, single-file PHP + SQLite web application for "Rejection Therapy" — a habit tool designed to build rejection resilience through daily micro-challenges.

### Key Requirements:
1. Architecture & Tech Stack:
   - Single-file PHP (`index.php`) using native PDO SQLite for persistence.
   - Self-contained HTML, embedded CSS, and JavaScript. Zero external frameworks or npm dependencies.
   - Auto-create the SQLite table `rejection_logs` on initial page load if it doesn't exist.

2. UI & UX Aesthetics:
   - Mobile-first, dark mode aesthetic inspired by Twitter/X.
   - Color Palette: Dark background (#0f1419), card background (#161e27), borders (#2f3336), with high-contrast text and accents (Purple for branding, Red for rejection, Green for accepted/wins, Gold for fear level).
   - Sticky header showing key top-line stats: Total Reps, Rejections (Fails), and Accidental Wins (Accepted).

3. Features & Data Structure:
   - Database Fields: `id`, `attempt_type` (Cold Ask, Sales Pitch, Social Ask, Favor Request, Custom), `target_person`, `outcome` (rejected, accepted, pending), `fear_level` (1-10 slider or input), `note` (request details), `lesson_learned` (reflection), `created_at`.
   - Composer Form: Quick dropdowns, touch-friendly inputs, and a submit button ("Log Rejection Rep").
   - Timeline/Feed: Reverse-chronological Twitter-style feed showing card entries with dynamic outcome badges, target mentions (@target), fear scale indicators, and highlighted reflection/lesson blocks.

4. Output:
   - Output ONLY clean, modern, fully functional, executable PHP code ready to drop into a server directory.