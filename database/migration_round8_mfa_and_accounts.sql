-- ============================================================================
-- Migration round 8 — multi-factor authentication, username change requests,
-- and a repair for the table that was silently breaking forgot-password.
--
-- Safe to run on a database with data. Run once; re-running errors on the
-- duplicate columns, which is harmless.
-- phpMyAdmin: select the database -> SQL tab -> paste -> Go.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 0. THE FORGOT-PASSWORD REPAIR — read this first
--
-- Admin -> Email Delivery Test working while forgot-password sends nothing
-- has one overwhelmingly likely cause: password_resets does not exist in this
-- database. It was introduced in migration_add_email_and_account_requests.sql,
-- and if schema.sql was imported before that migration existed (or the
-- migration was skipped), the INSERT throws, the catch block in
-- api/auth/forgot-password.php swallows it, and the endpoint returns its
-- deliberately generic "if an account matched, a code was sent" — with no
-- code and no email. The test page never touches this table, which is why it
-- succeeds.
--
-- IF NOT EXISTS means this is harmless if the table is already there.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    code_hash  VARCHAR(64) NOT NULL,
    attempts   INT NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Multi-factor authentication (audit checklist, Section 2 — CRITICAL)
--
-- Second factor is a six-digit code emailed to the address on the account.
-- Email is the only channel this system can actually deliver on: there is no
-- SMS gateway budget, and a TOTP authenticator app would need enrolment with
-- a QR code plus recovery codes, which is more surface than a five-account
-- office needs. Because the code is sent to the same mailbox that can also
-- reset the password, this raises the bar against a stolen or guessed
-- password, not against an attacker who already controls the mailbox — state
-- it that way if a panelist asks, rather than claiming more than it does.
--
-- mfa_enabled is per-account and admin-managed. Admin accounts are treated as
-- required regardless, via MFA_REQUIRED_FOR_ADMINS in includes/config.php.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- Admin accounts default to MFA on.
UPDATE users SET mfa_enabled = 1 WHERE role IN ('admin', 'superadmin');

-- One live code per user at a time; a new request deletes the previous unused
-- row. `attempts` caps guessing within the code's ten-minute window, the same
-- way password_resets.attempts does for reset codes.
CREATE TABLE IF NOT EXISTS mfa_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    code_hash  VARCHAR(64) NOT NULL,   -- SHA-256 of the code, never the code
    attempts   INT NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 1b. Trusted-device sessions
--
-- The browser receives only a random opaque token. The hash is stored in the
-- database and expires automatically, so the token can be invalidated server
-- side without weakening the MFA requirement for other devices.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trusted_devices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    token_hash    CHAR(64) NOT NULL UNIQUE,
    expires_at    TIMESTAMP NOT NULL,
    last_used_at  TIMESTAMP NULL,
    revoked_at    TIMESTAMP NULL,
    ip_address    VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trusted_devices_user_active (user_id, revoked_at, expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 2. Username in account-update requests
--
-- Staff could already request a name or email change; the username, which is
-- what they actually sign in with, was the one field with no path to change
-- it at all short of an admin editing the row directly.
-- ---------------------------------------------------------------------------
ALTER TABLE account_requests
    ADD COLUMN requested_username VARCHAR(50) NULL AFTER request_type;

-- ---------------------------------------------------------------------------
-- 3. AFTER RUNNING THIS
--
-- Every admin account now requires an emailed code at sign-in. Before you log
-- out, confirm the admin account's email is an inbox you can open
-- (Admin -> Manage User Accounts -> Edit). If mail delivery breaks later and
-- an admin is locked out, the escape hatch is to set
-- MFA_REQUIRED_FOR_ADMINS to false in includes/config.php, or run:
--     UPDATE users SET mfa_enabled = 0 WHERE username = 'admin';
-- ---------------------------------------------------------------------------
