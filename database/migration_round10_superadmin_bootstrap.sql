-- One-time local bootstrap for an existing installation.
-- Run this only after confirming the seeded `admin` account is the account
-- you control. It promotes that account and keeps MFA enabled.
ALTER TABLE users
MODIFY role ENUM('superadmin', 'admin', 'staff') NOT NULL DEFAULT 'staff';

UPDATE users
SET role = 'superadmin', mfa_enabled = 1
WHERE username = 'admin' AND is_active = 1;
