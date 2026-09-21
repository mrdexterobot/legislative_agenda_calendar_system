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
$itemId = trim($b['id'] ?? '');
if ($itemId === '') {
    jsonError('id is required.', 400);
}

$db = getDb();
$check = $db->prepare('SELECT id, is_archived FROM agenda_items WHERE id = :id');
$check->execute([':id' => $itemId]);
$row = $check->fetch();

if (!$row) {
    jsonError('Agenda item not found.', 404);
}
if ($row['is_archived']) {
    jsonError('This item is already archived.', 409);
}

$db->prepare(
    "UPDATE agenda_items SET is_archived = 1, archived_at = NOW(), archived_by = :by WHERE id = :id"
)->execute([':by' => $user['full_name'], ':id' => $itemId]);

logAudit('archive', 'agenda_item', $itemId, "Archived by {$user['full_name']}");

jsonSuccess(['id' => $itemId, 'archived' => true]);
