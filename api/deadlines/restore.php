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
if ($id === '') {
    jsonError('id is required.', 400);
}

$db = getDb();
$check = $db->prepare('SELECT id, is_deleted FROM deadlines WHERE id = :id');
$check->execute([':id' => $id]);
$row = $check->fetch();
if (!$row) {
    jsonError('Deadline not found.', 404);
}
if (!$row['is_deleted']) {
    jsonError('This deadline is not currently deleted.', 409);
}

$db->prepare(
    "UPDATE deadlines SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = :id"
)->execute([':id' => $id]);

logAudit('restore', 'deadline', $id, "Restored by {$user['full_name']}");

jsonSuccess(['id' => $id, 'restored' => true]);
