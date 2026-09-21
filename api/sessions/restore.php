<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$sessionId = trim($b['id'] ?? '');
if ($sessionId === '') {
    jsonError('id is required.', 400);
}

$db = getDb();
$check = $db->prepare('SELECT id, is_deleted FROM sessions WHERE id = :id');
$check->execute([':id' => $sessionId]);
$row = $check->fetch();
if (!$row) {
    jsonError('Session not found.', 404);
}
if (!$row['is_deleted']) {
    jsonError('This session is not currently deleted.', 409);
}

$db->prepare(
    "UPDATE sessions SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = :id"
)->execute([':id' => $sessionId]);

logAudit('restore', 'session', $sessionId, "Restored by {$user['full_name']}");

jsonSuccess(['id' => $sessionId, 'restored' => true]);
