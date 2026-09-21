-- Additive migration for: user email, forgot-password codes, and staff
-- account requests. Run alongside the earlier migration files if you
-- haven't applied those yet.

-- Add email as nullable first (existing rows have none), backfill a
-- placeholder so the column can then be made required, then tighten it.
-- Update the placeholder addresses to real ones afterward.
ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER full_name;
UPDATE users SET email = CONCAT(username, '@sjdm.gov.ph') WHERE email IS NULL;
ALTER TABLE users MODIFY COLUMN email VARCHAR(255) NOT NULL UNIQUE;

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

CREATE TABLE IF NOT EXISTS account_requests (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT NOT NULL,
    requested_by         VARCHAR(255) NOT NULL,
    request_type         ENUM('info_update', 'deactivation') NOT NULL,
    requested_full_name  VARCHAR(150) NULL,
    requested_email      VARCHAR(255) NULL,
    reason               VARCHAR(500) NOT NULL,
    status               ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_notes          VARCHAR(500) NULL,
    reviewed_by          VARCHAR(255) NULL,
    reviewed_at          TIMESTAMP NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
