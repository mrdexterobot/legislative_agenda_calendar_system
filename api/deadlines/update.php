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
$id = trim($b['id'] ?? '');
if ($id === '') {
    jsonError('id is required.', 400);
}
if (str_starts_with($id, 'MAYOR-WINDOW-')) {
    jsonError('This deadline is computed automatically from the agenda item\'s mayor-status fields — update it via api/agenda-items/update-mayor-status.php instead.', 422);
}

$db = getDb();
$check = $db->prepare('SELECT id, assigned_to_user_id, assigned_to_name, is_deleted FROM deadlines WHERE id = :id');
$check->execute([':id' => $id]);
$existing = $check->fetch();
if (!$existing) {
    jsonError('Deadline not found.', 404);
}
if ($existing['is_deleted']) {
    jsonError('This deadline has been removed — restore it first (admin only) before making changes.', 409);
}

$isAdmin = $user['role'] === 'admin';
$isAssignedToSomeoneElse = $existing['assigned_to_user_id'] !== null
    && (int) $existing['assigned_to_user_id'] !== (int) $user['id']
    && !$isAdmin;

// DEFENSE-IN-DEPTH: api/deadlines/list.php already hides deadlines assigned
// to other staff, so this shouldn't normally be reachable for them — but
// the API has to defend itself independently of what the UI shows (same
// principle used throughout this codebase, see includes/auth.php).
if ($isAssignedToSomeoneElse) {
    jsonError('This deadline is assigned to ' . ($existing['assigned_to_name'] ?? 'another user') . '. Only they or an admin can modify it.', 403);
}

$allowed = ['label', 'due_date', 'status', 'deadline_type'];
$fields = [];
$params = [':id' => $id];
foreach ($allowed as $f) {
    if (array_key_exists($f, $b)) {
        $fields[] = "$f = :$f";
        $params[":$f"] = $b[$f];
    }
}

// REASSIGNMENT: only an admin may change who owns a deadline — otherwise
// staff could quietly hand off (or grab) work items outside the review
// the assignment is meant to provide.
if (array_key_exists('assigned_to', $b)) {
    if (!$isAdmin) {
        jsonError('Only an admin can reassign a deadline.', 403);
    }
    $newAssigneeId = $b['assigned_to'] !== '' && $b['assigned_to'] !== null ? (int) $b['assigned_to'] : null;
    $newAssigneeName = null;
    if ($newAssigneeId !== null) {
        $userCheck = $db->prepare('SELECT id, full_name FROM users WHERE id = :id AND is_active = 1');
        $userCheck->execute([':id' => $newAssigneeId]);
        $assignee = $userCheck->fetch();
        if (!$assignee) {
            jsonError('The selected assignee was not found or is no longer active.', 422);
        }
        $newAssigneeName = $assignee['full_name'];
    }
    $fields[] = 'assigned_to_user_id = :assigned_id';
    $fields[] = 'assigned_to_name = :assigned_name';
    $params[':assigned_id'] = $newAssigneeId;
    $params[':assigned_name'] = $newAssigneeName;
}

if (!$fields) {
    jsonError('No editable fields provided.', 400);
}

// EVIDENCE FIX: marking a deadline complete used to be a bare one-click
// toggle with no record of why/how. Completion now requires a short note,
// and an OPTIONAL supporting file can be attached beforehand via
// api/evidence/upload.php (entity_type=deadline, entity_id=$id).
if (($b['status'] ?? '') === 'Completed') {
    $notes = trim($b['completion_notes'] ?? '');
    if ($notes === '') {
        jsonError('Please add a short note on how this was completed before marking it done.', 422);
    }
    $fields[] = 'completed_by = :completed_by';
    $fields[] = 'completed_at = NOW()';
    $fields[] = 'completion_notes = :completion_notes';
    $params[':completed_by'] = $user['full_name'];
    $params[':completion_notes'] = $notes;
}

$db->prepare('UPDATE deadlines SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);

logAudit('update', 'deadline', $id, "Updated by {$user['full_name']}" . (isset($params[':completion_notes']) ? ": marked complete — {$params[':completion_notes']}" : ''));

jsonSuccess(['id' => $id]);
