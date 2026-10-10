# CloudDesk Web

The website version of the CloudDesk IT helpdesk (same features as the Android app):
tickets, SLA tracking, AI priority, comments, history, ratings, attachments, chat,
analytics, performance reports, CSV export, user management, FAQ, notifications,
settings, 4 languages (English, Sepedi, Tshivenda, Xitsonga), dark/light themes,
Google sign-in, passkeys (fingerprint/face), forgot-password by security question.

## Run it on XAMPP / WAMP
1. Copy this folder to `C:\xampp\htdocs\cloudDeskWeb`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Open http://localhost/cloudDeskWeb/ — the database, tables and demo data are created automatically on first visit.

Demo logins (password `password123`):
- Admin: admin@clouddesk.com
- Technician: john.tech@clouddesk.com
- User: grace@company.com
- Security-question answer for all demo accounts: `blue`

## Optional settings
Copy `config/config.local.example.php` to `config/config.local.php` and fill in:
- `GEMINI_API_KEY` — Gemini answers in chat for admin/technician only. Users get the default replies.
- `GOOGLE_CLIENT_ID` — a **Web application** OAuth client with authorized JavaScript origin `http://localhost`.
- `DB_USER` / `DB_PASS` if your MySQL is not root with no password.
- `CURL_CA_BUNDLE` if Gemini calls fail with an SSL error on Windows.

Never share your API key or commit `config.local.php`.

## Notes
- Email sending uses PHP `mail()`, which needs a mail server configured.
- Passkeys need `localhost` or https.
- The website database is separate from the Android app's database.
- Self-registration always creates a `user`; admins promote people in the Users page.
- Deploy to Render: set `DATABASE_URL` (Postgres) and use the included Dockerfile.
