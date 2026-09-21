-- Additive migration for: session completion evidence + evidence_attachments
-- table (deadline/session file uploads). Run alongside
-- migration_add_deadline_assignment.sql if you haven't applied that one yet.
-- Safe to run once; re-running will error on duplicate objects (harmless).

ALTER TABLE sessions
    ADD COLUMN completion_notes VARCHAR(500) NULL AFTER status,
    ADD COLUMN completed_by     VARCHAR(255) NULL AFTER completion_notes,
    ADD COLUMN completed_at     TIMESTAMP NULL AFTER completed_by;

CREATE TABLE IF NOT EXISTS evidence_attachments (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    entity_type       ENUM('deadline', 'session') NOT NULL,
    entity_id         VARCHAR(20) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename   VARCHAR(255) NOT NULL,
    file_size         INT NOT NULL,
    mime_type         VARCHAR(100) NULL,
    uploaded_by       VARCHAR(255) NOT NULL,
    uploaded_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
