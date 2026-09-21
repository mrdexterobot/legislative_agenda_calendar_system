<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/conflict_detection.php';
require_once __DIR__ . '/../../includes/integration/session_mgmt_stub.php';

$user = requireApiAuth(); // staff or admin
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();

$date             = $b['date'] ?? '';
$timeDisplay      = trim($b['time'] ?? '');       // e.g. "9:00 AM" — what the UI shows
$sessionType      = $b['type'] ?? '';
$venue            = trim($b['venue'] ?? '');
$presidingOfficer = trim($b['presiding_officer'] ?? '') ?: 'TBD';
$committee        = trim($b['committee'] ?? '') ?: null;
$sequenceNumber   = isset($b['sequence_number']) && $b['sequence_number'] !== '' ? (int) $b['sequence_number'] : null;
$agendaItemIds    = $b['agenda_item_ids'] ?? [];
$forceOverride    = !empty($b['override_conflicts']); // staff can proceed anyway, but it's logged

// ---- Validation ----
$errors = [];
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj) $errors[] = 'A valid date is required.';
$time24h = null;
if ($timeDisplay !== '') {
    $ts = strtotime($timeDisplay);
    if ($ts === false) {
        $errors[] = 'Time could not be understood — try a format like "9:00 AM".';
    } else {
        $time24h = date('H:i:s', $ts);
    }
} else {
    $errors[] = 'Time is required.';
}
if (!in_array($sessionType, ['Regular Session', 'Special Session', 'Committee Hearing', 'Public Hearing'], true)) {
    $errors[] = 'Session type must be one of the four defined types.';
}
if ($venue === '') $errors[] = 'Venue is required.';
if (!is_array($agendaItemIds)) $errors[] = 'agenda_item_ids must be an array.';

if ($errors) {
    jsonError('Please fix the following: ' . implode(' ', $errors), 422);
}

$db = getDb();

// ---- Conflict detection (venue / committee / presiding officer, same day) ----
$conflicts = checkSessionConflicts($db, $date, $time24h, $venue, $committee, $presidingOfficer);

if ($conflicts && !$forceOverride) {
    $alternatives = suggestAlternativeTimes($db, $date, $venue, $committee, $presidingOfficer);
    jsonError('Scheduling conflict detected.', 409, [
        'conflicts'    => $conflicts,
        'alternatives' => $alternatives,
    ]);
}

// ---- Validate referenced agenda items exist and aren't archived ----
if ($agendaItemIds) {
    $placeholders = implode(',', array_fill(0, count($agendaItemIds), '?'));
    $check = $db->prepare("SELECT id FROM agenda_items WHERE id IN ($placeholders) AND is_archived = 0");
    $check->execute($agendaItemIds);
    $found = $check->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($agendaItemIds, $found);
    if ($missing) {
        jsonError('These agenda item IDs were not found: ' . implode(', ', $missing), 422);
    }
}

$sessionId = 'SESS-' . strtoupper(bin2hex(random_bytes(3)));

