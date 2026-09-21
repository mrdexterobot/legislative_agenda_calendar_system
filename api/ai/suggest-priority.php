<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/ai_priority.php';

$user = requireApiAuth(); // staff or admin
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$itemId = trim($b['item_id'] ?? '');
if ($itemId === '') {
    jsonError('item_id is required.', 400);
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM agenda_items WHERE id = :id AND is_archived = 0');
$stmt->execute([':id' => $itemId]);
$item = $stmt->fetch();

if (!$item) {
    jsonError('Agenda item not found.', 404);
}

// NOTE: this call can take a few seconds (external API) and counts against
// the Groq free-tier daily quota — this is why it's wired to an explicit
// "Generate AI suggestion" button in the UI rather than firing automatically
// whenever the priority-setting page loads.
$suggestion = getAIPrioritySuggestion($item);

if ($suggestion['error']) {
    // Don't overwrite a previously-good suggestion with a failure — leave
    // the existing ai_suggested_* columns untouched, just report the error.
    jsonError($suggestion['reasoning'], 502);
}

$update = $db->prepare(
    "UPDATE agenda_items
     SET ai_suggested_priority = :priority, ai_suggested_reasoning = :reasoning, ai_suggested_at = NOW()
     WHERE id = :id"
);
$update->execute([
    ':priority'  => $suggestion['priority'],
    ':reasoning' => $suggestion['reasoning'],
    ':id'        => $itemId,
]);

logAudit('ai_suggest_priority', 'agenda_item', $itemId, "Suggested: {$suggestion['priority']} — {$suggestion['reasoning']}");

jsonSuccess([
    'ai_suggested_priority'  => $suggestion['priority'],
    'ai_suggested_reasoning' => $suggestion['reasoning'],
]);
