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
$sessionId = trim($b['session_id'] ?? '');
if ($sessionId === '') {
    jsonError('session_id is required.', 400);
}

$allowed = ['venue_booked' => 'bool', 'documents_distributed' => 'bool', 'minutes_status' => 'enum', 'attendance_confirmed_text' => 'text'];
$fields = [];
$params = [':session_id' => $sessionId];

foreach ($allowed as $field => $type) {
    if (!array_key_exists($field, $b)) continue;
    if ($type === 'bool') {
        $fields[] = "$field = :$field";
        $params[":$field"] = !empty($b[$field]) ? 1 : 0;
    } elseif ($type === 'enum') {
        if (!in_array($b[$field], ['Not yet started', 'Draft', 'Finalized'], true)) {
            jsonError('minutes_status must be one of: Not yet started, Draft, Finalized.', 422);
        }
        $fields[] = "$field = :$field";
        $params[":$field"] = $b[$field];
    } else {
        $fields[] = "$field = :$field";
        $params[":$field"] = trim($b[$field]);
    }
}

if (!$fields) {
    jsonError('No editable fields provided.', 400);
}

$db = getDb();
$check = $db->prepare('SELECT id FROM meetings WHERE session_id = :session_id');
$check->execute([':session_id' => $sessionId]);
if (!$check->fetch()) {
    jsonError('No meeting record found for this session.', 404);
}

$sql = 'UPDATE meetings SET ' . implode(', ', $fields) . ' WHERE session_id = :session_id';
$db->prepare($sql)->execute($params);

logAudit('update_checklist', 'meeting', $sessionId, "Updated by {$user['full_name']}: " . implode(', ', array_keys($b)));

jsonSuccess(['session_id' => $sessionId]);
