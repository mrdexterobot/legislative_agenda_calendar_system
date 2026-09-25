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
// If a cancelled session is moved to a new time without an explicit status,
// it becomes active again and must pass the same conflict checks.
if ($timeChanged && !isset($b['status'])) {
    $status = 'Rescheduled';
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
$readingStages = [];
if ($status === 'Completed') {
    // Keep reading history and completion in one transaction so a retry
    // cannot leave the session closed while its stages were not recorded.
    $readingStages = $b['reading_stages'] ?? [];
    if (!is_array($readingStages)) {
        jsonError('reading_stages must be an object keyed by agenda item ID.', 422);
    }

    $allowedStages = ['1st Reading', '2nd Reading', '3rd Reading — Passed', 'Committee Report Submitted'];
    foreach ($readingStages as $itemId => $stage) {
        if (!is_string($itemId) || ($stage !== '' && !in_array($stage, $allowedStages, true))) {
            jsonError('Each reading stage must be empty or one of the supported reading stages.', 422);
        }
    }

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

$conflictDetails = [];
$conflicts = [];
$replacedSessionIds = [];
$scheduleLockNames = [];
$releaseScheduleLocks = static function () use ($db, &$scheduleLockNames): void {
    foreach ($scheduleLockNames as $lockName) {
        $db->prepare('SELECT RELEASE_LOCK(:lock_name)')->execute([':lock_name' => $lockName]);
    }
    $scheduleLockNames = [];
};
$isActiveSchedule = in_array($status, ['Scheduled', 'Rescheduled'], true);
$mustCheckRegularDay = $existing['session_type'] === 'Regular Session' && $status !== 'Cancelled';

if ($mustCheckRegularDay) {
    $datesToLock = [$date];
    if (in_array($existing['status'], ['Scheduled', 'Rescheduled'], true)) {
        $datesToLock[] = $existing['session_date'];
    }
    $datesToLock = array_values(array_unique($datesToLock));
    sort($datesToLock, SORT_STRING);
    $lockStatement = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
    foreach ($datesToLock as $dateToLock) {
        $lockName = 'regular_session_date_' . $dateToLock;
        $lockStatement->execute([':lock_name' => $lockName]);
        if ((int) $lockStatement->fetchColumn() !== 1) {
            $releaseScheduleLocks();
            jsonError('Could not reserve this date for the Regular Session. Please try again.', 503);
        }
        $scheduleLockNames[] = $lockName;
    }
}

if ($isActiveSchedule || $mustCheckRegularDay) {
    $conflictDetails = findSessionConflictDetails(
        $db, $date, $time24h, $venue, $committee, $presidingOfficer, $sessionId, $existing['session_type']
    );
    $conflicts = array_column($conflictDetails, 'description');
    $completedRegularConflict = $mustCheckRegularDay
        && array_filter($conflictDetails, static fn(array $conflict): bool => $conflict['status'] === 'Completed');
    if ($completedRegularConflict || ($conflicts && (!$forceOverride || !$isActiveSchedule))) {
        $releaseScheduleLocks();
        $alternatives = $mustCheckRegularDay ? [] : suggestAlternativeTimes(
            $db, $date, $venue, $committee, $presidingOfficer, 3, $existing['session_type']
        );
        jsonError($completedRegularConflict
            ? 'A Regular Session has already been completed on this date; another cannot be scheduled.'
            : 'Scheduling conflict detected.', 409, [
            'conflicts' => $conflicts,
            'conflicting_sessions' => $conflictDetails,
            'alternatives' => $alternatives,
            'can_replace' => $isActiveSchedule && !$completedRegularConflict,
        ]);
    }
    if ($forceOverride && $isActiveSchedule) {
        $replacedSessionIds = array_column($conflictDetails, 'session_id');
    }
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

$generatedDeadlines = [];

if ($completionNotes !== null) {
    $db->beginTransaction();
    try {
        // Lock the session and meeting row so two completion clicks cannot
        // both pass the checks and mutate the same historical record.
        $lockSession = $db->prepare('SELECT status FROM sessions WHERE id = :id FOR UPDATE');
        $lockSession->execute([':id' => $sessionId]);
        $lockedSession = $lockSession->fetch();
        if (!$lockedSession || $lockedSession['status'] === 'Completed') {
            $db->rollBack();
            $releaseScheduleLocks();
            jsonError('Completed sessions are historical records and cannot be edited.', 409);
        }

        $meetingStmt = $db->prepare(
            'SELECT notifications_sent FROM meetings WHERE session_id = :session_id FOR UPDATE'
        );
        $meetingStmt->execute([':session_id' => $sessionId]);
        $meeting = $meetingStmt->fetch();
        if (!$meeting || !$meeting['notifications_sent']) {
            $db->rollBack();
            $releaseScheduleLocks();
            jsonError('Send the meeting notification from Meeting Coordination before marking this session Completed.', 409);
        }

        $attachedStmt = $db->prepare(
            'SELECT sai.agenda_item_id
             FROM session_agenda_items sai
             WHERE sai.session_id = :session_id
             FOR UPDATE'
        );
        $attachedStmt->execute([':session_id' => $sessionId]);
        $attachedItemIds = array_column($attachedStmt->fetchAll(), 'agenda_item_id');

        $unknownItems = array_diff(array_keys($readingStages), $attachedItemIds);
        if ($unknownItems) {
            $db->rollBack();
            $releaseScheduleLocks();
            jsonError('Reading stages may only be supplied for agenda items attached to this session.', 422);
        }

        $db->prepare("UPDATE sessions SET $fields WHERE id = :id")->execute($params);

        foreach ($attachedItemIds as $itemId) {
            $stage = $readingStages[$itemId] ?? '';
            if ($stage === '') {
                continue;
            }

            // Idempotent inside the transaction: a retry does not create a
            // duplicate route-slip entry or a duplicate statutory deadline.
            $duplicateStmt = $db->prepare(
                'SELECT id FROM readings WHERE agenda_item_id = :item_id AND stage = :stage LIMIT 1'
            );
            $duplicateStmt->execute([':item_id' => $itemId, ':stage' => $stage]);
            if ($duplicateStmt->fetch()) {
                continue;
            }

            $maxOrderStmt = $db->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) FROM readings WHERE agenda_item_id = :item_id'
            );
            $maxOrderStmt->execute([':item_id' => $itemId]);
            $maxOrder = (int) $maxOrderStmt->fetchColumn();

            $db->prepare(
                'INSERT INTO readings (agenda_item_id, stage, reading_date, sort_order)
                 VALUES (:item_id, :stage, :reading_date, :sort_order)'
            )->execute([
                ':item_id'     => $itemId,
                ':stage'       => $stage,
                ':reading_date' => $existing['session_date'],
                ':sort_order'  => $maxOrder + 1,
            ]);

            if ($stage === '3rd Reading — Passed') {
                $dueDate = date('Y-m-d', strtotime($existing['session_date'] . ' +30 days'));
                $deadlineId = 'DL-' . strtoupper(bin2hex(random_bytes(3)));
                $reason = "Auto-generated because $itemId reached \"3rd Reading — Passed\" on "
                    . date('M j, Y', strtotime($existing['session_date'])) . " (session $sessionId).";
                $db->prepare(
                    "INSERT INTO deadlines (id, label, related_item_id, deadline_type, due_date, status, is_statutory, auto_generated, generation_reason)
                     VALUES (:id, :label, :item_id, 'SP Review (Sec. 56)', :due_date, 'Scheduled', 1, 1, :reason)"
                )->execute([
                    ':id'       => $deadlineId,
                    ':label'    => "Sangguniang Panlalawigan review window (Sec. 56) — $itemId",
                    ':item_id'  => $itemId,
                    ':due_date' => $dueDate,
                    ':reason'   => $reason,
                ]);
                $generatedDeadlines[] = ['id' => $deadlineId, 'due_date' => $dueDate];
            }
        }

        // A completed session is no longer the current scheduling handoff.
        // Items without a passed 3rd reading become visible to Agenda Prep
        // again; the Hub excludes items whose reading history says passed.
        if ($attachedItemIds) {
            $itemPlaceholders = implode(',', array_fill(0, count($attachedItemIds), '?'));
            $db->prepare("UPDATE agenda_items SET ready_for_scheduling = 0 WHERE id IN ($itemPlaceholders)")
               ->execute($attachedItemIds);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $releaseScheduleLocks();
        error_log('Failed to complete session: ' . $e->getMessage());
        jsonError('Failed to complete the session. Please try again.', 500);
    }
} else {
    if ($isActiveSchedule) {
        $db->beginTransaction();
        try {
            $conflictDetails = findSessionConflictDetails(
                $db, $date, $time24h, $venue, $committee, $presidingOfficer, $sessionId, $existing['session_type']
            );
            $conflicts = array_column($conflictDetails, 'description');
            $completedRegularConflict = $existing['session_type'] === 'Regular Session'
                && array_filter($conflictDetails, static fn(array $conflict): bool => $conflict['status'] === 'Completed');
            if ($completedRegularConflict || ($conflicts && !$forceOverride)) {
                $db->rollBack();
                $releaseScheduleLocks();
                jsonError($completedRegularConflict
                    ? 'A Regular Session has already been completed on this date; another cannot be scheduled.'
                    : 'Scheduling conflict detected.', 409, [
                    'conflicts' => $conflicts,
                    'conflicting_sessions' => $conflictDetails,
                    'alternatives' => $existing['session_type'] === 'Regular Session'
                        ? []
                        : suggestAlternativeTimes($db, $date, $venue, $committee, $presidingOfficer, 3, $existing['session_type']),
                    'can_replace' => $isActiveSchedule && !$completedRegularConflict,
                ]);
            }

            $replacedSessionIds = $forceOverride ? array_column($conflictDetails, 'session_id') : [];
            if ($replacedSessionIds) {
                $placeholders = implode(',', array_fill(0, count($replacedSessionIds), '?'));
                $db->prepare(
                    "UPDATE sessions SET status = 'Cancelled'
                     WHERE id IN ($placeholders) AND status IN ('Scheduled', 'Rescheduled') AND is_deleted = 0"
                )->execute($replacedSessionIds);
            }
            $db->prepare("UPDATE sessions SET $fields WHERE id = :id")->execute($params);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $releaseScheduleLocks();
            error_log('Failed to replace conflicting schedule: ' . $e->getMessage());
            jsonError('Failed to update the session. Please try again.', 500);
        }
    } else {
        $db->prepare("UPDATE sessions SET $fields WHERE id = :id")->execute($params);
    }
}

$releaseScheduleLocks();

$note = $timeChanged ? "Rescheduled by {$user['full_name']}" : "Updated by {$user['full_name']}";
if ($replacedSessionIds) $note .= ' (replaced conflicting sessions: ' . implode(', ', $replacedSessionIds) . ')';
if ($completionNotes !== null) $note .= " — marked Completed: $completionNotes";
logAudit('update', 'session', $sessionId, $note);
foreach ($replacedSessionIds as $replacedSessionId) {
    logAudit('replace', 'session', $replacedSessionId,
        "Cancelled after being replaced by {$sessionId}; action by {$user['full_name']}.");
}

jsonSuccess([
    'id'                   => $sessionId,
    'status'               => $status,
    'conflicts_overridden' => $conflicts,
    'generated_deadlines'  => $generatedDeadlines,
]);
