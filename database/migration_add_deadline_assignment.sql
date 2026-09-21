-- Additive migration — run this instead of re-importing schema.sql if you
-- already have data in your `deadlines` table you don't want to lose.
-- Safe to run once; re-running will error on the duplicate column (harmless).

ALTER TABLE deadlines
    ADD COLUMN assigned_to_user_id INT NULL AFTER completion_notes,
    ADD COLUMN assigned_to_name    VARCHAR(255) NULL AFTER assigned_to_user_id,
    ADD CONSTRAINT fk_deadlines_assigned_to
        FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL;
