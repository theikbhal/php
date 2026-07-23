
---

## 🧭 How to Use

### Bases
- On the homepage, create a new base by typing a name and clicking **Create**.
- Click a base to see its tables.

### Tables
- Inside a base, add a table by giving it a name and defining at least one field.
- You can add multiple fields (text, number, date) when creating the table.
- After creation, you can add more fields or delete existing ones.

### Records
- In a table, fill in the fields and click **Add Record** to create a new row.
- Records are displayed in a table; click **View** to see details and edit.
- Edit any field inline on the record page, then click **Update**.

### Comments
- On a record’s detail page, you can add comments with an optional author name.
- All comments are displayed in reverse chronological order.

---

## 🏗️ Architecture (Role Perspectives)

### 🧑‍💼 Product Manager
- **Goal**: Provide a simple yet functional data management tool for non‑technical users.
- **Key decisions**: Minimal feature set (no complex relations, formulas, or views) to keep the UI clean and the code simple. Prioritised mobile responsiveness and dark mode for modern user expectations.

### 🧑‍🎨 UI/UX Designer
- **Dark mode** as default reduces eye strain and fits current design trends.
- **Mobile‑first** ensures usability on small screens; forms and tables adapt seamlessly.
- **Clear visual hierarchy**: Cards separate sections, buttons use consistent colours, and actions are always visible.

### 🏛️ Architect
- **Technology**: PHP (server) + SQLite (database) in a single file for extreme portability.
- **Data model**: Normalised tables with foreign keys (`bases` → `tables` → `fields` & `records` → `comments`).
- **Flexibility**: Fields are stored separately, allowing dynamic schemas per table. Records store field data as JSON, avoiding complex EAV patterns.

### 🔬 Analyst
- **Data integrity**: Foreign key constraints enforce relationships; cascading deletes clean up child records.
- **Audit**: All records have `created_at` timestamps.
- **Queryability**: SQLite allows ad‑hoc queries; the schema is straightforward for reporting.

### 🧪 Tester
- **Test cases**:
  - Create base → table → record → comment.
  - Delete base cascades to tables, records, and comments.
  - Add/delete fields and verify records update correctly.
  - Mobile layout: all elements fit without horizontal scroll.
- **Edge cases**: Empty field names, duplicate names (not enforced), large text inputs.

### ⚙️ Full‑Stack Developer
- **Backend**: PHP handles routing, CRUD operations, and SQLite interactions via PDO.
- **Frontend**: Inline CSS and minimal JavaScript (only for dynamic field addition). No external libraries.
- **API‑like**: All actions are performed via POST forms; can be extended to REST if needed.

### 📡 API Developer
- The current implementation uses form‑based POST requests. A future version could expose a JSON API with the same endpoints.
- Each action (`create_base`, `add_record`, etc.) can be repurposed as API endpoints.

### 💼 Solo Business / Marketer / Sales
- **Value proposition**: Zero‑cost, self‑hosted alternative to Airtable for small teams or personal projects.
- **Marketing angle**: “Your data, your server – no subscriptions, no limits.”
- **Sales pitch**: Perfect for freelancers, startups, and educators who need a lightweight database with a clean interface.

### ✍️ Content Writer
- **Documentation**: This README and inline code comments make onboarding easy.
- **User guide**: The interface is self‑explanatory; tooltips or help text could be added later.

### 🎨 Artist
- The dark theme and minimal design provide a calm, focused workspace. The subtle purple accent adds personality without distraction.

---

## 🔮 Future Improvements

- **User authentication** – multi‑user support with permissions.
- **Advanced field types** – checkbox, select, email, URL.
- **Views** – grid, gallery, calendar.
- **Record search & filtering**.
- **REST API** – expose JSON endpoints for integration.
- **Export/Import** – CSV/JSON.
- **Undo/Redo** – for accidental deletions.
- **Attachment fields** – file uploads.
- **Formula fields** – compute values automatically.
- **Theming** – light mode toggle.

---

## 🛠️ Requirements

- PHP 7.4+ with `pdo_sqlite` extension.
- Web server (Apache, Nginx, or PHP built‑in server).
- Write permissions in the directory for SQLite.

---

## 📄 License

MIT – use it freely, modify it, and share it.

---

## 🤝 Contributing

This is a mini project meant to be a starting point. Feel free to fork and expand. For major changes, please open an issue first to discuss what you would like to change.

---

**Enjoy your mini Airtable!** 🚀