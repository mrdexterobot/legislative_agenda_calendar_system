<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireApiRole('admin'); // record correction is an admin action
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$itemId = trim($b['id'] ?? '');
if ($itemId === '') {
    jsonError('id is required.', 400);
}

$allowedFields = ['title', 'item_type', 'committee', 'submitted_by', 'category', 'date_filed'];
$fields = [];
$params = [':id' => $itemId];

foreach ($allowedFields as $f) {
    if (array_key_exists($f, $b)) {
        $fields[] = "$f = :$f";
        $params[":$f"] = $b[$f];
    }
}

if (!$fields) {
    jsonError('No editable fields provided.', 400);
}

if (isset($b['item_type']) && !in_array($b['item_type'], ['Ordinance', 'Resolution'], true)) {
    jsonError('item_type must be Ordinance or Resolution.', 422);
}
if (isset($b['category']) && !in_array($b['category'], ['Regular', 'Budget', 'Emergency'], true)) {
    jsonError('category must be Regular, Budget, or Emergency.', 422);
}

$db = getDb();
$check = $db->prepare('SELECT id FROM agenda_items WHERE id = :id AND is_archived = 0');
$check->execute([':id' => $itemId]);
if (!$check->fetch()) {
    jsonError('Agenda item not found.', 404);
}

$sql = 'UPDATE agenda_items SET ' . implode(', ', $fields) . ' WHERE id = :id';
$db->prepare($sql)->execute($params);

logAudit('update', 'agenda_item', $itemId, "Fields corrected by {$user['full_name']}: " . implode(', ', array_keys($b)));

jsonSuccess(['id' => $itemId]);
