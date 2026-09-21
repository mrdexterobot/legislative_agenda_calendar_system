<?php
/**
 * undo-notifications.php — admin-only correction for a mistaken "Send
 * notifications" click (wrong venue announced, sent before the checklist
 * was actually ready, etc). Resets the confirmation flags so the meeting
 * checklist unlocks again in js/meeting-coordination.js and the Send
 * button can reappear once staff actually fix the underlying issue —
 * this does NOT re-send a "never mind" notice to whoever already got the
 * original one (no real SMTP exists in this build — see
 * api/meetings/send-notifications.php — so there's nothing to retract),
 * it only corrects the SYSTEM's record of what happened.
 *
 * Requires a reason, same evidence-required pattern as marking a deadline
 * or session complete — an undo of an official action is exactly the kind
 * of thing that needs an audit trail, maybe more so.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$admin = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$sessionId = trim($b['session_id'] ?? '');
$reason    = trim($b['reason'] ?? '');

if ($sessionId === '') {
    jsonError('session_id is required.', 400);
}
if ($reason === '') {
    jsonError('Please explain why this is being undone before proceeding.', 422);
}

$db = getDb();
$stmt = $db->prepare('SELECT id, notifications_sent, notifications_sent_by FROM meetings WHERE session_id = :sid');
$stmt->execute([':sid' => $sessionId]);
$meeting = $stmt->fetch();

if (!$meeting) {
    jsonError('No meeting record found for this session.', 404);
}
if (!$meeting['notifications_sent']) {
    jsonError('Notifications were not marked as sent for this meeting — nothing to undo.', 409);
}

$db->prepare(
    "UPDATE meetings SET notifications_sent = 0, notifications_sent_at = NULL, notifications_sent_by = NULL WHERE session_id = :sid"
)->execute([':sid' => $sessionId]);

logAudit('undo_notifications', 'meeting', $sessionId, "Undone by admin {$admin['full_name']} (originally sent by {$meeting['notifications_sent_by']}). Reason: $reason");

jsonSuccess(['session_id' => $sessionId, 'undone' => true]);
