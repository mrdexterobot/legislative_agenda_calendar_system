# Integration readiness — what's real, what's mocked, and why

This capstone's pre-oral defense stage doesn't require live integration
with the other nine subsystems (per your instructor's ~60% requirement at
this stage). This document exists so that's demonstrable to a panel: the
**interface contracts are fully designed, coded, and testable**, even
though no other group's system is actually being called over the network.

## Outbound: sending our proposed schedule to their system

**Real code, mocked network call.**

- `api/sessions/create.php` runs real conflict detection against our own
  data (venue/committee/presiding-officer overlaps — see
  `includes/conflict_detection.php`), then calls
  `sendProposedScheduleToSessionSystem()` in
  `includes/integration/session_mgmt_stub.php`.
- That function is clearly commented as a MOCK. It returns a fake
  "confirmed" response immediately instead of making an HTTP request to a
  real peer system (because none exists yet).
- **To make this real later:** replace the body of that one function with
  an actual `curl_init()` call to the peer system's real endpoint. Nothing
  else in the codebase needs to change — `api/sessions/create.php` just
  calls the function and uses whatever it returns, the same as today.

## Inbound: them confirming back to us

**Fully coded, but nothing calls it yet.**

- `api/integration/receive-confirmation.php` is a real, working, token-
  protected endpoint — the URL a real peer system would `POST` to once
  they've validated attendee availability and agenda readiness on their
  end.
- It's not wired into the live flow because the mock in
  `session_mgmt_stub.php` already returns a synchronous "confirmed"
  response, so there's nothing to wait for yet. Once a real peer system
  exists, the outbound call would get an immediate "received, will
  validate" acknowledgement instead of an instant confirmation, and *this*
  endpoint would be what unblocks `meetings.attendees_confirmed` / `agenda_confirmed`
  sometime later.

## Outbound (the other direction): letting peer systems pull our data

- `api/integration/export-agenda-items.php` is a real, working, read-only
  endpoint that returns confirmed (never AI-suggested-only) agenda item
  data — for something like a Records Management or Ordinance Lifecycle
  group to consume.
- Authenticated with a bearer token, not a login session — see
  `includes/integration_auth.php`. Only the SHA-256 hash of each token is
  stored; the raw value is shown once, at creation, in Admin → Integration
  Access.
- This is the "exposed but access-controlled" data surface — nothing about
  our legislative data is reachable without a valid, revocable token.

## What "encrypted" means here, honestly

Nothing in this PHP code can force data to be encrypted *in transit* on
its own — that's HTTPS/TLS, a hosting-level setting, not something
application code can guarantee (see `README.md` → "Deploying to real
hosting" for how to turn it on). What the code *does* guarantee: password
hashing (bcrypt), token hashing (SHA-256, never storing raw tokens),
prepared statements everywhere (no SQL injection), and CSRF protection on
every state-changing request.
