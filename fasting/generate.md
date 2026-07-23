---

## 3. Generator Prompt

```text
Build a single-file PHP application (`index.php`) backed by SQLite (`fasting_tracker.sqlite`) for tracking Sunnah fasting (Saum) with a minimalist Twitter/X feed UI style.

Requirements:
1. Target System Goals:
   - Target Goal: 10 fasts per month.
   - Minimum Pass Threshold: 1 fast per month.
   - Calculated weekly average (~1 per week target).

2. Fasting Types Supported:
   - Monday Sunnah Fast
   - Thursday Sunnah Fast
   - 13, 14, 15 Islamic Calendar / Lunar Month (Ayyam al-Bidh / White Days)
   - Farz / Qaza
   - Voluntary / Other

3. Feature Set:
   - Minimalist Twitter-style card feed layout with dark mode / light mode toggle.
   - Interactive calendar grid overview showing logged and planned dates.
   - Ability to add today's fast or plan a future fast date.
   - Optional fields for Sehri time, Iftar time, spiritual reflection notes, and Ikhlas star rating (1-5 scale).
   - Celebration badge on completed entries.
   - Auto-creating SQLite schema on first load without external CSS or JS library dependencies.

   https://gemini.google.com/app/d1f3d4e1c8cd4f69