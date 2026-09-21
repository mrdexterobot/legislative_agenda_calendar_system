# Deploying to a real domain

Plain PHP + MySQL, no build step and no Composer, so any shared host with
PHP 8.0+ and MySQL/MariaDB runs it — Hostinger, HostForge, InfinityFree, a
small VPS. Roughly thirty minutes end to end.

## 1. Upload

Put the contents of the project folder — not the folder itself — into the
host's web root (`public_html/` on cPanel-style hosts). File Manager → Upload
a zip → Extract is faster and safer than FTP for a few hundred small files.

Directory layout must be preserved: `api/`, `admin/`, `includes/`, `js/`,
`css/`, `database/`, `uploads/` all sit beside `index.php`.

## 2. Create the database

Control panel → MySQL Databases → create a database and a user, and **grant
that user all privileges on that database**. Hosts prefix both names
(`u123456_legislative`), which is why `root`/blank from XAMPP will not work.

Open phpMyAdmin from the panel, select the new database, Import →
`database/schema.sql` → Go. Then import, in order:

1. `database/migration_add_deadline_assignment.sql`
2. `database/migration_add_evidence_and_session_completion.sql`
3. `database/migration_round6_stakeholder_user_link.sql`
4. `database/migration_round7_security.sql`

`schema.sql` already contains everything up to round 6, so on a **fresh**
import you only need `schema.sql` and round 7. Run the earlier ones only if
you are upgrading a database that already holds data.

## 3. Configure

Copy `includes/config.sample.php` to `includes/config.php` and fill in:

- the DB name, user, and password the host issued
- `GROQ_API_KEY` (regenerate it if it has ever been pasted anywhere)
- the three Brevo SMTP values — see the comments in that file
- `APP_SECRET` — any long random string
- `APP_IS_LOCAL` → `false`

`APP_IS_LOCAL = false` marks the session cookie `Secure`, so **HTTPS has to be
working before you flip it**, or nobody can log in.

## 4. HTTPS

Panel → SSL → issue a free Let's Encrypt certificate for the domain, then
enable "Force HTTPS". Wait for it to say Active — issuance takes a few
minutes and needs DNS pointed at the host already.

Once `https://yourdomain.com` loads with a padlock, uncomment the HSTS line in
`.htaccess`. Leave it commented until then: a browser that receives HSTS over a
broken certificate will refuse to load the site over plain HTTP afterwards,
and that is painful to undo.

This is also the item the audit checklist calls TLS encryption. TLS 1.3
specifically is the host's setting, not the application's — most panels expose
it under SSL/TLS configuration, and a screenshot of that panel is the evidence
to attach.

## 5. Permissions

`uploads/evidence/` must be writable by PHP (755 is normally enough; 775 if
uploads fail). Confirm `uploads/evidence/.htaccess` uploaded with it — that
file is what blocks direct browser access to evidence attachments, leaving
`api/evidence/download.php` as the only path to them, which is where the
permission check lives.

## 6. Verify, in this order

1. `https://yourdomain.com/includes/config.php` → must print
   "Direct access not permitted." If it prints PHP source or a blank page,
   stop and fix that before anything else.
2. Sign in as `admin`. **Change both seeded passwords immediately**, and
   change both seeded email addresses to real ones.
3. Admin → Email Delivery Test → send to yourself. Fix any error it reports
   before moving on; every other mail flow depends on this one working.
4. Forgot password, using a real account's address. The code should land in
   that inbox.
5. Priority Setting → Generate AI suggestion, confirming the Groq key works
   from the host (some hosts block outbound HTTPS on shared plans — if the
   call times out, that is what happened, and the module's error path shows
   "please assess manually" rather than breaking the page).
6. Schedule a session, complete the Meeting Coordination checklist, send
   notifications. Confirm arrival in the inboxes.

## 7. If mail works locally but not on the host

Shared hosts sometimes block outbound port 587 to force use of their own mail
service. Symptom: "Could not open a connection to smtp-relay.brevo.com:587".
Set `SMTP_PORT` to `2525` in `includes/config.php`; Brevo accepts it and it is
blocked far less often. If both are blocked, the host's own SMTP server works
too — swap `SMTP_HOST`, `SMTP_USERNAME`, and `SMTP_PASSWORD` for the mailbox
credentials the panel gives you, and set `SMTP_FROM_EMAIL` to that mailbox.

## 8. Do not upload

`includes/config.php` from your local machine (different DB credentials),
`includes/debug_last_ai_response.php`, `includes/debug_last_email.php`, and
`.git/`. All four are already in `.gitignore`; the debug files regenerate
themselves on the server when needed.
