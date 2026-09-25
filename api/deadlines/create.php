<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireApiAuth();
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$label        = trim($b['label'] ?? '');
$relatedItem  = trim($b['related_item_id'] ?? '') ?: null;
$type         = trim($b['deadline_type'] ?? '');
$dueDate      = $b['due_date'] ?? '';
$isStatutory  = !empty($b['is_statutory']);
$assignedToId = (int) $user['id'];
$assignedToName = $user['full_name'];

$errors = [];
if ($label === '') $errors[] = 'Label is required.';
if ($type === '') $errors[] = 'Deadline type is required.';
if (!DateTime::createFromFormat('Y-m-d', $dueDate)) $errors[] = 'A valid due date is required.';
if ($errors) {
    jsonError('Please fix the following: ' . implode(' ', $errors), 422);
}

$db = getDb();

if ($relatedItem) {
    $check = $db->prepare('SELECT id FROM agenda_items WHERE id = :id AND is_archived = 0');
    $check->execute([':id' => $relatedItem]);
    if (!$check->fetch()) {
        jsonError("Related agenda item $relatedItem not found.", 422);
    }
}

// Manual deadlines belong to the user who creates them. Requests that assign
// work to another person enter through the Integration Hub endpoint instead.
$id = 'DL-' . strtoupper(bin2hex(random_bytes(3)));

$db->prepare(
    "INSERT INTO deadlines (id, label, related_item_id, deadline_type, due_date, status, is_statutory, assigned_to_user_id, assigned_to_name)
     VALUES (:id, :label, :related, :type, :due, 'Scheduled', :statutory, :assigned_id, :assigned_name)"
)->execute([
    ':id'            => $id,
    ':label'         => $label,
    ':related'       => $relatedItem,
    ':type'          => $type,
    ':due'           => $dueDate,
    ':statutory'     => $isStatutory ? 1 : 0,
    ':assigned_id'   => $assignedToId,
    ':assigned_name' => $assignedToName,
]);

logAudit('create', 'deadline', $id, "Created by {$user['full_name']} and assigned to self.");

jsonSuccess(['id' => $id], 201);
