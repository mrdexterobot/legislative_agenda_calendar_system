<?php
/**
 * receive-confirmation.php
 *
 * ============================================================================
 * NOT CURRENTLY CALLED BY ANYTHING IN THIS BUILD. This is here to document
 * the RECEIVING side of the integration contract.
 * ============================================================================
 *
 * api/sessions/create.php currently gets its "confirmation" synchronously
 * and instantly from includes/integration/session_mgmt_stub.php (a mock
 * function call, not a network request) — because no real peer system
 * exists yet to call us back asynchronously.
 *
 * In a real integration, the flow would instead be:
 *   1. We send the proposed schedule to the peer system and get back an
 *      immediate "received, will validate" acknowledgement (not a full
 *      confirmation yet).
 *   2. Some time later — after THEY check attendee availability and
 *      agenda readiness on their end — their system calls THIS endpoint
 *      with the actual confirmation.
 *   3. This endpoint would then update meetings.attendees_confirmed / agenda_confirmed,
 *      exactly like the mock does today, unblocking Meeting Coordination's
 *      "send notifications" step.
 *
 * Kept here, fully written and token-protected, so the interface contract
 * is demonstrable to the panel even though nothing calls it yet.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/integration_auth.php';
require_once __DIR__ . '/../../includes/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$token = requireIntegrationToken();
$b = getJsonBody();

$sessionId = trim($b['session_id'] ?? '');
$status    = $b['status'] ?? '';

if ($sessionId === '' || !in_array($status, ['confirmed', 'rejected'], true)) {
    jsonError('session_id and status ("confirmed" or "rejected") are required.', 400);
}

$db = getDb();
$check = $db->prepare('SELECT id FROM meetings WHERE session_id = :s');
$check->execute([':s' => $sessionId]);
$meeting = $check->fetch();

if (!$meeting) {
    jsonError('No meeting record found for this session.', 404);
}

if ($status === 'confirmed') {
    // Real peer system would ideally send these as separate fields (a
    // partial confirmation — e.g. attendees ready but agenda materials
    // still pending — is a real possible state); default both to true
    // for a bare {"status":"confirmed"} payload for simplicity.
    $attendeesConfirmed = array_key_exists('attendees_ready', $b) ? (!empty($b['attendees_ready']) ? 1 : 0) : 1;
    $agendaConfirmed    = array_key_exists('agenda_ready', $b) ? (!empty($b['agenda_ready']) ? 1 : 0) : 1;

    $db->prepare(
        "UPDATE meetings SET attendees_confirmed = :a, agenda_confirmed = :g, external_confirmed_at = NOW() WHERE session_id = :s"
    )->execute([':a' => $attendeesConfirmed, ':g' => $agendaConfirmed, ':s' => $sessionId]);
} else {
    $db->prepare(
        "UPDATE meetings SET attendees_confirmed = 0, agenda_confirmed = 0, external_confirmed_at = NULL WHERE session_id = :s"
    )->execute([':s' => $sessionId]);
}

logAudit('external_confirmation', 'meeting', $sessionId, "Status: $status, from: {$token['label']}");

jsonSuccess(['session_id' => $sessionId, 'status' => $status]);
