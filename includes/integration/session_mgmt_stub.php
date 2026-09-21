<?php
/**
 * session_mgmt_stub.php
 *
 * ============================================================================
 * THIS IS A MOCK. There is no live connection to another group's subsystem.
 * ============================================================================
 *
 * Per the capstone's pre-oral defense requirement, live cross-subsystem
 * integration is not required at this stage. This file exists so the
 * INTERFACE CONTRACT — what we send, what we expect back — is fully
 * designed, documented, and demonstrable, without depending on another
 * team's system being ready.
 *
 * Real-world replacement: swap the body of sendProposedScheduleToSessionSystem()
 * for an actual HTTP call (cURL, same pattern as includes/ai_priority.php)
 * to the real Session and Legislative Meeting Management System's endpoint.
 * The function signature and return shape are designed to stay the same,
 * so nothing else in this codebase would need to change.
 *
 * FLOW THIS SIMULATES:
 *   1. Calendar Scheduling confirms a session has no internal conflicts
 *      (see api/sessions/create.php) and a staff member saves it.
 *   2. This function is called with that session's details — standing in
 *      for "hand the proposed schedule to the Session and Legislative
 *      Meeting Management System for attendee/agenda validation."
 *   3. It returns a mock confirmation (attendees set, agenda ready).
 *   4. Meeting Coordination (api/meetings/*) only allows notifications to
 *      be sent once attendees_confirmed and agenda_confirmed = 1 — see
 *      api/meetings/send-notifications.php.
 */

function sendProposedScheduleToSessionSystem(array $session): array {
    // --- MOCK BEHAVIOR ---
    // In a real integration this would be something like:
    //   $ch = curl_init('https://session-mgmt-system.example/api/schedule/propose');
    //   curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($session));
    //   ... send, wait for their response, return it ...
    //
    // Since that system doesn't exist yet in this capstone, we simulate a
    // successful confirmation immediately. This keeps the demo runnable
    // end-to-end while making the mock/stub boundary explicit everywhere
    // it's used (never silently pretend this is a real network call).

    return [
        'status'              => 'confirmed',
        'external_reference'  => 'MOCK-SESSIONSYS-' . strtoupper(bin2hex(random_bytes(4))),
        'attendees_ready'     => true,
        'agenda_ready'        => true,
        'confirmed_at'        => date('c'),
        'is_mock'             => true, // always present so callers/UI can flag this clearly
        'note'                => 'Simulated response — no live Session and Legislative Meeting Management System connection exists yet.',
    ];
}
