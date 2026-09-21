<?php
/**
 * notify-stakeholder.php — emails the session notice to ONE person.
 *
 * The bulk endpoint (send-notifications.php) is deliberately once-per-meeting
 * and idempotent, which is right for the official announcement but leaves no
 * answer to the ordinary situations that follow it: a councilor says the
 * notice never arrived, an address was corrected after the fact, or a
 * resource person needs the details again the day before a hearing. Without
 * this, the only route was the undo-and-resend-to-everyone path, which
 * re-announces the meeting to the whole list to reach one person.
 *
 * Admin-only, matching who may send the bulk notice, and every send is
 * recorded in the audit trail.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/meeting_notice.php';

$admin = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$stakeholderId = isset($b['id']) ? (int) $b['id'] : 0;
if (!$stakeholderId) {
    jsonError('id is required.', 400);
}

$db = getDb();
$stmt = $db->prepare(
    'SELECT ms.id, ms.stakeholder_name, ms.email, m.session_id
     FROM meeting_stakeholders ms
     JOIN meetings m ON m.id = ms.meeting_id
     WHERE ms.id = :id'
);
$stmt->execute([':id' => $stakeholderId]);
$row = $stmt->fetch();

if (!$row) {
    jsonError('Stakeholder not found.', 404);
}
if (empty($row['email'])) {
    jsonError('This contact has no email address on file — remove and re-add them with one.', 422);
}

$meeting = loadMeetingForNotice($db, $row['session_id']);
if (!$meeting) {
    jsonError('The session for this meeting is no longer available.', 404);
}
if (in_array($meeting['status'], ['Completed', 'Cancelled'], true)) {
    jsonError('That session is already ' . strtolower($meeting['status']) . ' — there is nothing to notify anyone about.', 409);
}

$result = sendEmail(
    $row['email'],
    sessionNoticeSubject($meeting, true),
    sessionNoticeBody($meeting, $row['stakeholder_name'])
);

logAudit('notify_stakeholder', 'meeting', $row['session_id'],
    "Notice sent to {$row['stakeholder_name']} <{$row['email']}> by {$admin['full_name']}"
    . ($result['success'] ? '' : ' — FAILED: ' . $result['error']));

if (!$result['success']) {
    jsonError('Could not send: ' . $result['error'], 502);
}

jsonSuccess([
    'id'      => $stakeholderId,
    'sent_to' => $row['email'],
    'message' => "Notice sent to {$row['stakeholder_name']} <{$row['email']}>.",
]);
