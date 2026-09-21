-- Additive migration for: linking meeting stakeholders to real user
-- accounts, replacing the old free-text-only design.
-- Run instead of (or in addition to) migration_round5_stakeholder_email.sql
-- if you haven't applied that one yet — this includes its email column too.

ALTER TABLE meeting_stakeholders
    ADD COLUMN email VARCHAR(255) NULL AFTER stakeholder_name,
    ADD COLUMN user_id INT NULL AFTER email,
    ADD CONSTRAINT fk_meeting_stakeholders_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Optional cleanup: if you already ran the previous migration and have
-- fake department-alias emails seeded (legal@sjdm.gov.ph etc.), you can
-- clear them out so they don't look like real, testable addresses:
-- UPDATE meeting_stakeholders SET email = NULL WHERE email LIKE '%@sjdm.gov.ph';
