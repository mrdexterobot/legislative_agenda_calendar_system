-- Adds the bounded superadmin audit-log CSV export support.
-- Apply this to an existing database. Fresh databases receive the same
-- objects from database/schema.sql.

CREATE INDEX idx_audit_log_created_at ON audit_log (created_at, id);
CREATE INDEX idx_audit_log_user_action_created ON audit_log (user_id, action, created_at);

CREATE TABLE IF NOT EXISTS audit_export_rate_limits (
    user_id         INT PRIMARY KEY,
    last_export_at  DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
