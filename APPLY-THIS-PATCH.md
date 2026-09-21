# Patch: email delivery, account lockout, session timeout, UI cleanup

Drop-in replacement files. The folder structure here mirrors the project, so
extract this archive **over** your project folder and let it overwrite. No file
outside this list is touched.

> **Before anything else — revoke your Brevo SMTP key.** The key currently in
> `includes/config.php` (`xsmtpsib-18de87d9…`) has been pasted into a chat
> transcript, which means it is no longer secret. Anyone holding it can send
> mail as your verified sender. Brevo → SMTP & API → SMTP → delete that key,
> generate a new one, paste the new value into `includes/config.php` only.
> `.gitignore` already excludes that file, so it never reaches GitHub.

---

## 1. Run the migration first

`database/migration_round7_security.sql`

Adds the lockout columns, deletes the invented `@sjdm.gov.ph` stakeholder
rows, and backfills every existing meeting's notification list from real user
accounts. Run it in phpMyAdmin (SQL tab → paste → Go) before loading the app,
otherwise `api/auth/login.php` falls back to the old no-lockout behaviour and
writes a notice to the PHP error log.

Step 4 of that file is a manual step you have to do: the seeded accounts carry
`admin@sjdm.gov.ph` and `rsantos@sjdm.gov.ph`, which are not real mailboxes.
Change them to addresses you can actually open, in Admin → Manage User
Accounts.

## 2. Files in this patch

| File | What changed |
|---|---|
| `includes/mailer.php` | Validates every SMTP setting before opening a socket; translates 535/501/550 rejections into an instruction; never swallows a failure silently |
| `includes/auth.php` | Idle session timeout (`SESSION_IDLE_MINUTES`, default 60) enforced in one place for both pages and API endpoints |
| `includes/config.sample.php` | Spells out which Brevo value goes in which constant; adds `SESSION_IDLE_MINUTES` |
| `api/auth/login.php` | Account lockout: 5 failed attempts → 15-minute lock, checked before the password comparison |
| `api/auth/forgot-password.php` | Response stays deliberately generic, but the real send outcome now lands in the audit log and the error log |
| `api/admin/test-email.php` | New. Admin-only SMTP diagnostic that returns the raw relay response |
| `api/sessions/create.php` | A new session's meeting starts with every active account on the notification list, linked by `user_id` |
| `admin/email-test.php` | New. Admin → Email Delivery Test page |
| `admin/index.php` | Adds the card linking to that page |
| `index.php` | Default-credentials box removed; show/hide password; lockout message surfaces |
| `forgot-password.php` | Show/hide password; the "email sending is simulated" line is gone (it isn't) |
| `js/password-toggle.js` | New. Self-attaching show/hide control for every password field, including ones rendered into modals later |
| `database/migration_round7_security.sql` | New migration described above |
| `docs/DEPLOYMENT.md` | Shared-hosting deployment walkthrough |

`profile.php` and `admin/manage-users.php` get the password toggle for free
**only if** they load the new script. Add this one line to each, just above
the closing `</body>`:

```html
<script src="js/password-toggle.js"></script>        <!-- profile.php -->
<script src="../js/password-toggle.js"></script>     <!-- admin/manage-users.php -->
```

## 3. Why the email was failing

Three separate things were stacked on top of each other, which is why fixing
one at a time never produced a visible change:

1. **`SMTP_FROM_EMAIL` was still the placeholder string** in the version of
   `includes/config.php` in the project. Brevo rejects
   `MAIL FROM:<PASTE_YOUR_VERIFIED_BREVO_SENDER_EMAIL_HERE>` at the protocol
   level. The old mailer only checked `SMTP_USERNAME` for a placeholder, so it
   opened the connection and failed halfway through.
2. **Nothing displayed the failure.** `forgot-password.php` returns the same
   generic message whether or not an account exists — correct, deliberate, and
   the reason it also "succeeds" for an address that was never registered. But
   it meant a rejected send looked identical to a delivered one.
3. **`openssl` disabled in XAMPP** makes STARTTLS impossible and, in the
   original code, crashed the request outright rather than returning JSON —
   that is the "localhost says something went wrong" alert.

After applying this patch, go to **Admin → Email Delivery Test**, enter your
own address, and send. That page reports the actual SMTP response, so you get
one clear answer instead of silence.

### A note on the "it accepted an email that isn't registered" behaviour

That is not a bug, and it should stay. If the form said "no such account," the
page becomes a tool for discovering which usernames and email addresses belong
to the Sanggunian — worth saying out loud during the defense, because a
panelist may read it as a defect otherwise.

## 4. Stakeholders / "None recorded"

You were right that the old list was nonsense. `councilors@sjdm.gov.ph` and
`legal@sjdm.gov.ph` were invented; no mailbox exists at either, so a send
against them proves nothing.

The system has no separate "councilor" account type — `users` holds admin and
staff, and those are the only rows with a verified address. So the
notification list is now built from exactly that: when a session is scheduled,
every active account is added to its meeting, name and email read live from
the account rather than typed. Admins can remove anyone irrelevant to a
specific hearing and can still add external contacts (a councilor's personal
address, the Mayor's office) through **+ Add stakeholder**.

Practical consequence for your demo: create two or three accounts with real
addresses you control, schedule a session, complete the checklist, send. Every
one of those inboxes receives the notice — which is the thing a panel actually
wants to see happen.

## 5. Still not implemented, and the honest answer for each

- **MFA** — not built. The defensible answer is scope: the system is used by a
  handful of office accounts on an internal deployment, and lockout plus bcrypt
  plus HTTPS covers the realistic threat. Say it as a decision, not an
  oversight.
- **AES-256 at rest** — not implemented, and retrofitting it onto a live
  `users` table hours before a defense is the wrong risk. The correct technical
  point, and one panelists respect: passwords must be *hashed* (bcrypt), not
  encrypted — reversible encryption of passwords would be a weakening, not a
  strengthening. What legitimately warrants encryption at rest here is very
  little: ordinances and session calendars are public records by law.
- **SAST/DAST** — no tooling run. If you have an hour, `vimeo/psalm` or
  PHPStan level 1 over `includes/` and `api/` gives you a report to attach.
- **Report export (PDF/CSV)** — not built; Sections 4–5 of the checklist are
  largely N/A for this subsystem and marking them N/A with a one-line reason
  reads better than a rushed half-feature.
