<?php
/**
 * send-notifications.php — the official announcement for a meeting, sent once.
 *
 * Gates, in order: not already sent; the peer system has confirmed attendees
 * and agenda materials; the staff logistics checklist is complete. All three
 * are enforced here as well as in the UI, since the endpoint has to defend
 * itself against a direct call regardless of what the page shows.
 *
 * The message itself is built by includes/meeting_notice.php, shared with the
 * per-person send (api/meetings/notify-stakeholder.php) and the catch-up
 * notice in api/meetings/add-stakeholder.php, so all three say the same thing.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/meeting_notice.php';

$user = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$sessionId = trim($b['session_id'] ?? '');
if ($sessionId === '') {
    jsonError('session_id is required.', 400);
}

$db = getDb();
$meeting = loadMeetingForNotice($db, $sessionId);

if (!$meeting) {
    jsonError('No meeting record found for this session.', 404);
}
if (in_array($meeting['status'], ['Completed', 'Cancelled'], true)) {
    jsonError('Notifications cannot be sent for a ' . strtolower($meeting['status']) . ' session.', 409);
}

// ---- Gate 1: don't double-send ----
if ($meeting['notifications_sent']) {
    jsonError('Notifications for this meeting were already sent on ' . $meeting['notifications_sent_at']
        . '. Use the send button beside an individual name to reach one person, or undo the send first.', 409);
}

// ---- Gate 2: confirmation from the (mocked) peer system ----
if (!$meeting['attendees_confirmed'] || !$meeting['agenda_confirmed']) {
    $missing = [];
    if (!$meeting['attendees_confirmed']) $missing[] = 'attendee list';
    if (!$meeting['agenda_confirmed']) $missing[] = 'agenda materials';
    jsonError('Cannot send notifications yet — still waiting on ' . implode(' and ', $missing)
        . ' confirmation from the Session and Legislative Meeting Management System.', 409);
}

// ---- Gate 3: the staff-managed logistics checklist ----
$logisticsMissing = [];
if (!$meeting['venue_booked']) $logisticsMissing[] = 'venue booked';
if (!$meeting['documents_distributed']) $logisticsMissing[] = 'documents distributed';
if ($meeting['minutes_status'] !== 'Finalized') $logisticsMissing[] = 'minutes finalized';
if ($logisticsMissing) {
    jsonError('Cannot send notifications yet — the logistics checklist is incomplete: ' . implode(', ', $logisticsMissing) . '.', 409);
}

$stakeholderStmt = $db->prepare('SELECT id, stakeholder_name, email FROM meeting_stakeholders WHERE meeting_id = :id');
$stakeholderStmt->execute([':id' => $meeting['id']]);
$stakeholders = $stakeholderStmt->fetchAll();

if (!$stakeholders) {
    jsonError('Nobody is on the notification list for this meeting — add at least one recipient first.', 422);
}

$emailable = array_filter($stakeholders, fn($s) => !empty($s['email']));
$skipped   = array_filter($stakeholders, fn($s) => empty($s['email']));

if (!$emailable) {
    jsonError('None of this meeting\'s recipients have an email on file yet — add one before sending.', 422);
}

$subject = sessionNoticeSubject($meeting);

$sentTo = [];
$failed = [];
foreach ($emailable as $s) {
    $result = sendEmail($s['email'], $subject, sessionNoticeBody($meeting, $s['stakeholder_name']));
    if ($result['success']) {
        $sentTo[] = $s['stakeholder_name'] . " <{$s['email']}>";
    } else {
        $failed[] = $s['stakeholder_name'] . " <{$s['email']}>: " . $result['error'];
    }
}

// LOOPHOLE FIX: the meeting used to be marked as notified even when every
// single send had failed, which locked the send button behind the
// already-sent gate with nobody actually notified. Only record the send when
// at least one message was accepted by the relay.
if (!$sentTo) {
    logAudit('send_notifications', 'meeting', $sessionId,
        "Attempted by {$user['full_name']} — ALL sends failed: " . implode(' | ', $failed));
    jsonError('Nothing was sent — every delivery failed. ' . implode(' | ', $failed), 502, ['failed' => $failed]);
}

$db->prepare(
    'UPDATE meetings SET notifications_sent = 1, notifications_sent_at = NOW(), notifications_sent_by = :by WHERE session_id = :session_id'
)->execute([':by' => $user['full_name'], ':session_id' => $sessionId]);

$auditNote = "Sent by {$user['full_name']} to " . implode(', ', $sentTo);
if ($failed)  $auditNote .= ' | FAILED: ' . implode(', ', $failed);
if ($skipped) $auditNote .= ' | Skipped (no email on file): ' . implode(', ', array_column($skipped, 'stakeholder_name'));
logAudit('send_notifications', 'meeting', $sessionId, $auditNote);

jsonSuccess([
    'session_id'       => $sessionId,
    'sent_to'          => $sentTo,
    'failed'           => $failed,
    'skipped_no_email' => array_values(array_column($skipped, 'stakeholder_name')),
]);
