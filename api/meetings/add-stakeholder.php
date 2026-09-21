<?php
/**
 * add-stakeholder.php — admin-only, same gate as sending itself. Two modes:
 *   - user_id given: pulls full_name + email live from the users table
 *     (a REAL, verified account) — nothing hand-typed or guessable.
 *   - name + email given instead: an external contact who isn't a system
 *     user (a councilor's own address, a Mayor's-office contact). Validated
 *     as a real email format before being stored.
 *
 * NEW BEHAVIOUR: if this meeting's notifications have already gone out, the
 * person being added now receives the notice immediately. Previously they
 * were added to a list that would never be sent again — the send endpoint
 * refuses to run twice on the same meeting — so anyone added after the fact
 * silently got nothing, which is precisely the case where a late addition
 * matters most (a resource person invited two days before a hearing).
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
$meetingId = isset($b['meeting_id']) ? (int) $b['meeting_id'] : 0;
$userId    = isset($b['user_id']) && $b['user_id'] !== '' ? (int) $b['user_id'] : null;
$name      = trim($b['name'] ?? '');
$email     = trim($b['email'] ?? '');

if (!$meetingId) {
    jsonError('meeting_id is required.', 400);
}

$db = getDb();
$meetingCheck = $db->prepare('SELECT id, session_id, notifications_sent FROM meetings WHERE id = :id');
$meetingCheck->execute([':id' => $meetingId]);
$meetingRow = $meetingCheck->fetch();
if (!$meetingRow) {
    jsonError('Meeting not found.', 404);
}

if ($userId !== null) {
    $userStmt = $db->prepare('SELECT full_name, email FROM users WHERE id = :id AND is_active = 1');
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        jsonError('That user was not found or is not active.', 422);
    }
    $name = $user['full_name'];
    $email = $user['email'];
} else {
    if ($name === '') {
        jsonError('A name is required for an external contact.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('A valid email is required for an external contact.', 422);
    }
}

// Don't add the same person to the same meeting twice.
$dupCheck = $db->prepare(
    'SELECT id FROM meeting_stakeholders WHERE meeting_id = :mid AND (email = :email OR (user_id IS NOT NULL AND user_id = :uid))'
);
$dupCheck->execute([':mid' => $meetingId, ':email' => $email, ':uid' => $userId]);
if ($dupCheck->fetch()) {
    jsonError('This person is already on the notification list for this meeting.', 409);
}

$stmt = $db->prepare(
    'INSERT INTO meeting_stakeholders (meeting_id, stakeholder_name, email, user_id) VALUES (:mid, :name, :email, :uid)'
);
$stmt->execute([':mid' => $meetingId, ':name' => $name, ':email' => $email, ':uid' => $userId]);
$id = (int) $db->lastInsertId();

// ---- Catch-up notice ----
$notified = false;
$notifyError = null;

if ($meetingRow['notifications_sent']) {
    $meeting = loadMeetingForNotice($db, $meetingRow['session_id']);
    if ($meeting) {
        $result = sendEmail($email, sessionNoticeSubject($meeting), sessionNoticeBody($meeting, $name));
        $notified = $result['success'];
        $notifyError = $result['success'] ? null : $result['error'];
    }
}

logAudit('add', 'meeting_stakeholder', $name,
    "Added to meeting $meetingId by {$admin['full_name']}"
    . ($userId ? " (linked user #$userId)" : ' (external contact)')
    . ($meetingRow['notifications_sent']
        ? ($notified ? ' — catch-up notice emailed' : ' — catch-up notice FAILED: ' . $notifyError)
        : ''));

jsonSuccess([
    'id'            => $id,
    'name'          => $name,
    'email'         => $email,
    'notified'      => $notified,
    'notify_error'  => $notifyError,
    'message'       => $meetingRow['notifications_sent']
        ? ($notified
            ? "Added, and the session notice was emailed to $email straight away since this meeting was already announced."
            : "Added, but the catch-up notice could not be sent: $notifyError")
        : 'Added. They will be included when notifications are sent for this meeting.',
], 201);
