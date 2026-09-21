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
$id = trim($b['id'] ?? '');
$reason = trim($b['reason'] ?? '');

if ($id === '') {
    jsonError('id is required.', 400);
}
if (str_starts_with($id, 'MAYOR-WINDOW-')) {
    jsonError('This deadline is computed automatically and cannot be deleted directly.', 422);
}
// RISK FIX: admin delete used to be an immediate, permanent DELETE with
// no explanation on record — accidental data loss with zero audit trail
// beyond "an admin did it." A reason is now required (same evidence-
// required pattern as every other corrective action in this app), and
// see below: the row isn't actually destroyed, just archived.
if ($reason === '') {
    jsonError('Please explain why this deadline is being removed.', 422);
}

$db = getDb();
$check = $db->prepare('SELECT id, is_deleted FROM deadlines WHERE id = :id');
$check->execute([':id' => $id]);
$row = $check->fetch();
if (!$row) {
    jsonError('Deadline not found.', 404);
}
if ($row['is_deleted']) {
    jsonError('This deadline was already removed.', 409);
}

// RISK FIX: soft delete, not DELETE FROM — reversible via
// api/deadlines/restore.php, same principle as agenda_items.is_archived.
// Nothing an admin removes here is actually destroyed.
$db->prepare(
    "UPDATE deadlines SET is_deleted = 1, deleted_at = NOW(), deleted_by = :by, delete_reason = :reason WHERE id = :id"
)->execute([':by' => $user['full_name'], ':reason' => $reason, ':id' => $id]);

logAudit('delete', 'deadline', $id, "Removed by {$user['full_name']}: $reason");

jsonSuccess(['id' => $id, 'deleted' => true]);
