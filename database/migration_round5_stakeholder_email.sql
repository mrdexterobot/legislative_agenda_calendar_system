-- Additive migration for: real SMTP support (stakeholder email addresses).
-- meeting_stakeholders previously stored only a label, never an address.

ALTER TABLE meeting_stakeholders ADD COLUMN email VARCHAR(255) NULL AFTER stakeholder_name;
