<?php
/**
 * hub-push-agenda.php
 *
 * Part of the Integration Hub — plays the role of the Agenda Preparation
 * Module PUSHING a compiled proposed agenda to us, rather than us having
 * nothing to base scheduling decisions on. Marks the SELECTED confirmed-
 * priority, not-yet-transmitted items as ready_for_scheduling — this is
 * what Calendar Scheduling's "items to attach" checklist actually reads
 * from (see api/agenda-items/list.php / js/calendar-scheduling.js).
 *
 * SELECTION FIX: this used to push every eligible item in one shot with
 * no way to choose a subset — a real Agenda Preparation Module compiling
 * a specific session's agenda wouldn't hand over its entire backlog at
 * once. item_ids is now required and validated against what's actually
 * eligible, so this can't be used to push items that were never staff-
 * confirmed or are already transmitted.
 *
 * Still mocked: no real Agenda Preparation Module exists yet. This is the
 * demonstrable stand-in for that push, same spirit as
 * includes/integration/session_mgmt_stub.php.
 */

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
$itemIds = $b['item_ids'] ?? [];
if (!is_array($itemIds) || !count($itemIds)) {
    jsonError('Select at least one item to push.', 400);
}

$db = getDb();

$placeholders = implode(',', array_fill(0, count($itemIds), '?'));
$stmt = $db->prepare(
    "SELECT id, title FROM agenda_items
     WHERE id IN ($placeholders)
       AND confirmed_priority IS NOT NULL AND transmitted_to_mayor_date IS NULL
       AND is_archived = 0 AND ready_for_scheduling = 0"
);
$stmt->execute(array_values($itemIds));
$eligible = $stmt->fetchAll();

// LOOPHOLE CHECK: don't silently drop items the client asked for but that
// aren't actually eligible (already pushed, not yet confirmed, archived,
// or transmitted) — tell the caller so the UI can explain it rather than
// quietly pushing fewer items than the user selected.
$foundIds = array_column($eligible, 'id');
$skipped = array_values(array_diff($itemIds, $foundIds));

if (!$eligible) {
    jsonSuccess(['pushed' => [], 'skipped' => $skipped, 'message' => 'None of the selected items are eligible to push (already pushed, not yet confirmed, or already transmitted).']);
}

$db->beginTransaction();
try {
    $ids = $foundIds;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE agenda_items SET ready_for_scheduling = 1 WHERE id IN ($ph)")->execute($ids);

    $summary = "Agenda Preparation Module sent a proposed agenda covering " . count($eligible) . " selected item(s): " . implode(', ', $ids) . '.';
    $db->prepare(
        "INSERT INTO integration_events (direction, event_type, related_session_id, summary, payload)
         VALUES ('inbound', 'proposed_agenda_received', NULL, :summary, :payload)"
    )->execute([
        ':summary' => $summary,
        ':payload' => json_encode(['items' => $eligible]),
    ]);

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('Failed to push agenda: ' . $e->getMessage());
    jsonError('Failed to save. Please try again.', 500);
}

logAudit('hub_push_agenda', 'agenda_items', null, "Pushed by {$user['full_name']}: " . implode(', ', array_column($eligible, 'id')));

jsonSuccess(['pushed' => $eligible, 'skipped' => $skipped]);
