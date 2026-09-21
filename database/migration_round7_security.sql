-- ============================================================================
-- Migration round 7 — account lockout + removal of invented stakeholder data
--
-- Additive and safe to run on a database that already has data. Run it once;
-- re-running will error on the duplicate columns, which is harmless.
--
-- phpMyAdmin: select your database -> SQL tab -> paste -> Go.
-- CLI:  mysql -u USER -p DBNAME < database/migration_round7_security.sql
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Account lockout (audit checklist, Section 2)
--
-- The counter has to survive across requests from an attacker who discards
-- cookies between attempts, so it lives on the user row rather than in the
-- session. api/auth/login.php locks an account for 15 minutes after 5
-- consecutive failures and clears both columns on any successful sign-in.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN locked_until          TIMESTAMP NULL         AFTER failed_login_attempts;

-- ---------------------------------------------------------------------------
-- 2. Remove the invented department-alias stakeholders
--
-- Rows such as "All councilors <councilors@sjdm.gov.ph>" and "City Legal
-- Office <legal@sjdm.gov.ph>" were seed placeholders. No mailbox exists at
-- those addresses, so a real send either bounces or silently goes nowhere,
-- and they made the notification list look populated when it was not.
-- ---------------------------------------------------------------------------
DELETE FROM meeting_stakeholders
WHERE email IS NULL
   OR email LIKE '%@sjdm.gov.ph';

-- ---------------------------------------------------------------------------
-- 3. Backfill every existing meeting's notification list from real accounts
--
-- New sessions get this automatically (api/sessions/create.php). This covers
-- meetings that already existed before that change. Only active accounts with
-- an email are included, and user_id links each row back to the account so
-- the name and address are read from the real record rather than stored by
-- hand.
-- ---------------------------------------------------------------------------
INSERT INTO meeting_stakeholders (meeting_id, stakeholder_name, email, user_id)
SELECT m.id, u.full_name, u.email, u.id
FROM meetings m
CROSS JOIN users u
WHERE u.is_active = 1
  AND u.email IS NOT NULL
  AND u.email <> ''
  AND NOT EXISTS (
      SELECT 1 FROM meeting_stakeholders s
      WHERE s.meeting_id = m.id AND s.user_id = u.id
  );

-- ---------------------------------------------------------------------------
-- 4. REQUIRED MANUAL STEP — replace the sample account addresses
--
-- The seeded accounts carry placeholder addresses (admin@sjdm.gov.ph,
-- rsantos@sjdm.gov.ph) that cannot receive mail either. Because step 3 copies
-- users.email into the notification list, notifications will go nowhere until
-- these are real. Either edit them in Admin -> Manage User Accounts, or run
-- the statements below with real addresses substituted in, then re-run step 3.
--
--   UPDATE users SET email = 'your.real.address@gmail.com' WHERE username = 'admin';
--   UPDATE users SET email = 'secretary.real@gmail.com'     WHERE username = 'rsantos';
--   DELETE FROM meeting_stakeholders WHERE email LIKE '%@sjdm.gov.ph';
--   -- then re-run the INSERT ... SELECT in step 3
-- ---------------------------------------------------------------------------