$db->beginTransaction();
try {
    $db->prepare(
        "INSERT INTO sessions (id, session_date, session_time, session_time_24h, session_type, venue, presiding_officer, committee, sequence_number, status, created_by)
         VALUES (:id, :date, :time_display, :time24h, :type, :venue, :presiding, :committee, :sequence, 'Scheduled', :created_by)"
    )->execute([
        ':id'           => $sessionId,
        ':date'         => $date,
        ':time_display' => $timeDisplay,
        ':time24h'      => $time24h,
        ':type'         => $sessionType,
        ':venue'        => $venue,
        ':presiding'    => $presidingOfficer,
        ':committee'    => $committee,
        ':sequence'     => $sequenceNumber,
        ':created_by'   => $user['id'],
    ]);

    foreach ($agendaItemIds as $itemId) {
        $db->prepare("INSERT INTO session_agenda_items (session_id, agenda_item_id) VALUES (:s, :a)")
           ->execute([':s' => $sessionId, ':a' => $itemId]);
    }

    // Hand off the (now internally conflict-checked) proposed schedule to
    // the peer subsystem — MOCKED, see includes/integration/session_mgmt_stub.php.
    $outboundPayload = [
        'session_id' => $sessionId,
        'date'       => $date,
        'time'       => $timeDisplay,
        'venue'      => $venue,
        'type'       => $sessionType,
    ];

    // VISIBILITY: logging both halves as separate integration_events rows
    // makes the exchange inspectable afterward (and the UI shows them as a
    // live sequence right after scheduling — see js/calendar-scheduling.js).
    $db->prepare(
        "INSERT INTO integration_events (direction, event_type, related_session_id, summary, payload)
         VALUES ('outbound', 'proposed_schedule_sent', :sid, :summary, :payload)"
    )->execute([
        ':sid'     => $sessionId,
        ':summary' => "Sent proposed schedule for $sessionId ($sessionType, $date $timeDisplay at $venue) to the Session and Legislative Meeting Management System.",
        ':payload' => json_encode($outboundPayload),
    ]);

    $externalResponse = sendProposedScheduleToSessionSystem($outboundPayload);

    // session_mgmt_stub.php returns an ISO-8601 timestamp (date('c')), but a
    // MySQL TIMESTAMP column needs "Y-m-d H:i:s".
    $confirmedAtMysql = $externalResponse['status'] === 'confirmed'
        ? date('Y-m-d H:i:s', strtotime($externalResponse['confirmed_at']))
        : null;

    $db->prepare(
        "INSERT INTO integration_events (direction, event_type, related_session_id, summary, payload)
         VALUES ('inbound', 'confirmation_received', :sid, :summary, :payload)"
    )->execute([
        ':sid'     => $sessionId,
        ':summary' => "Received confirmation for $sessionId — attendees ready: " . ($externalResponse['attendees_ready'] ? 'yes' : 'no') . ", agenda materials ready: " . ($externalResponse['agenda_ready'] ? 'yes' : 'no') . ". (Simulated — no live peer system exists yet.)",
        ':payload' => json_encode($externalResponse),
    ]);

    $db->prepare(
        "INSERT INTO meetings (session_id, attendees_confirmed, agenda_confirmed, external_confirmed_at)
         VALUES (:s, :attendees, :agenda, :confirmed_at)"
    )->execute([
        ':s'            => $sessionId,
        ':attendees'    => !empty($externalResponse['attendees_ready']) ? 1 : 0,
        ':agenda'       => !empty($externalResponse['agenda_ready']) ? 1 : 0,
        ':confirmed_at' => $confirmedAtMysql,
    ]);
    $meetingId = (int) $db->lastInsertId();

    // ---- Notification list, seeded from real accounts ----
    // DESIGN FIX: a new meeting used to start with an empty stakeholder list,
    // so Meeting Coordination showed "None recorded" and the send step had
    // nothing to send to — the only rows that ever existed came from seed
    // data with invented department-alias addresses. Every registered,
    // active account already holds a verified email (users.email is NOT
    // NULL), and those accounts ARE the office: legislative staff, the
    // Secretary, and any councilor issued a login. Each new session's
    // meeting therefore starts with all of them on the list, linked by
    // user_id so the name and address are read from the real account rather
    // than typed. An admin can remove anyone who shouldn't be notified for a
    // particular hearing, and can still add external contacts (a councilor's
    // personal address, the Mayor's office) through "+ Add stakeholder".
    $activeUsers = $db->query(
        "SELECT id, full_name, email FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY full_name ASC"
    )->fetchAll();

    $stakeholderStmt = $db->prepare(
        "INSERT INTO meeting_stakeholders (meeting_id, stakeholder_name, email, user_id) VALUES (:mid, :name, :email, :uid)"
    );
    foreach ($activeUsers as $u) {
        $stakeholderStmt->execute([
            ':mid'   => $meetingId,
            ':name'  => $u['full_name'],
            ':email' => $u['email'],
            ':uid'   => $u['id'],
        ]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('Failed to create session: ' . $e->getMessage());
    jsonError('Failed to save the session. Please try again.', 500);
}

$auditNote = $conflicts
    ? "Saved WITH conflicts overridden by {$user['full_name']}: " . implode(' | ', $conflicts)
    : "Scheduled by {$user['full_name']}";
$auditNote .= ' — notification list seeded with ' . count($activeUsers) . ' active account(s).';
logAudit('create', 'session', $sessionId, $auditNote);

$eventsStmt = $db->prepare(
    "SELECT direction, event_type, summary, created_at FROM integration_events WHERE related_session_id = :sid ORDER BY id ASC"
);
$eventsStmt->execute([':sid' => $sessionId]);

jsonSuccess([
    'id'                    => $sessionId,
    'conflicts_overridden'  => $conflicts,
    'external_response'     => $externalResponse,
    'stakeholders_seeded'   => count($activeUsers),
    'integration_events'    => $eventsStmt->fetchAll(),
], 201);
