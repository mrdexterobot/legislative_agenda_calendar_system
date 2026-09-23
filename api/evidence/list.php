<?php
/**
 * list.php — returns evidence attachments for one entity. Never exposes
 * stored_filename (the on-disk random name) — only download.php, which
 * re-checks permission itself, resolves an attachment id to an actual
 * file.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$entityType = $_GET['entity_type'] ?? '';
$entityId   = trim($_GET['entity_id'] ?? '');

if (!in_array($entityType, ['deadline', 'session', 'agenda_item'], true) || $entityId === '') {
    jsonError('entity_type (deadline|session|agenda_item) and entity_id are required.', 400);
}

$db = getDb();

if ($entityType === 'deadline') {
    $stmt = $db->prepare('SELECT id, assigned_to_user_id, assigned_to_name FROM deadlines WHERE id = :id');
    $stmt->execute([':id' => $entityId]);
    $entity = $stmt->fetch();
    if (!$entity) {
        jsonError('Deadline not found.', 404);
    }
    $isAssignedToSomeoneElse = $entity['assigned_to_user_id'] !== null
        && (int) $entity['assigned_to_user_id'] !== (int) $user['id']
        && !isAdminOrAbove($user);
    if ($isAssignedToSomeoneElse) {
        jsonError('This deadline is assigned to ' . ($entity['assigned_to_name'] ?? 'another user') . '.', 403);
    }
}
if ($entityType === 'agenda_item') {
    if (!isAdminOrAbove($user)) {
        jsonError('Only an administrator can view Mayor-action evidence.', 403);
    }
    $stmt = $db->prepare('SELECT id FROM agenda_items WHERE id = :id AND is_archived = 0');
    $stmt->execute([':id' => $entityId]);
    if (!$stmt->fetch()) {
        jsonError('Agenda item not found.', 404);
    }
}
// sessions: no per-user restriction, same as the rest of Calendar Scheduling.

$stmt = $db->prepare(
    "SELECT id, original_filename, file_size, mime_type, uploaded_by, uploaded_at
     FROM evidence_attachments WHERE entity_type = :type AND entity_id = :id ORDER BY uploaded_at ASC"
);
$stmt->execute([':type' => $entityType, ':id' => $entityId]);

jsonSuccess($stmt->fetchAll());
