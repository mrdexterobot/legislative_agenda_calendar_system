# Legislative Agenda & Calendar Management System

A PHP and MySQL application for managing legislative agenda preparation,
priority setting, calendar scheduling, meeting coordination, and deadline
tracking.

This repository is suitable for local development and demonstration. It is
not a production-ready deployment by itself; review the security and hosting
requirements before making an instance publicly accessible.

## Features

- Agenda item creation, readings, prioritization, archiving, and status tracking
- Session calendar and deadline management
- Meeting coordination, stakeholder notifications, and checklists
- Role-based administration and account deactivation
- Multi-factor authentication and password-reset flows
- Evidence upload and controlled download endpoints
- Optional AI-assisted priority and schedule suggestions
- Optional integration endpoints for an external meeting-management system
- Audit logging for important account and record changes

## Requirements

- PHP 8.0 or later
- MySQL 8.0 or compatible MariaDB version
- Apache with URL rewriting enabled, or an equivalent PHP web server
- PHP extensions used by the project, including PDO MySQL and cURL

Docker support is included for a quick local setup. XAMPP is also supported.

## Quick start with Docker

1. Clone this repository.
2. Create a local `.env` file from the variable names documented in
   `includes/config.sample.php`.
3. Replace every placeholder value in `.env`, especially database passwords,
   `APP_SECRET`, API keys, and SMTP credentials.
4. Start the application:

   ```bash
   docker compose up --build
   ```

5. Open `http://localhost:8080`.

The database container imports `database/schema.sql` when its data volume is
created for the first time. To rebuild the database from scratch during local
development, remove only the project Compose volume after confirming that no
data is needed.

Never commit `.env` or any file containing real credentials. The repository's
`.gitignore` excludes `.env` and the runtime PHP configuration.

## Local setup with XAMPP

1. Install XAMPP and start Apache and MySQL.
2. Copy the project into the XAMPP web root, such as
   `C:\xampp\htdocs\legislative-agenda-system\`.
3. Create a database and import `database/schema.sql` using phpMyAdmin.
4. Copy `includes/config.sample.php` to `includes/config.php`.
5. Set the database connection and application settings in
   `includes/config.php`. Keep this file outside version control.
6. Open the project URL in a browser, for example
   `http://localhost/legislative-agenda-system/`.

The schema may contain demonstration records and accounts. Treat them as
local-only seed data: change all passwords and email addresses before using
the application outside a private development machine, and do not publish
those credentials in documentation.

## Configuration

Configuration can be supplied through environment variables or the ignored
`includes/config.php` file. Common settings include:

- `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`
- `APP_SECRET` — a long, randomly generated secret
- `APP_IS_LOCAL` — use `false` only when HTTPS is correctly configured
- `GROQ_API_KEY` and `GROQ_MODEL` — optional AI integration settings
- SMTP settings for email delivery, if notifications are enabled

The AI and email integrations are optional. The core agenda and calendar
features should not require real credentials for basic local development.

## Database changes

For a new local database, import `database/schema.sql`. For an existing
database, use the versioned migration files in `database/` in the documented
order. Do not re-import a schema containing destructive statements into a
database that contains data.

## Deployment checklist

Before deploying to a shared host or public domain:

- Use HTTPS and enable secure session cookies.
- Set unique database credentials and a strong `APP_SECRET`.
- Remove or change all seeded account credentials.
- Keep `includes/config.php`, `.env`, logs, and debug output out of GitHub.
- Confirm that uploaded evidence files cannot be executed or directly
  downloaded without authorization.
- Verify that each administrative and data endpoint enforces authentication,
  authorization, CSRF protection, and server-side validation.
- Configure backups and test restoring them before relying on the system.
- Review `docs/DEPLOYMENT.md` for the hosting procedure and verification steps.

Do not upload real personal data, API keys, SMTP passwords, database
credentials, or production configuration to this repository.

## Known limitations

- Email delivery requires an SMTP provider; without one, notification flows
  are limited to local testing or simulation.
- External subsystem integration is represented by the integration endpoints
  and stub documented in `INTEGRATION.md`.
- Conflict detection is limited to records available inside this system; it
  does not know about private calendars or attendance outside the application.
- AI suggestions are advisory and must be reviewed by an authorized user.

## Project documentation

- [Deployment guide](docs/DEPLOYMENT.md)
- [Integration notes](INTEGRATION.md)
- [Database schema](database/schema.sql)
- [Configuration template](includes/config.sample.php)

## License

No license has been declared yet. Add a `LICENSE` file before distributing
this repository for reuse.
