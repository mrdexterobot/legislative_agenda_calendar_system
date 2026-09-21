<?php
/**
 * audit.php — writes to audit_log. Called by every endpoint that creates,
 * updates, or deletes data, and by login/logout, so there's a full trail
 * of "who did what, when" — this directly supports the transparency
 * concern the client raised in the interview guide (audit log of who
 * confirmed a priority, not just the confirmation itself) and the
 * Cybersecurity/Data Privacy sections of Chapter 2.
 */

require_once __DIR__ . '/db.php';

function logAudit(string $action, string $entityType, ?string $entityId = null, ?string $details = null): void {
    $user = $_SESSION['user'] ?? null;

    $stmt = getDb()->prepare(
        "INSERT INTO audit_log (user_id, username, action, entity_type, entity_id, details, ip_address)
         VALUES (:user_id, :username, :action, :entity_type, :entity_id, :details, :ip)"
    );
    $stmt->execute([
        ':user_id'     => $user['id'] ?? null,
        ':username'    => $user['username'] ?? 'system',
        ':action'      => $action,
        ':entity_type' => $entityType,
        ':entity_id'   => $entityId,
        ':details'     => $details,
        ':ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
