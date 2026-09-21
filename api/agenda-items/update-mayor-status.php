<?php
/**
 * This endpoint exists because Deadline Tracking still needs to monitor
 * the Sec. 54 mayor's-action window even though the dedicated
 * Executive-Legislative Synchronization module was removed from scope.
 * There's no automatable feed for this data — a staff member records it
 * manually after learning the status through the Backstopping Committee
 * (transmittal date when it's sent, and the resulting action once known).
 *
 * EVIDENCE FIX: recording mayor_action used to be a bare dropdown change
 * with nothing backing it up — every other "mark complete" action in this
 * system (deadlines, sessions) requires a short note; this one didn't.
 * mayor_action_notes is now required whenever mayor_action is being SET
 * (not when only the transmittal date is being recorded, since there's
 * nothing to explain yet at that point). An optional supporting file goes
 * through the same evidence_attachments table as deadlines/sessions
 * (entity_type='agenda_item', entity_id=$itemId), uploaded beforehand via
 * api/evidence/upload.php the same way the other completion modals do.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireApiAuth(); // staff or admin — this is routine data entry, not system config
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$itemId               = trim($b['item_id'] ?? '');
$transmittedDate       = $b['transmitted_to_mayor_date'] ?? null;
$windowDays            = isset($b['mayor_action_window_days']) ? (int) $b['mayor_action_window_days'] : null;
$mayorAction           = $b['mayor_action'] ?? null;
$mayorActionDate       = $b['mayor_action_date'] ?? null;
$mayorActionNotes      = trim($b['mayor_action_notes'] ?? '');

if ($itemId === '') {
    jsonError('item_id is required.', 400);
}
if ($mayorAction !== null && !in_array($mayorAction, ['Signed', 'Vetoed', 'Deemed Approved'], true)) {
    jsonError('mayor_action must be Signed, Vetoed, or Deemed Approved (or omitted).', 422);
}
// EVIDENCE FIX: see file header — required the moment an action is set.
if ($mayorAction !== null && $mayorActionNotes === '') {
    jsonError('Please add a short note on how this was confirmed (e.g. signed copy received, Backstopping Committee report) before recording the Mayor\'s action.', 422);
}
// LOOPHOLE CHECK: can't record a mayor action without a transmittal date —
// there's nothing for the "window" to be measured against.
if ($mayorAction !== null && !$transmittedDate) {
    $existing = getDb()->prepare('SELECT transmitted_to_mayor_date FROM agenda_items WHERE id = :id');
    $existing->execute([':id' => $itemId]);
    $row = $existing->fetch();
    if (!$row || !$row['transmitted_to_mayor_date']) {
        jsonError('Cannot record a mayor action before a transmittal date is set.', 422);
    }
}
// LOOPHOLE CHECK: a mayor action date can't be before the transmittal date.
if ($mayorAction !== null && $mayorActionDate && $transmittedDate && $mayorActionDate < $transmittedDate) {
    jsonError('Mayor action date cannot be earlier than the transmittal date.', 422);
}

$db = getDb();
$check = $db->prepare('SELECT id FROM agenda_items WHERE id = :id AND is_archived = 0');
$check->execute([':id' => $itemId]);
if (!$check->fetch()) {
    jsonError('Agenda item not found.', 404);
}

$fields = [];
$params = [':id' => $itemId];

if ($transmittedDate !== null) { $fields[] = 'transmitted_to_mayor_date = :td'; $params[':td'] = $transmittedDate; }
if ($windowDays !== null)      { $fields[] = 'mayor_action_window_days = :wd'; $params[':wd'] = $windowDays; }
if (array_key_exists('mayor_action', $b)) { $fields[] = 'mayor_action = :ma'; $params[':ma'] = $mayorAction; }
if (array_key_exists('mayor_action_date', $b)) { $fields[] = 'mayor_action_date = :mad'; $params[':mad'] = $mayorActionDate; }
if ($mayorAction !== null) { $fields[] = 'mayor_action_notes = :notes'; $params[':notes'] = $mayorActionNotes; }

if (!$fields) {
    jsonError('Nothing to update.', 400);
}

$sql = 'UPDATE agenda_items SET ' . implode(', ', $fields) . ' WHERE id = :id';
$db->prepare($sql)->execute($params);

logAudit('update_mayor_status', 'agenda_item', $itemId, "Updated by {$user['full_name']}: " . json_encode($b));

jsonSuccess(['item_id' => $itemId]);
