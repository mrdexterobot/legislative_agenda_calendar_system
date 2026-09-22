<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/conflict_detection.php';

$user = requireApiAuth(); // staff or admin
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$sessionId = trim($b['id'] ?? '');
if ($sessionId === '') {
    jsonError('id is required.', 400);
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM sessions WHERE id = :id');
$stmt->execute([':id' => $sessionId]);
$existing = $stmt->fetch();
if (!$existing) {
    jsonError('Session not found.', 404);
}
if ($existing['is_deleted']) {
    jsonError('This session has been removed — restore it first (admin only) before making changes.', 409);
}
if ($existing['status'] === 'Completed') {
    jsonError('Completed sessions are historical records and cannot be edited.', 409);
}

$date             = $b['date'] ?? $existing['session_date'];
$timeDisplay      = $b['time'] ?? $existing['session_time'];
$venue            = $b['venue'] ?? $existing['venue'];
$presidingOfficer = $b['presiding_officer'] ?? $existing['presiding_officer'];
$committee        = array_key_exists('committee', $b) ? $b['committee'] : $existing['committee'];
$status           = $b['status'] ?? $existing['status'];
$sequenceNumber   = array_key_exists('sequence_number', $b)
    ? ($b['sequence_number'] !== '' && $b['sequence_number'] !== null ? (int) $b['sequence_number'] : null)
    : $existing['sequence_number'];
$forceOverride    = !empty($b['override_conflicts']);

$timeChanged = ($timeDisplay !== $existing['session_time']) || ($date !== $existing['session_date']) || ($venue !== $existing['venue']);
$time24h = $existing['session_time_24h'];
if ($timeChanged) {
    $ts = strtotime($timeDisplay);
    if ($ts === false) {
        jsonError('Time could not be understood — try a format like "9:00 AM".', 422);
    }
    $time24h = date('H:i:s', $ts);
}

if (!in_array($status, ['Scheduled', 'Rescheduled', 'Completed', 'Cancelled'], true)) {
    jsonError('Invalid status.', 422);
}

// EVIDENCE FIX: marking a session Completed used to be a bare status flip
// with nothing backing it up — no record of what actually happened at the
// session or who attests to it. Mirrors the same requirement already
// enforced for Deadline Tracking completions (api/deadlines/update.php).
// A supporting file (minutes excerpt, attendance sheet scan, etc.) must be
// attached beforehand via api/evidence/upload.php (entity_type=session,
// entity_id=$sessionId). Enforce that here as well as in the UI so a direct
// API request cannot mark a session complete without documentary evidence.
$completionNotes = null;
if ($status === 'Completed') {
    $completionNotes = trim($b['completion_notes'] ?? '');
    if ($completionNotes === '') {
        jsonError('Please add a short note on what happened at this session (e.g. quorum, key outcomes) before marking it Completed.', 422);
    }
    $evidenceStmt = $db->prepare(
        "SELECT id FROM evidence_attachments
         WHERE entity_type = 'session' AND entity_id = :id
         LIMIT 1"
    );
    $evidenceStmt->execute([':id' => $sessionId]);
    if (!$evidenceStmt->fetch()) {
        jsonError('Please attach a supporting document (e.g. minutes excerpt or attendance sheet) before marking this session Completed.', 422);
    }
}

$conflicts = [];
if ($timeChanged) {
    $conflicts = checkSessionConflicts($db, $date, $time24h, $venue, $committee, $presidingOfficer, $sessionId);
    if ($conflicts && !$forceOverride) {
        $alternatives = suggestAlternativeTimes($db, $date, $venue, $committee, $presidingOfficer);
        jsonError('Scheduling conflict detected.', 409, ['conflicts' => $conflicts, 'alternatives' => $alternatives]);
    }
}

// If the time/date/venue changed, mark as Rescheduled unless caller
// explicitly set a different status.
if ($timeChanged && !isset($b['status'])) {
    $status = 'Rescheduled';
}

$fields = "session_date = :date, session_time = :time_display, session_time_24h = :time24h,
        venue = :venue, presiding_officer = :presiding, committee = :committee, sequence_number = :sequence, status = :status";
$params = [
    ':date'         => $date,
    ':time_display' => $timeDisplay,
    ':time24h'      => $time24h,
    ':venue'        => $venue,
    ':presiding'    => $presidingOfficer,
    ':committee'    => $committee,
    ':sequence'     => $sequenceNumber,
    ':status'       => $status,
    ':id'           => $sessionId,
];

if ($completionNotes !== null) {
    $fields .= ", completion_notes = :notes, completed_by = :completed_by, completed_at = NOW()";
    $params[':notes'] = $completionNotes;
    $params[':completed_by'] = $user['full_name'];
}

$db->prepare("UPDATE sessions SET $fields WHERE id = :id")->execute($params);

$note = $timeChanged ? "Rescheduled by {$user['full_name']}" : "Updated by {$user['full_name']}";
if ($conflicts) $note .= ' (conflicts overridden: ' . implode(' | ', $conflicts) . ')';
if ($completionNotes !== null) $note .= " — marked Completed: $completionNotes";
logAudit('update', 'session', $sessionId, $note);

jsonSuccess(['id' => $sessionId, 'status' => $status, 'conflicts_overridden' => $conflicts]);
