# Patch round 8 — MFA, trusted devices, account management, notification fixes

Extract over your project folder. Paths already match. **Apply the round 7
patch first** if you have not — this one builds on the lockout columns and
`js/password-toggle.js` from it.

---

## Step 1 — run the migration

`database/migration_round8_mfa_and_accounts.sql`

## Step 2 — add three settings to `includes/config.php`

Your live config is gitignored, so the patch cannot edit it. Paste these in:

```php
define('SESSION_IDLE_MINUTES', 60);
define('MFA_REQUIRED_FOR_ADMINS', true);
define('AUTH_SHOW_ACCOUNT_LOOKUP', true);
```

All three have safe built-in defaults, so nothing breaks if you skip this —
but `MFA_REQUIRED_FOR_ADMINS` is the escape hatch if mail ever breaks while
an admin is locked behind a code, and you want to know where it lives before
you need it.

## Step 3 — set the admin email before signing out

Admin → Manage User Accounts → Edit → set the address to the inbox you
actually use. After the migration, admin accounts need an emailed code at
sign-in, and an account with a dead address cannot receive one.

---

## Why forgot-password sent nothing while the test page worked

`password_resets` almost certainly does not exist in your database. It was
introduced by `migration_add_email_and_account_requests.sql`; if `schema.sql`
was imported without it, the `INSERT` threw, the old catch block swallowed the
exception, and the endpoint returned its cheerful generic "if an account
matched, a code has been sent" — with no code and no email. The test page
never touches that table, which is exactly why it succeeded while this failed.

The migration creates it with `IF NOT EXISTS`, so it is harmless either way.
And the endpoint no longer hides a database failure behind a success message:
it now names the missing table outright.

## The lookup behaviour you asked for, and what it costs

The old flow deliberately gave the same answer whether or not an account
existed, so it could not be used to discover who holds one. You asked for real
feedback, and for this system that is the right call — accounts are issued by
an administrator to a known handful of office staff, so there is no user list
an outsider could learn that they could not get by asking at the counter, and
the silence is what made a typo indistinguishable from a broken mailer.

So it now tells you: no such account, account deactivated, no email on file,
or the actual SMTP error when a send fails. Set `AUTH_SHOW_ACCOUNT_LOOKUP` to
`false` if a panelist wants the stricter posture demonstrated — one constant,
no code changes. Worth knowing the tradeoff by name, because "why does your
reset page confirm which usernames exist" is a fair question and "deliberate,
here is the switch" is a much better answer than not having considered it.

Also added: a resend control on both the reset step and the sign-in code step,
with a 30-second cooldown so nobody can hammer an inbox or burn through the
Brevo daily quota by holding the button.

## MFA — what was built and what it actually protects

Second factor is a six-digit code emailed to the address on the account.
Admin accounts require it; staff accounts can be switched on individually in
the admin edit modal.

Email is the only channel this system can really deliver on — there is no SMS
gateway budget, and TOTP would need QR enrolment plus recovery codes, which is
more machinery than a five-account office needs. Be precise about what it buys
when asked: because the code goes to the same mailbox that can also reset the
password, it raises the bar against a stolen or guessed **password**, not
against someone who already controls the mailbox. Claiming more than that is
the thing a sharp panelist catches.

Flow: `login.php` verifies the password and, for an MFA account, sets only
`$_SESSION['mfa_pending']` — no signed-in session exists until
`verify-mfa.php` accepts the code, so every page and API guard still treats
the visitor as logged out in between. The pending user id lives in the
server-side session, never in the request body, or anyone could post someone
else's id and grind codes against an account they never authenticated to.
Codes expire in 10 minutes, allow 5 attempts, and a new one invalidates the
old.

After a successful code verification, staff may select **Remember this device
for 30 days**. The browser receives an HttpOnly, SameSite cookie containing a
random token; only its hash is stored in `trusted_devices`. The password is
still required on every sign-in, and the MFA code is still required on any
browser without a valid unexpired token.

