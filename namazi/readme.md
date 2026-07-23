# 🕌 Connect Namaz

A **single-file, mobile-first, dark-mode** tracker for the 5 daily prayers — and for the people who walk the path with you.

Built with **PHP + SQLite**. No frameworks, no npm, no build step. Drop `index.php` on any PHP 7.4+ server and open it.

---

## ✨ What it does

| Area | Feature |
|---|---|
| 🕌 Daily prayers | Tap-to-check Fajr, Dhuhr, Asr, Maghrib, Isha — one note per day |
| 🔥 Streaks | Current + best streak, with celebrations at 7 / 21 / 40 / 100 / 365 |
| 📅 Calendar | GitHub-style heatmap of the month, per-prayer shading |
| 🤝 Connections | Log people you met / talked to / visited — Imam, Parents, Elders, Madrasa students, Regular Namazi |
| 📊 Reports | Lifetime stats, per-prayer completion %, connections by role |
| 💡 Why | In-app explanation of the philosophy |
| ⬇ Export | Full JSON backup of prayers + connections |

---

## 🤔 Why track Namaz?

> *"The first thing a servant will be asked about on the Day of Judgement is his prayer."* — Prophet ﷺ

Tracking is **not** about perfection. It is about:

1. **Awareness** — you cannot improve what you do not measure.
2. **Consistency** — the most beloved deeds to Allah are those done regularly, even if small.
3. **Recovery** — when you slip, the data shows you exactly where and invites you back without shame.
4. **Connection** — prayer ties you to Allah; this app ties you to the *people* who carry the deen with you.

---

## 🤝 Why "Connect"?

Salah is personal. The *ummah* is not. This app adds a second layer of tracking:

- **Imam** — sit with him after Fajr, ask a question, learn.
- **Parents** — a weekly call is sadaqah and silaturrahim.
- **Elders** — visit them; their dua is light.
- **Madrasa students** — check on them; they are carrying a heavy trust.
- **Regular Namazi** — keep righteous company; you become who you sit with.

Every logged connection is a small act of *silaturrahim* — and a reminder that you are not walking alone.

---

## 🚀 How to use

### Install
1. Save the code as `index.php`.
2. Put it in any web-accessible folder (Apache, Nginx, XAMPP, MAMP, Caddy, `php -S localhost:8000`, shared hosting, Termux).
3. Open it in a browser. The SQLite file `connect_namaz.sqlite` is created automatically in the same folder.

> ⚠️ Make sure the folder is **writable** by the web server.

### Daily flow (2 minutes)
1. Open the app 5 times a day.
2. Tap each prayer when done — the tile turns green.
3. Write **one short note** — a dua, a reflection, a reminder.
4. At least once a week, open **Connect** and log one person you met, called, or visited.
5. Check the **Calendar** every week. Protect the streak — but if you break it, restart the same day.

### Milestones to aim for
`7` · `21` · `40` · `100` · `365` — each one unlocks a small celebration in the app.

---

## 🧠 Design philosophy (multi-lens)

| Lens | Decision |
|---|---|
| **Analyst** | Streak + heatmap + per-prayer completion = behavior signal, not just data |
| **Product Manager** | Core loop is 5 taps + 1 note. Secondary loop is 1 connection/week |
| **UI/UX** | Dark, minimal, thumb-friendly. Bottom nav. No onboarding friction |
| **Full-stack** | Single file, zero deps, SQLite WAL mode for concurrent reads |
| **Business/Investor** | Spiritual wellness + community = retention. No ads, no tracking, data stays on device |
| **Tester** | Upsert-by-date prevents duplicates; foreign keys + WAL; XSS-safe output |
| **DevOps** | Backup = copy one `.sqlite` file. Deploy = copy one `.php` file |
| **Muslim user** | No music, no gamification that trivializes worship — just quiet accountability |

---

## 🔒 Privacy

- **100% local.** All data lives in `connect_namaz.sqlite` on your server.
- No analytics, no ads, no external requests.
- Export anytime via **Report → Export JSON**.

---

## 🛠 Roadmap ideas

- [ ] Qibla direction + prayer times (auto from coordinates)
- [ ] Weekly "connection challenge" (e.g. call one parent, visit one elder)
- [ ] PWA install prompt + offline support
- [ ] Shared streaks with a mahram / accountability partner
- [ ] Multi-language (Arabic, Urdu, Bahasa, English)

---

## 🤲 Intention

> *Allahumma a'inni 'ala dhikrika, wa shukrika, wa husni 'ibadatik.*
> "O Allah, help me to remember You, to give You thanks, and to worship You in the best manner."

May this small tool be a means — not the goal — of steadier salah and stronger ties.