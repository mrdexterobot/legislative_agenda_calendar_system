<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$db = getDb();

$rows = $db->query(
    "SELECT s.id AS session_id, s.session_date, s.session_time, s.session_type, s.venue, s.status, s.sequence_number,
            m.id AS meeting_id, m.venue_booked, m.attendance_confirmed_text, m.documents_distributed,
            m.minutes_status, m.attendees_confirmed, m.agenda_confirmed, m.external_confirmed_at,
            m.notifications_sent, m.notifications_sent_at, m.notifications_sent_by
     FROM sessions s
     LEFT JOIN meetings m ON m.session_id = s.id
     WHERE s.is_deleted = 0
     ORDER BY s.session_date ASC"
)->fetchAll();

if (!$rows) {
    jsonSuccess([]);
}

$meetingIds = array_filter(array_column($rows, 'meeting_id'));
$stakeholdersByMeeting = [];
if ($meetingIds) {
    $placeholders = implode(',', array_fill(0, count($meetingIds), '?'));
    // SMTP FIX: id + email are now returned alongside the name so the UI
    // can let an admin attach an email (api/meetings/update-stakeholder-
    // email.php) and so send-notifications.php has an address to send to
    // — stakeholder_name alone was never a deliverable address.
    $stmt = $db->prepare("SELECT id, meeting_id, stakeholder_name, email, user_id FROM meeting_stakeholders WHERE meeting_id IN ($placeholders)");
    $stmt->execute(array_values($meetingIds));
    foreach ($stmt->fetchAll() as $row) {
        $stakeholdersByMeeting[$row['meeting_id']][] = [
            'id'      => $row['id'],
            'name'    => $row['stakeholder_name'],
            'email'   => $row['email'],
            'user_id' => $row['user_id'],
        ];
    }
}

foreach ($rows as &$r) {
    $r['stakeholders'] = $stakeholdersByMeeting[$r['meeting_id']] ?? [];
}
unset($r);

// LOOPHOLE FIX: Meeting Coordination showed logistics/stakeholders for a
// session but never WHICH agenda items were actually being covered, or
// what reading stage they were at — staff had to switch to Calendar
// Scheduling just to see that. Attaching it here too.
$sessionIds = array_column($rows, 'session_id');
if ($sessionIds) {
    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $itemStmt = $db->prepare(
        "SELECT sai.session_id, ai.id, ai.title, ai.confirmed_priority
         FROM session_agenda_items sai
         JOIN agenda_items ai ON ai.id = sai.agenda_item_id
         WHERE sai.session_id IN ($placeholders)"
    );
    $itemStmt->execute($sessionIds);
    $rawItems = $itemStmt->fetchAll();

    $itemIds = array_column($rawItems, 'id');
    $readingsByItem = [];
    if ($itemIds) {
        $rp = implode(',', array_fill(0, count($itemIds), '?'));
        $readingsStmt = $db->prepare("SELECT * FROM readings WHERE agenda_item_id IN ($rp) ORDER BY sort_order ASC");
        $readingsStmt->execute($itemIds);
        foreach ($readingsStmt->fetchAll() as $rd) {
            $readingsByItem[$rd['agenda_item_id']][] = ['stage' => $rd['stage'], 'date' => $rd['reading_date']];
        }
    }

    $itemsBySession = [];
    foreach ($rawItems as $it) {
        $it['readings'] = $readingsByItem[$it['id']] ?? [];
        $itemsBySession[$it['session_id']][] = $it;
    }
    foreach ($rows as &$r) {
        $r['agenda_items'] = $itemsBySession[$r['session_id']] ?? [];
    }
    unset($r);
}

jsonSuccess($rows);
