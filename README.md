# Legislative Agenda & Calendar Management System

A working PHP + MySQL backend for the Priority Setting, Calendar Scheduling,
Meeting Coordination, and Deadline Tracking modules — Sangguniang Panlungsod
of San Jose del Monte, Bulacan (capstone build).

**Scope note:** the Executive–Legislative Synchronization module from the
original five-module plan is not included here. There's no automatable
data channel for executive-side status (mayor transmittal/signature/veto)
independent of the Backstopping Committee's manual process, so a dedicated
sync module wouldn't add real automation value over what Deadline Tracking
already does (it still monitors the Sec. 54 mayor's-action window — see
"Where the mayor's-action window lives" below).

---

## 1. Setup for local testing (XAMPP)

1. **Install XAMPP** if you haven't (apachefriends.org) and start **Apache**
   and **MySQL** from the XAMPP Control Panel.
2. **Copy this whole folder** into `C:\xampp\htdocs\` (Windows) or
   `/Applications/XAMPP/htdocs/` (Mac), so you end up with e.g.
   `C:\xampp\htdocs\legislative-agenda-system\`.
3. **Create the database:**
   - Open `http://localhost/phpmyadmin`
   - Click "New", name it `legislative_agenda_system`, click Create
   - Select it, click the "Import" tab, choose `database/schema.sql`, click Go
   - You should see 11 tables created and some sample data inserted
4. **Set up your config file:**
   - Copy `includes/config.sample.php` to `includes/config.php`
   - The defaults (`DB_USER = 'root'`, `DB_PASS = ''`) already match XAMPP's
     out-of-the-box MySQL setup, so you likely don't need to change those
   - Paste your **own, freshly-generated** Groq API key into `GROQ_API_KEY`
     (see the "AI setup" section below — and never paste a real key into a
     chat or commit it to GitHub; regenerate immediately if you ever do)
5. **Open** `http://localhost/legislative-agenda-system/index.php` in your
   browser.

### Default accounts (from the sample data)

| Username  | Password       | Role  |
|-----------|----------------|-------|
| `admin`   | `!` | superadmin |
| `rsantos` | `!` | staff |

**Change these passwords** (or create your own accounts and deactivate
these) before using this anywhere beyond your own machine — see
Admin → Manage User Accounts once logged in as `admin`.

### AI setup (Groq)

1. Get a free API key at `https://console.groq.com` → API Keys.
2. Paste it into `includes/config.php` as `GROQ_API_KEY`.
3. The model is currently `openai/gpt-oss-20b` (see `GROQ_MODEL` in
   config.php) — Groq's actual Llama models were moved off the free tier
   in June 2026. If your thesis title/docs commit specifically to
   "LLaMA-Powered," this is worth revisiting with your group — see the
   note in Chapter 1 alignment below.
4. Free tier limits: 30 requests/min, 1,000 requests/day, 8,000 tokens/min,
   200,000 tokens/day (resets daily). The "Generate AI suggestion" button
   is deliberately click-triggered, not automatic, to stay well within this.

---

## 2. Deploying to real hosting / a domain

This is plain PHP + MySQL, so it runs on almost any shared host (Hostinger,
InfinityFree, etc.) or a small VPS. Broad steps:

1. **Upload the files** via FTP/File Manager to your host's web root
   (often `public_html/` or similar).
2. **Create a MySQL database** through your host's control panel (cPanel,
   etc.) and import `database/schema.sql` the same way as step 3 above —
   most hosts include phpMyAdmin.
3. **Edit `includes/config.php`** with the real DB host/username/password/
   database name your host gave you (usually NOT `root`/blank — hosts
   issue their own credentials).
4. **Enable HTTPS.** Most hosts offer a free Let's Encrypt SSL certificate
   you can turn on from the control panel — do this before real use, since
   login sessions and the AI API key are only meaningfully protected in
   transit over HTTPS. Once it's on, you can uncomment the HSTS header line
   in `.htaccess` and flip `APP_IS_LOCAL` to `false` in `config.php`.
5. **Double-check `.htaccess` is being honored** — visit
   `https://yourdomain.com/includes/config.php` in a browser; it should
   show "Direct access not permitted" (this is enforced in PHP itself, not
   just `.htaccess`, so it should hold even if your host's Apache config
   differs from XAMPP's).

---

## 3. Known simplifications (documented on purpose, not oversights)

- **Email sending is simulated**, not real SMTP — see the comment block at
  the top of `api/meetings/send-notifications.php`. Standing up a real
  mail server is unreliable to test and out of scope for a capstone demo;
  swapping in PHPMailer + real SMTP credentials later wouldn't require
  changing anything else.
- **The "other subsystem" (Session and Legislative Meeting Management
  System) is mocked** — see `INTEGRATION.md`.
- **Agenda items are archived, never hard-deleted**, through the app UI —
  they're official legislative records. `is_archived` hides them from
  normal views but keeps readings/priority history intact.
- **User "deletion" is deactivation**, not a row delete — preserves the
  audit trail (a deactivated account can't log in, but past actions still
  show who did them).
- **Completed sessions can't be edited or deleted** — they're historical
  records once a session has actually happened.
- **Conflict detection checks venue, committee, and presiding-officer
  overlaps within our own sessions table only** — not individual
  councilors' personal calendars (we have no visibility into that, and
  live attendance/quorum tracking is a different subsystem's scope).

## 4. Chapter 1 alignment still needed

Two of your thesis document versions commit to "LLaMA-Powered" in the
title with Meta AI citations; others (Chapter1_revised, the definition-of-
terms doc) use generic "cloud-based LLM" language and note the specific
provider "will be selected during development." Since Groq's free/developer
tier no longer serves actual Llama models (moved to their own gpt-oss
models in June 2026), this is worth a group decision: either chase a
provider that still serves real Llama access for free, or align everyone
on the generic-LLM framing that's already technically accurate and doesn't
need revisiting if the provider changes again later.
