<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireApiAuth(); // staff or admin
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$itemId   = trim($b['item_id'] ?? '');
$priority = $b['priority'] ?? '';
$notes    = trim($b['notes'] ?? '');

if ($itemId === '' || !in_array($priority, ['High', 'Medium', 'Low'], true)) {
    jsonError('item_id and a valid priority (High/Medium/Low) are required.', 400);
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM agenda_items WHERE id = :id AND is_archived = 0');
$stmt->execute([':id' => $itemId]);
$item = $stmt->fetch();

if (!$item) {
    jsonError('Agenda item not found.', 404);
}

// LOOPHOLE FIX: the original front-end only enforced "notes required when
// overriding the AI suggestion" in JavaScript, which anyone can bypass by
// calling the API directly. Re-checking here means the rule actually holds.
$differsFromAI = $item['ai_suggested_priority'] !== null && $priority !== $item['ai_suggested_priority'];
if ($differsFromAI && $notes === '') {
    jsonError('Please add a short note explaining why this differs from the AI suggestion before confirming.', 422);
}

$db->beginTransaction();
try {
    // If this item already had a confirmed priority, archive that as history
    // before overwriting it — mirrors the "Previously X" trail in the UI.
    if ($item['confirmed_priority'] !== null) {
        $db->prepare(
            "INSERT INTO priority_history (agenda_item_id, priority, confirmed_by, confirmed_date, notes)
             VALUES (:id, :priority, :by, :date, :notes)"
        )->execute([
            ':id'       => $itemId,
            ':priority' => $item['confirmed_priority'],
            ':by'       => $item['priority_confirmed_by'],
            ':date'     => $item['priority_confirmed_date'],
            ':notes'    => $item['priority_notes'],
        ]);
    }

    $db->prepare(
        "UPDATE agenda_items
         SET confirmed_priority = :priority, priority_confirmed_by = :by,
             priority_confirmed_date = CURDATE(), priority_notes = :notes
         WHERE id = :id"
    )->execute([
        ':priority' => $priority,
        ':by'       => $user['full_name'],
        ':notes'    => $notes ?: null,
        ':id'       => $itemId,
    ]);

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('Failed to confirm priority: ' . $e->getMessage());
    jsonError('Failed to save the confirmation. Please try again.', 500);
}

logAudit('confirm_priority', 'agenda_item', $itemId, "Set to $priority by {$user['full_name']}" . ($differsFromAI ? ' (overrode AI suggestion)' : ''));

jsonSuccess(['item_id' => $itemId, 'confirmed_priority' => $priority]);