## Admin account editing

The admin screen previously offered exactly two actions: create and
deactivate. Everything else was a dead end that ended in "edit the database
directly" — a deactivated account could never be reactivated, a locked-out
account had to sit out fifteen minutes, a mistyped username was permanent, and
there was no way to hand somebody a new password after they lost their mailbox.

Now editable, each with its own guard: username (format + uniqueness), full
name, email (uniqueness), role (last-admin protection), password (policy),
MFA, active state (cannot deactivate yourself or the last admin), and a
one-click **Unlock** on a locked-out account.

## Meeting Coordination

Two things that did not make sense from a user's point of view:

**Adding someone after the announcement went out did nothing.** The bulk send
is once-per-meeting by design, so anyone added afterwards sat on a list that
would never be sent again — silently, in exactly the case where it matters
most (a resource person invited two days before a hearing). Adding a person to
an already-announced meeting now emails them the session details immediately,
and the response says whether it landed.

**There was no way to reach one person.** A councilor says the notice never
arrived, or an address gets corrected. The only route was undo-and-resend to
everyone, re-announcing the meeting to the whole list to reach one person.
Each name now has a send button beside it.

The notice itself is built in one place (`includes/meeting_notice.php`) and
carries the session label, date, time, venue, presiding officer, and the
attached agenda items with their confirmed priorities — previously it was four
lines with no agenda at all, which is not a notice anyone could act on.

**One loophole fixed while in there:** the bulk send marked the meeting as
notified even when every single delivery failed, which then locked the send
button behind the already-sent gate with nobody actually notified. It now only
records the send if at least one message was accepted.

## Other fixes in this round

- **Password policy** (`includes/password_policy.php`) — one validator shared
  by account creation, admin password reset, self-service change, and the
  forgot-password flow. Previously each of those four enforced its own "8
  characters" rule independently. Rejects the seeded `ChangeMe123!` outright.
- **Changing your password to the same password** now says so instead of
  silently succeeding, which used to leave people believing they had rotated a
  credential they had not.
- **A completed password reset clears any lockout** — the person just proved
  control of the mailbox, so holding the lock only punishes the real owner.
- **Username changes** can be requested from the profile page and approved by
  an admin, with uniqueness re-checked at approval (another account may have
  taken it in the meantime) and a warning on the approve button, since
  approving changes what that person signs in with.

## Files in this patch

```
database/migration_round8_mfa_and_accounts.sql
includes/config.sample.php        includes/mfa.php
includes/password_policy.php      includes/meeting_notice.php
api/auth/login.php                api/auth/verify-mfa.php       api/auth/forget-device.php
api/auth/resend-mfa.php           api/auth/forgot-password.php
api/auth/reset-password.php       api/auth/change-password.php
api/users/create.php              api/users/update.php           api/users/list.php
api/meetings/add-stakeholder.php  api/meetings/notify-stakeholder.php
api/meetings/send-notifications.php
api/account-requests/create.php   api/account-requests/resolve.php
admin/manage-users.php            admin/admin-users.js           admin/admin-account-requests.js
index.php                         forgot-password.php            profile.php
js/meeting-coordination.js        js/profile.js
```

## Test order

1. Admin → Email Delivery Test (should still pass).
2. Sign out, sign in as admin → a code should arrive → verify.
3. Forgot password with a bogus address → "No active account is registered
   with that username or email address."
4. Forgot password with your real account → code arrives → reset works.
5. Get an account locked on purpose (5 wrong passwords), then unlock it from
   the admin screen.
6. Schedule a session, complete the checklist, send notifications, then add
   yourself as an external contact — the catch-up notice should arrive without
   you touching anything else.

## Still not implemented

Database encryption at rest, SAST/DAST scan output, and report export. The
honest positions on each are in the round 7 patch notes; nothing about this
round changes them.
