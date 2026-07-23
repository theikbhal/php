---

## Full Generation Prompt (For Next Time)

Here is the reusable prompt to instantly recreate or expand this exact app in any AI tool:

```text
Act as a Senior Full-Stack Engineer. Build a production-ready, single-file PHP + SQLite application called "Holding Hand" — a distraction-parking tool for side ideas and sudden thoughts during deep work.

Requirements:
1. Architecture: Single-file PHP (`index.php`) backed by native PDO SQLite (`holding_hand.sqlite`). Auto-create tables on launch.
2. UI/UX: Twitter/X style feed, dark mode default with a toggleable light mode (saved in localStorage). Mobile-first, responsive.
3. Fields: Category (Side Idea, Sudden Thought, Micro Task, Custom), Custom Category string, Idea text, Target Pomodoro duration (default 15 mins), Status (parked, harvested, discarded), Timestamp.
4. Core Workflow: Capture side ideas in under 5 seconds, park them on "Holding Hand", and convert them into 15-minute Pomodoro blocks when ready.


https://gemini.google.com/app/d1f3d4e1c8cd4f69