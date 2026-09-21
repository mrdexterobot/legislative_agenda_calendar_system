<?php
/**
 * add-reading.php
 *
 * Records the next stage in an agenda item's route slip (1st Reading,
 * 2nd Reading, 3rd Reading — Passed, Committee Report Submitted). This is
 * the missing piece that made "does the system know it passed 3rd
 * reading" impossible to answer before — nothing recorded readings past
 * the initial "Filed" entry added at encoding time.
 *
 * Intended call site: when a staff member marks a session "Completed"
 * (see api/sessions/update.php), the Calendar Scheduling UI prompts, for
 * each agenda item attached to that session, what reading stage (if any)
 * was reached — tying reading progression to an actual calendar event
 * instead of a disconnected manual log.
 *
 * When the stage recorded is "3rd Reading — Passed", this auto-generates
 * the Sec. 56 Sangguniang Panlalawigan review deadline (30 days out) —
 * because that IS a real, fixed statutory trigger point, unlike deadline
 * types such as "Committee Report", where the office's actual internal
 * timing rule hasn't been confirmed (see the note in deadline-tracking's
 * "new deadline" form instead — that stays manual on purpose).
 */

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
$itemId      = trim($b['item_id'] ?? '');
$stage       = $b['stage'] ?? '';
$readingDate = $b['reading_date'] ?? '';
$sessionId   = trim($b['session_id'] ?? '') ?: null;

$allowedStages = ['1st Reading', '2nd Reading', '3rd Reading — Passed', 'Committee Report Submitted'];
if (!in_array($stage, $allowedStages, true)) {
    jsonError('stage must be one of: ' . implode(', ', $allowedStages), 422);
}
if (!DateTime::createFromFormat('Y-m-d', $readingDate)) {
    jsonError('A valid reading_date is required.', 422);
}

$db = getDb();
$check = $db->prepare('SELECT id FROM agenda_items WHERE id = :id AND is_archived = 0');
$check->execute([':id' => $itemId]);
if (!$check->fetch()) {
    jsonError('Agenda item not found.', 404);
}

// LOOPHOLE CHECK: don't let the exact same stage get recorded twice for
// the same item (e.g. clicking the button twice, or re-completing a
// session that already had this recorded).
$dupCheck = $db->prepare('SELECT id FROM readings WHERE agenda_item_id = :id AND stage = :stage');
$dupCheck->execute([':id' => $itemId, ':stage' => $stage]);
if ($dupCheck->fetch()) {
    jsonError("This item already has a \"$stage\" entry recorded.", 409);
}

$maxOrderStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM readings WHERE agenda_item_id = :id');
$maxOrderStmt->execute([':id' => $itemId]);
$maxOrder = (int) $maxOrderStmt->fetchColumn();

$db->beginTransaction();
try {
    $db->prepare(
        "INSERT INTO readings (agenda_item_id, stage, reading_date, sort_order) VALUES (:id, :stage, :date, :order)"
    )->execute([
        ':id'    => $itemId,
        ':stage' => $stage,
        ':date'  => $readingDate,
        ':order' => $maxOrder + 1,
    ]);

    $generatedDeadline = null;
    if ($stage === '3rd Reading — Passed') {
        $dueDate = date('Y-m-d', strtotime($readingDate . ' +30 days'));
        $deadlineId = 'DL-' . strtoupper(bin2hex(random_bytes(3)));
        $reason = "Auto-generated because $itemId reached \"3rd Reading — Passed\" on " . date('M j, Y', strtotime($readingDate)) . ($sessionId ? " (session $sessionId)" : '') . ".";
        $db->prepare(
            "INSERT INTO deadlines (id, label, related_item_id, deadline_type, due_date, status, is_statutory, auto_generated, generation_reason)
             VALUES (:id, :label, :item, 'SP Review (Sec. 56)', :due, 'Scheduled', 1, 1, :reason)"
        )->execute([
            ':id'     => $deadlineId,
            ':label'  => "Sangguniang Panlalawigan review window (Sec. 56) — $itemId",
            ':item'   => $itemId,
            ':due'    => $dueDate,
            ':reason' => $reason,
        ]);
        $generatedDeadline = ['id' => $deadlineId, 'due_date' => $dueDate, 'reason' => $reason];
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('Failed to record reading: ' . $e->getMessage());
    jsonError('Failed to save. Please try again.', 500);
}

logAudit('add_reading', 'agenda_item', $itemId, "Stage \"$stage\" recorded by {$user['full_name']}" . ($sessionId ? " (session $sessionId)" : '') . ($generatedDeadline ? " — auto-generated Sec. 56 deadline {$generatedDeadline['id']}" : ''));

jsonSuccess(['item_id' => $itemId, 'stage' => $stage, 'generated_deadline' => $generatedDeadline]);
