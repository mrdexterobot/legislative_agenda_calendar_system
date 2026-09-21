-- ============================================================================
-- Legislative Agenda and Calendar Management System
-- Database schema + sample seed data
-- Sangguniang Panlungsod of San Jose del Monte, Bulacan (capstone draft)
--
-- SCOPE NOTE: This schema covers the four modules currently in active scope
-- — Priority Setting, Calendar Scheduling, Meeting Coordination, and
-- Deadline Tracking. A dedicated Executive-Legislative Synchronization
-- module (veto tracking, override-vote workflow) was removed from scope:
-- the SP has no automatable data channel for executive-side status outside
-- the Backstopping Committee's manual process, so a live-tracked sync
-- module would not add real automation value. Deadline Tracking still
-- monitors the Sec. 54 mayor's-action window (it only needs a transmittal
-- date + a manually-updatable status, not a live feed), which is why
-- `transmitted_to_mayor_date` and `mayor_action` remain on agenda_items.
--
-- HOW TO IMPORT (phpMyAdmin / XAMPP):
--   1. Open phpMyAdmin (http://localhost/phpmyadmin)
--   2. Create a database, e.g. "legislative_agenda_system"
--   3. Select it, go to "Import", choose this file, click Go.
--   (Or via CLI: mysql -u root -p legislative_agenda_system < schema.sql)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- users
-- Two roles only, per current scope: admin (manages accounts + full data
-- control) and staff (covers both SP Secretary/legislative staff and
-- councilors reviewing priority — a single login-holding role for this
-- capstone; individual councilors are represented as free-text references
-- in other tables, not as separate system logins).
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(150) NOT NULL,
    -- EMAIL FIX: added so accounts have somewhere to receive a forgot-
    -- password code (api/auth/forgot-password.php) and account-request
    -- notifications — previously nothing on this table could identify an
    -- email address at all.
    email         VARCHAR(255) NOT NULL UNIQUE,
    role          ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- agenda_items  (ordinances & resolutions)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS agenda_items;
CREATE TABLE agenda_items (
    id                        VARCHAR(20) PRIMARY KEY,      -- e.g. 'ORD-2026-014'
    title                     VARCHAR(500) NOT NULL,
    item_type                 ENUM('Ordinance', 'Resolution') NOT NULL,
    committee                 VARCHAR(255) NOT NULL,
    submitted_by              VARCHAR(255) NOT NULL,
    category                  ENUM('Regular', 'Budget', 'Emergency') NOT NULL DEFAULT 'Regular',
    date_filed                DATE NOT NULL,

    -- AI-generated suggestion (Priority Setting Module). Read-only from the
    -- UI's perspective — only re-populated by calling the AI endpoint again.
    ai_suggested_priority     ENUM('High', 'Medium', 'Low') NULL,
    ai_suggested_reasoning    TEXT NULL,
    ai_suggested_at           TIMESTAMP NULL,

    -- Human-confirmed priority (the only value the rest of the system acts on)
    confirmed_priority        ENUM('High', 'Medium', 'Low') NULL,
    priority_confirmed_by     VARCHAR(255) NULL,
    priority_confirmed_date   DATE NULL,
    priority_notes            TEXT NULL,

    -- Deadline Tracking's mayor's-action-window fields (Sec. 54). No veto
    -- workflow / override-vote tracking here by design — see schema header.
    transmitted_to_mayor_date DATE NULL,
    mayor_action_window_days  INT NOT NULL DEFAULT 10,       -- 10 city/municipality, 15 province
    mayor_action              ENUM('Signed', 'Vetoed', 'Deemed Approved') NULL,
    mayor_action_date         DATE NULL,
    -- EVIDENCE FIX: recording the Mayor's action used to be a bare status
    -- dropdown with nothing backing it up — no note on how staff learned
    -- this (Backstopping Committee report, signed copy received, etc.),
    -- unlike every other "mark complete" action in this system. Mirrors
    -- deadlines.completion_notes; an optional file goes through the same
    -- evidence_attachments table as deadlines/sessions (entity_type=
    -- 'agenda_item', entity_id=the ordinance/resolution id).
    mayor_action_notes        VARCHAR(500) NULL,

    -- LOOPHOLE FIX: agenda items are official legislative records. "Delete"
    -- in the admin UI archives rather than hard-deletes, so the record
    -- (and its full readings/priority history) survives for audit and
    -- records-retention purposes. A truly mistaken encode can still be
    -- hard-deleted directly in the DB by an administrator if needed — that
    -- path is intentionally not exposed through the app UI.
    is_archived               TINYINT(1) NOT NULL DEFAULT 0,
    archived_at               TIMESTAMP NULL,
    archived_by               VARCHAR(255) NULL,

    -- ARCHITECTURE FIX: Calendar Scheduling used to let staff attach ANY
    -- unattached item to a new session, with nothing determining WHICH
    -- items were actually ready to be scheduled — there was no real input
    -- driving that decision. Since Agenda Preparation (a peer module) is
    -- the one that actually compiles a session's agenda in real practice,
    -- Calendar Scheduling's "items to attach" list is now sourced from
    -- items Agenda Prep has "sent" (simulated via the Integration Hub),
    -- not from every eligible item in the database.
    ready_for_scheduling      TINYINT(1) NOT NULL DEFAULT 0,

    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_priority_notes_when_override
        CHECK (1 = 1) -- enforced in application layer (needs cross-column logic); see includes/validation.php
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- readings  (the "route slip" — Filed / 1st / 2nd / 3rd Reading, etc.)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS readings;
CREATE TABLE readings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    agenda_item_id  VARCHAR(20) NOT NULL,
    stage           VARCHAR(150) NOT NULL,
    reading_date    DATE NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    FOREIGN KEY (agenda_item_id) REFERENCES agenda_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- priority_history  (audit trail of past confirmations, shown as "Previously X")
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS priority_history;
CREATE TABLE priority_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    agenda_item_id  VARCHAR(20) NOT NULL,
    priority        ENUM('High', 'Medium', 'Low') NOT NULL,
    confirmed_by    VARCHAR(255) NOT NULL,
    confirmed_date  DATE NOT NULL,
    notes           TEXT NULL,
    recorded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agenda_item_id) REFERENCES agenda_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- sessions  (regular/special sessions, committee hearings, public hearings)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS sessions;
CREATE TABLE sessions (
    id                 VARCHAR(20) PRIMARY KEY,   -- e.g. 'SESS-0731'
    session_date       DATE NOT NULL,
    session_time       VARCHAR(20) NOT NULL,      -- display string, e.g. '9:00 AM'
    session_time_24h   TIME NOT NULL,             -- normalized, used for conflict detection
    session_type       VARCHAR(100) NOT NULL,     -- Regular Session / Special Session / Committee Hearing / Public Hearing
    venue              VARCHAR(255) NOT NULL,
    presiding_officer  VARCHAR(255) NOT NULL DEFAULT 'TBD',
    committee          VARCHAR(255) NULL,         -- populated for Committee Hearings, used in conflict checks

    -- Sequential numbering as used on the SP's own agenda documents (e.g.
    -- "Ika-58 Pangkaraniwang Pulong" / "58th Regular Session"). Manually
    -- entered rather than auto-incremented — the numbering convention
    -- (continuous vs. reset per 3-year council term) needs to be confirmed
    -- with the client before this could be safely automated. Only
    -- meaningful for Regular Sessions in practice, but not restricted at
    -- the schema level in case the office numbers other session types too.
    sequence_number    INT NULL,

    status             ENUM('Scheduled', 'Rescheduled', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Scheduled',

    -- EVIDENCE FIX: marking a session Completed used to be a bare status
    -- flip with nothing backing it up. Mirrors deadlines.completion_notes/
    -- completed_by/completed_at — see api/sessions/update.php. An optional
    -- supporting file is looked up separately via evidence_attachments
    -- (entity_type='session'), not stored as a column here.
    completion_notes   VARCHAR(500) NULL,
    completed_by       VARCHAR(255) NULL,
    completed_at       TIMESTAMP NULL,

    -- RISK FIX: admin "delete" used to be a permanent hard DELETE. Same
    -- risk class as any admin-delete function — accidental data loss, and
    -- no recovery path if a session is removed by mistake or maliciously.
    -- This is now a soft delete: is_deleted flags it out of every normal
    -- list (api/sessions/list.php), a reason is required (mirrors the
    -- evidence-required pattern elsewhere), and api/sessions/restore.php
    -- (admin-only) can bring it back. The row and its history are never
    -- actually destroyed through the app UI.
    is_deleted         TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at         TIMESTAMP NULL,
    deleted_by         VARCHAR(255) NULL,
    delete_reason      VARCHAR(500) NULL,

    created_by         INT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- session_agenda_items  (many-to-many: a session covers one or more items)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS session_agenda_items;
CREATE TABLE session_agenda_items (
    session_id      VARCHAR(20) NOT NULL,
    agenda_item_id  VARCHAR(20) NOT NULL,
    PRIMARY KEY (session_id, agenda_item_id),
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (agenda_item_id) REFERENCES agenda_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- meetings  (logistics checklist per session — Meeting Coordination Module)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS meetings;
CREATE TABLE meetings (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    session_id                VARCHAR(20) NOT NULL UNIQUE,
    venue_booked               TINYINT(1) NOT NULL DEFAULT 0,
    attendance_confirmed_text VARCHAR(255) NOT NULL DEFAULT 'Pending',
    documents_distributed     TINYINT(1) NOT NULL DEFAULT 0,
    minutes_status            ENUM('Not yet started', 'Draft', 'Finalized') NOT NULL DEFAULT 'Not yet started',

    -- Integration checkpoint: mirrors the confirmation payload that would
    -- come back from the Session and Legislative Meeting Management System
    -- once the proposed schedule is validated on their end. Kept as TWO
    -- separate flags (not one combined boolean) because that's what the
    -- stub actually returns (attendees_ready, agenda_ready — see
    -- includes/integration/session_mgmt_stub.php), and because the
    -- Meeting Coordination checklist needs to show them as separate,
    -- auto-populated rows rather than one manual "attendance confirmed"
    -- checkbox. "Ready to send notifications" is computed as
    -- (attendees_confirmed AND agenda_confirmed) at query time — not
    -- stored as a third redundant column, same reasoning as why the
    -- Mayor's-action-window deadline isn't duplicated (see api/deadlines/list.php).
    attendees_confirmed             TINYINT(1) NOT NULL DEFAULT 0,
    agenda_confirmed                TINYINT(1) NOT NULL DEFAULT 0,
    external_confirmed_at          TIMESTAMP NULL,
    notifications_sent             TINYINT(1) NOT NULL DEFAULT 0,
    notifications_sent_at          TIMESTAMP NULL,
    notifications_sent_by          VARCHAR(255) NULL,

    updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- meeting_stakeholders  (normalized list of who gets notified per meeting)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS meeting_stakeholders;
CREATE TABLE meeting_stakeholders (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id        INT NOT NULL,
    stakeholder_name  VARCHAR(255) NOT NULL,
    -- SMTP FIX: stakeholder_name is a label ("All councilors", "City
    -- Legal Office"), never an address a real mail server can deliver to
    -- — this was invisible while dispatch was mocked. Real sends in
    -- api/meetings/send-notifications.php only go to stakeholders with an
    -- email on file; others are reported back as skipped rather than
    -- silently dropped.
    email             VARCHAR(255) NULL,
    -- DESIGN FIX: this system has no separate "councilor" account type —
    -- only admin/staff users have real, verified emails (users.email is
    -- NOT NULL). When a stakeholder IS a registered user, user_id links
    -- back to that account so name/email are pulled from a real record
    -- (api/meetings/add-stakeholder.php), not hand-typed and possibly
    -- stale or fictional. External people who aren't system users
    -- (an actual councilor's own email, the Mayor's office contact) stay
    -- as a plain name+email with user_id left NULL — a real address the
    -- office typed in on purpose, not an invented department alias.
    user_id           INT NULL,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- deadlines  (statutory + internal — Deadline Tracking Module)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS deadlines;
CREATE TABLE deadlines (
    id               VARCHAR(20) PRIMARY KEY,   -- e.g. 'DL-1'
    label            VARCHAR(255) NOT NULL,
    related_item_id  VARCHAR(20) NULL,
    deadline_type    VARCHAR(150) NOT NULL,
    due_date         DATE NOT NULL,
    status           VARCHAR(100) NOT NULL DEFAULT 'Scheduled',
    is_statutory     TINYINT(1) NOT NULL DEFAULT 0,

    -- Provenance: makes it visible WHY a deadline exists, not just that it
    -- does. Set automatically when a system event (like recording 3rd
    -- Reading — Passed) generates one — see api/agenda-items/add-reading.php.
    -- Manually-created deadlines (the "Add deadline" form) leave these null.
    auto_generated    TINYINT(1) NOT NULL DEFAULT 0,
    generation_reason VARCHAR(500) NULL,
    source_system     VARCHAR(150) NULL,  -- e.g. 'Committee Management and Assignment System (simulated)' — null for staff-entered
    completed_by      VARCHAR(255) NULL,
    completed_at      TIMESTAMP NULL,
    completion_notes  VARCHAR(500) NULL,  -- required when marking complete — the evidence, not just a checkbox

    -- Assignment: who is responsible for this deadline, and therefore the
    -- only person (besides an admin) allowed to mark it complete — see
    -- api/deadlines/update.php. Currently only settable from the
    -- Integration Hub's simulated "request a deadline" form (a real
    -- Committee Management system would specify who on our side owns it),
    -- not from the plain "Add deadline" form, which stays unassigned/open
    -- to any staff member on purpose.
    -- assigned_to_name is a denormalized snapshot (same pattern as
    -- audit_log.username) so the label survives if the user is later
    -- deactivated — ON DELETE SET NULL only clears the FK, not the name.
    assigned_to_user_id INT NULL,
    assigned_to_name     VARCHAR(255) NULL,

    -- RISK FIX: same soft-delete treatment as sessions above — see that
    -- table's comment for the reasoning. api/deadlines/delete.php now
    -- archives (requires a reason) instead of destroying the row;
    -- api/deadlines/restore.php (admin-only) reverses it.
    is_deleted       TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at       TIMESTAMP NULL,
    deleted_by       VARCHAR(255) NULL,
    delete_reason    VARCHAR(500) NULL,

    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (related_item_id) REFERENCES agenda_items(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- audit_log  (who did what, when — supports the Cybersecurity/Data Privacy
-- sections of Chapter 2 and the transparency concern raised by the client)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS audit_log;
CREATE TABLE audit_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NULL,
    username     VARCHAR(50) NULL,     -- denormalized snapshot, survives user deletion
    action       VARCHAR(100) NOT NULL,
    entity_type  VARCHAR(50) NOT NULL,
    entity_id    VARCHAR(50) NULL,
    details      TEXT NULL,
    ip_address   VARCHAR(45) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- integration_tokens  (API keys for OTHER subsystems to pull our data —
-- the "exposed but access-controlled" integration surface)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS integration_tokens;
CREATE TABLE integration_tokens (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    label        VARCHAR(150) NOT NULL,      -- e.g. 'Session and Legislative Meeting Mgmt System (peer group)'
    token_hash   VARCHAR(255) NOT NULL,      -- SHA-256 hash of the token; raw token is shown once on creation
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- evidence_attachments
-- Optional supporting file for a deadline or session completion (minutes
-- excerpt, signed report, attendance sheet scan, etc). Generic across both
-- entity types via entity_type+entity_id (a lightweight lookup table, not
-- FK-bound to either parent — a strict FK can't point at "either of two
-- tables depending on a column value", and the alternative of two nullable
-- FK columns was more complex for no real benefit at this scale). See
-- api/evidence/upload.php, list.php, download.php — download.php re-checks
-- the same permission rule as the parent entity before streaming any file.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS evidence_attachments;
CREATE TABLE evidence_attachments (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    entity_type       ENUM('deadline', 'session', 'agenda_item') NOT NULL,
    entity_id         VARCHAR(20) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,   -- for display only, never used as the on-disk path
    stored_filename   VARCHAR(255) NOT NULL,   -- random name actually on disk under /uploads/evidence
    file_size         INT NOT NULL,
    mime_type         VARCHAR(100) NULL,
    uploaded_by       VARCHAR(255) NOT NULL,
    uploaded_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- password_resets
-- One live (unused) code per user at a time — a fresh forgot-password
-- request deletes any earlier unused row for that user (see
-- api/auth/forgot-password.php). `attempts` caps brute-forcing a 6-digit
-- code within its 15-minute window (see api/auth/reset-password.php).
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS password_resets;
CREATE TABLE password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    code_hash  VARCHAR(64) NOT NULL,   -- SHA-256 hex of the emailed code, never the code itself
    attempts   INT NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- account_requests
-- Staff-submitted requests for an admin to review — info updates (name/
-- email) and self-deactivation both go through here instead of being
-- applied directly, so a non-admin can never edit their own record or
-- take themselves offline unilaterally. See api/account-requests/*.php.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS account_requests;
CREATE TABLE account_requests (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT NOT NULL,
    requested_by         VARCHAR(255) NOT NULL,  -- denormalized snapshot, survives user deletion
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

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- integration_events
-- Makes the mocked peer-system exchange VISIBLE and demonstrable, instead
-- of an instant, invisible function call. Every time Calendar Scheduling
-- hands a proposed schedule to session_mgmt_stub.php, TWO rows are written
-- here: one for what we "sent," one for what the (mocked) peer system
-- "replied" with. Meeting Coordination's checklist changes are a direct,
-- visible consequence of the second row — panelists can see the whole
-- chain: sent -> received -> checklist updated -> Send button unlocked.
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS integration_events;
CREATE TABLE integration_events (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    direction           ENUM('outbound', 'inbound') NOT NULL,
    event_type          VARCHAR(100) NOT NULL,   -- e.g. 'proposed_schedule_sent', 'confirmation_received'
    related_session_id  VARCHAR(20) NULL,
    summary             VARCHAR(500) NOT NULL,   -- human-readable, shown directly in the UI
    payload             TEXT NULL,               -- the actual simulated JSON exchanged (for a "view raw" detail)
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (related_session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- SEED DATA
-- Dates are computed relative to CURDATE() at import time (via a session
-- variable) so the sample "upcoming" sessions/deadlines always look current,
-- whenever this file is actually imported — instead of being hardcoded to
-- July 2026 and looking stale by the time you run this.
-- ============================================================================

SET @today = CURDATE();

-- Default admin + staff accounts.
-- Password for BOTH accounts: "ChangeMe123!"  (bcrypt hash below)
-- CHANGE THESE before deploying anywhere reachable outside your own machine.
INSERT INTO users (username, password_hash, full_name, email, role) VALUES
    ('admin',   '$2y$10$INEcwTrP/04S5NWcocnM7OCH2JS7hb4.6Bub03WXZE3RqoGkVCGo.', 'System Administrator', 'admin@sjdm.gov.ph', 'admin'),
    ('rsantos', '$2y$10$INEcwTrP/04S5NWcocnM7OCH2JS7hb4.6Bub03WXZE3RqoGkVCGo.', 'Atty. R. Santos, Legislative Officer', 'rsantos@sjdm.gov.ph', 'staff');

-- Agenda items (mirrors data.js, dates shifted relative to @today)
INSERT INTO agenda_items (id, title, item_type, committee, submitted_by, category, date_filed,
    ai_suggested_priority, ai_suggested_reasoning, confirmed_priority, priority_confirmed_by, priority_confirmed_date,
    transmitted_to_mayor_date, mayor_action_window_days, mayor_action, mayor_action_date) VALUES
('ORD-2026-014', 'An Ordinance Regulating the Operation of E-Bikes and E-Trikes Along City Roads',
    'Ordinance', 'Committee on Transportation & Traffic Management', 'Councilor M. Fernandez', 'Regular',
    DATE_SUB(@today, INTERVAL 26 DAY),
    'Medium', 'Public-interest measure, no statutory deadline attached, moderate constituent impact.',
    'Medium', 'Atty. R. Santos, Legislative Officer', DATE_SUB(@today, INTERVAL 23 DAY),
    DATE_SUB(@today, INTERVAL 3 DAY), 10, NULL, NULL),

('ORD-2026-011', 'An Ordinance Appropriating Supplemental Funds for the City Disaster Risk Reduction Office',
    'Ordinance', 'Committee on Ways & Means', 'Office of the City Budget', 'Budget',
    DATE_SUB(@today, INTERVAL 45 DAY),
    'High', 'Budget/appropriation measure — flagged high priority by category rule.',
    'High', 'Atty. R. Santos, Legislative Officer', DATE_SUB(@today, INTERVAL 44 DAY),
    DATE_SUB(@today, INTERVAL 15 DAY), 10, 'Signed', DATE_SUB(@today, INTERVAL 9 DAY)),

('ORD-2026-005', 'An Ordinance Setting Uniform Business Permit Renewal Deadlines for Micro-Enterprises',
    'Ordinance', 'Committee on Trade & Commerce', 'Councilor L. Ocampo', 'Regular',
    DATE_SUB(@today, INTERVAL 90 DAY),
    'Low', 'Administrative/process measure, no urgent deadline.',
    'Low', 'Atty. R. Santos, Legislative Officer', DATE_SUB(@today, INTERVAL 89 DAY),
    DATE_SUB(@today, INTERVAL 79 DAY), 10, 'Deemed Approved', DATE_SUB(@today, INTERVAL 69 DAY)),

('RES-2026-022', 'A Resolution Declaring a State of Calamity Due to Localized Flooding',
    'Resolution', 'Committee on Disaster Risk Reduction', 'Office of the Vice Mayor', 'Emergency',
    DATE_SUB(@today, INTERVAL 8 DAY),
    'High', 'Emergency measure — flagged automatically high priority by category rule.',
    NULL, NULL, NULL,
    NULL, 10, NULL, NULL);

INSERT INTO readings (agenda_item_id, stage, reading_date, sort_order) VALUES
('ORD-2026-014', 'Filed', DATE_SUB(@today, INTERVAL 26 DAY), 1),
('ORD-2026-014', '1st Reading', DATE_SUB(@today, INTERVAL 19 DAY), 2),
('ORD-2026-014', '2nd Reading', DATE_SUB(@today, INTERVAL 12 DAY), 3),
('ORD-2026-014', '3rd Reading — Passed', DATE_SUB(@today, INTERVAL 5 DAY), 4),

('ORD-2026-011', 'Filed', DATE_SUB(@today, INTERVAL 45 DAY), 1),
('ORD-2026-011', '1st Reading', DATE_SUB(@today, INTERVAL 40 DAY), 2),
('ORD-2026-011', '2nd Reading', DATE_SUB(@today, INTERVAL 33 DAY), 3),
('ORD-2026-011', '3rd Reading — Passed', DATE_SUB(@today, INTERVAL 26 DAY), 4),

('ORD-2026-005', 'Filed', DATE_SUB(@today, INTERVAL 90 DAY), 1),
('ORD-2026-005', '1st Reading', DATE_SUB(@today, INTERVAL 83 DAY), 2),
('ORD-2026-005', '2nd Reading', DATE_SUB(@today, INTERVAL 76 DAY), 3),
('ORD-2026-005', '3rd Reading — Passed', DATE_SUB(@today, INTERVAL 69 DAY), 4),

('RES-2026-022', 'Filed', DATE_SUB(@today, INTERVAL 8 DAY), 1),
('RES-2026-022', '1st Reading', DATE_SUB(@today, INTERVAL 4 DAY), 2);

-- Sessions (dates spread across a 3-week window centered on @today)
INSERT INTO sessions (id, session_date, session_time, session_time_24h, session_type, venue, presiding_officer, committee, sequence_number, status, completion_notes, completed_by, completed_at) VALUES
('SESS-0001', DATE_ADD(@today, INTERVAL 3 DAY),  '9:00 AM', '09:00:00', 'Regular Session', 'Sanggunian Session Hall, 4th Flr.', 'Hon. Vice Mayor, City Presiding Officer', NULL, 58, 'Scheduled', NULL, NULL, NULL),
('SESS-0002', DATE_ADD(@today, INTERVAL 5 DAY),  '2:00 PM', '14:00:00', 'Committee Hearing — Transportation & Traffic', 'Committee Room B', 'Councilor M. Fernandez, Committee Chair', 'Committee on Transportation & Traffic Management', NULL, 'Scheduled', NULL, NULL, NULL),
('SESS-0003', DATE_ADD(@today, INTERVAL 10 DAY), '1:30 PM', '13:30:00', 'Public Hearing', 'City Social Hall', 'Councilor M. Fernandez, Committee Chair', 'Committee on Transportation & Traffic Management', NULL, 'Scheduled', NULL, NULL, NULL),
('SESS-0004', DATE_SUB(@today, INTERVAL 4 DAY),  '9:00 AM', '09:00:00', 'Regular Session', 'Sanggunian Session Hall, 4th Flr.', 'Hon. Vice Mayor, City Presiding Officer', NULL, 57, 'Completed', 'Quorum met (12/12). RES-2026-022 passed 2nd reading; minutes on file with the SP Secretary.', 'Atty. R. Santos, Legislative Officer', DATE_SUB(@today, INTERVAL 4 DAY));

INSERT INTO session_agenda_items (session_id, agenda_item_id) VALUES
('SESS-0001', 'ORD-2026-014'),
('SESS-0001', 'RES-2026-022'),
('SESS-0002', 'ORD-2026-014'),
('SESS-0003', 'ORD-2026-014'),
('SESS-0004', 'RES-2026-022');

INSERT INTO meetings (session_id, venue_booked, attendance_confirmed_text, documents_distributed, minutes_status, attendees_confirmed, agenda_confirmed, external_confirmed_at, notifications_sent, notifications_sent_at, notifications_sent_by) VALUES
('SESS-0001', 1, '10 of 12 councilors confirmed', 1, 'Not yet started', 1, 1, NOW(), 1, NOW(), 'Atty. R. Santos, Legislative Officer'),
('SESS-0002', 1, '5 of 5 committee members confirmed', 1, 'Not yet started', 1, 1, NOW(), 1, NOW(), 'Atty. R. Santos, Legislative Officer'),
('SESS-0003', 0, 'Pending', 0, 'Not yet started', 0, 0, NULL, 0, NULL, NULL),
('SESS-0004', 1, '12 of 12 councilors confirmed', 1, 'Finalized', 1, 1, NOW(), 1, NOW(), 'Atty. R. Santos, Legislative Officer');

-- SEED FIX: previously seeded fake department-alias addresses
-- ("legal@sjdm.gov.ph" etc.) that don't correspond to anything real and
-- can't actually receive mail — confusing for a real SMTP test. These two
-- rows instead link to the real seeded user accounts via user_id, so
-- name/email come from an actual users row (see the user_id comment on
-- the table above) rather than being invented. To genuinely test
-- delivery, add a stakeholder with your own real email through the
-- "+ Add stakeholder" control in Meeting Coordination — seed data alone
-- can't prove a real inbox receives anything.
INSERT INTO meeting_stakeholders (meeting_id, stakeholder_name, email, user_id) VALUES
(1, 'Atty. R. Santos, Legislative Officer', 'rsantos@sjdm.gov.ph', 2),
(4, 'Atty. R. Santos, Legislative Officer', 'rsantos@sjdm.gov.ph', 2);

-- Deadlines table holds ONLY internal deadlines (committee reports, referred-
-- measure responses, SP review, etc.). The Mayor's-action-window deadline
-- (Sec. 54) is deliberately NOT duplicated here — it's computed on the fly
-- in api/deadlines/list.php directly from agenda_items.transmitted_to_mayor_date
-- + mayor_action_window_days. Storing it twice (once here, once on
-- agenda_items) would let the two copies drift out of sync if only one
-- were ever updated — see the note in api/deadlines/list.php.
-- DL-2 is seeded assigned to rsantos (id=2) specifically so the new
-- assignment/visibility feature has something to demo out of the box —
-- log in as rsantos to see it under "Assigned to me", or as admin to see
-- everything. DL-3 is left unassigned on purpose to demonstrate that an
-- unassigned deadline stays visible to (and completable by) any staff
-- account, not just admin.
INSERT INTO deadlines (id, label, related_item_id, deadline_type, due_date, status, is_statutory, assigned_to_user_id, assigned_to_name) VALUES
('DL-2', 'Committee report due — Transportation & Traffic', 'ORD-2026-014', 'Committee Report', DATE_ADD(@today, INTERVAL 2 DAY), 'Due soon', 0, 2, 'Atty. R. Santos, Legislative Officer'),
('DL-3', 'Referred-measure response — City Legal Office opinion', 'RES-2026-022', 'Referred Measure Response', DATE_ADD(@today, INTERVAL 4 DAY), 'Due soon', 0, NULL, NULL),
('DL-4', 'Sangguniang Panlalawigan review window (Sec. 56, if component city/municipality)', 'ORD-2026-011', 'SP Review', DATE_SUB(@today, INTERVAL 39 DAY), 'Completed', 1, NULL, NULL);

-- No integration_tokens seeded by default — create one via the admin panel
-- (Admin → Integration Access) so the raw token is only ever shown once.
