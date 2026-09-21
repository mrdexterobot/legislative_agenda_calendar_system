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
$reason = trim($b['reason'] ?? '');

if ($sessionId === '') {
    jsonError('id is required.', 400);
}
// RISK FIX: same as api/deadlines/delete.php — a reason is required, and
// this is now a reversible soft delete (see api/sessions/restore.php),
// not a permanent DELETE FROM.
if ($reason === '') {
    jsonError('Please explain why this session is being removed.', 422);
}

$db = getDb();
$stmt = $db->prepare('SELECT status, is_deleted FROM sessions WHERE id = :id');
$stmt->execute([':id' => $sessionId]);
$existing = $stmt->fetch();

if (!$existing) {
    jsonError('Session not found.', 404);
}
if ($existing['is_deleted']) {
    jsonError('This session was already removed.', 409);
}
// LOOPHOLE FIX (original): a session that already happened is a historical
// record (minutes, attendance, etc. reference it) — removing it would
// silently break that trail. Cancel/reschedule is allowed for anything
// upcoming; only non-completed sessions can be archived.
if ($existing['status'] === 'Completed') {
    jsonError('Completed sessions are historical records and cannot be removed. Use status changes instead for future sessions.', 409);
}

$db->prepare(
    "UPDATE sessions SET is_deleted = 1, deleted_at = NOW(), deleted_by = :by, delete_reason = :reason WHERE id = :id"
)->execute([':by' => $user['full_name'], ':reason' => $reason, ':id' => $sessionId]);

logAudit('delete', 'session', $sessionId, "Removed by {$user['full_name']}: $reason");

jsonSuccess(['id' => $sessionId, 'deleted' => true]);
