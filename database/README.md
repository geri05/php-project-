## 🚀 1. Getting Started
To run this project locally, you must ensure your environment is compatible with **Cloud PostgreSQL (NeonDB)**.

### Prerequisites
* **Enable PostgreSQL:** In your `php.ini`, uncomment `extension=pdo_pgsql` and `extension=pgsql`.
* **SSL Mode:** The connection requires SSL. This is already handled in `db.php`.
* **Restart Server:** Always restart WAMP/XAMPP after changing `.ini` settings.

---

## 🔐 2. Environment Setup
The project uses environment variables to keep credentials secure.
1. Create a `.env` file in the root directory.
2. Fill in the credentials provided by the admin (Host, User, Password, Port).

---

## 📊 3. Database Architecture
For specific details, refer to the following files in this folder:
* **[`database_setup.sql`](./database_setup.sql):** Full DDL for creating tables and initial data.
* **[`er_diagram.png`](./er_diagram.png):** Visual representation of entities and relationships.
* **[`db.php`](./db.php):** Centralized PDO connection instance.

---

## 🔄 4. Collaboration Workflow
* **Database Changes:** Update `database_setup.sql` and notify the team for any schema changes.
* **Secure Coding:** Use the `$pdo` instance with **prepared statements** for all queries.
* **Git Policy:** Never commit your `.env` file (protected by `.gitignore`).