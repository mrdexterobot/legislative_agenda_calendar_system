-- Round 9 — allow existing evidence tables to store Mayor-action documents.
-- Fresh databases already receive this value from database/schema.sql.

ALTER TABLE evidence_attachments
    MODIFY COLUMN entity_type ENUM('deadline', 'session', 'agenda_item') NOT NULL;
