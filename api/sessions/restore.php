<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/conflict_detection.php';

$user = requireApiRole('admin');
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
$check = $db->prepare(
    'SELECT id, is_deleted, session_date, session_time_24h, session_type, venue, committee, presiding_officer, status
     FROM sessions WHERE id = :id'
);
$check->execute([':id' => $sessionId]);
$row = $check->fetch();
if (!$row) {
    jsonError('Session not found.', 404);
}
if (!$row['is_deleted']) {
    jsonError('This session is not currently deleted.', 409);
}

$scheduleLockName = null;
$isActiveSchedule = in_array($row['status'], ['Scheduled', 'Rescheduled'], true);
$mustCheckRegularDay = $row['session_type'] === 'Regular Session' && $row['status'] !== 'Cancelled';
if ($mustCheckRegularDay) {
    $scheduleLockName = 'regular_session_date_' . $row['session_date'];
    $scheduleLock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $scheduleLock->execute([':lock_name' => $scheduleLockName]);
    if ((int) $scheduleLock->fetchColumn() !== 1) {
        jsonError('Could not reserve this date for the Regular Session. Please try again.', 503);
    }
}

if ($isActiveSchedule || $mustCheckRegularDay) {
    $conflicts = checkSessionConflicts(
        $db,
        $row['session_date'],
        $row['session_time_24h'],
        $row['venue'],
        $row['committee'],
        $row['presiding_officer'],
        $sessionId,
        $row['session_type']
    );
    if ($conflicts) {
        if ($scheduleLockName !== null) {
            $db->prepare('SELECT RELEASE_LOCK(:lock_name)')->execute([':lock_name' => $scheduleLockName]);
        }
        jsonError('This session cannot be restored because it conflicts with another schedule on that date.', 409, [
            'conflicts' => $conflicts,
        ]);
    }
}

$db->prepare(
    "UPDATE sessions SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = :id"
)->execute([':id' => $sessionId]);

if ($scheduleLockName !== null) {
    $db->prepare('SELECT RELEASE_LOCK(:lock_name)')->execute([':lock_name' => $scheduleLockName]);
}

logAudit('restore', 'session', $sessionId, "Restored by {$user['full_name']}");

jsonSuccess(['id' => $sessionId, 'restored' => true]);
