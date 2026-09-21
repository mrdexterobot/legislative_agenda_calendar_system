<?php
/**
 * meeting_notice.php — one definition of what a session notice says.
 *
 * Three different places now send the same notice: the bulk send in
 * api/meetings/send-notifications.php, the automatic notice when someone is
 * added to a meeting whose notifications already went out
 * (api/meetings/add-stakeholder.php), and the per-person resend in
 * api/meetings/notify-stakeholder.php. Composing the message separately in
 * each of them guarantees they drift apart, so the wording, the subject, and
 * the agenda list are built here once.
 */

require_once __DIR__ . '/db.php';

/**
 * Loads a meeting with its session details and attached agenda items.
 * Returns null when the session has no meeting record.
 */
function loadMeetingForNotice(PDO $db, string $sessionId): ?array {
    $stmt = $db->prepare(
        'SELECT m.*, s.session_type, s.session_date, s.session_time, s.venue,
                s.presiding_officer, s.sequence_number, s.status
         FROM meetings m
         JOIN sessions s ON s.id = m.session_id
         WHERE m.session_id = :sid AND s.is_deleted = 0'
    );
    $stmt->execute([':sid' => $sessionId]);
    $meeting = $stmt->fetch();
    if (!$meeting) {
        return null;
    }

    $items = $db->prepare(
        'SELECT ai.id, ai.title, ai.confirmed_priority
         FROM session_agenda_items sai
         JOIN agenda_items ai ON ai.id = sai.agenda_item_id
         WHERE sai.session_id = :sid'
    );
    $items->execute([':sid' => $sessionId]);
    $meeting['agenda_items'] = $items->fetchAll();

    return $meeting;
}

/** Session label as the office writes it: "58th Regular Session". */
function sessionNoticeLabel(array $meeting): string {
    $type = $meeting['session_type'];
    $n = $meeting['sequence_number'] ?? null;
    if (!$n) {
        return $type;
    }
    $suffix = ($n % 10 === 1 && $n % 100 !== 11) ? 'st'
            : (($n % 10 === 2 && $n % 100 !== 12) ? 'nd'
            : (($n % 10 === 3 && $n % 100 !== 13) ? 'rd' : 'th'));
    return "$n$suffix $type";
}

function sessionNoticeSubject(array $meeting, bool $isResend = false): string {
    return ($isResend ? 'Reminder: ' : 'Notice: ')
        . sessionNoticeLabel($meeting) . ' on ' . date('M j, Y', strtotime($meeting['session_date']));
}

function sessionNoticeBody(array $meeting, string $recipientName = ''): string {
    $lines = [];
    $lines[] = $recipientName !== '' ? "Hello $recipientName," : 'Good day,';
    $lines[] = '';
    $lines[] = 'You are being notified of an upcoming ' . sessionNoticeLabel($meeting) . '.';
    $lines[] = '';
    $lines[] = 'Date:    ' . date('l, F j, Y', strtotime($meeting['session_date']));
    $lines[] = 'Time:    ' . $meeting['session_time'];
    $lines[] = 'Venue:   ' . $meeting['venue'];
    if (!empty($meeting['presiding_officer'])) {
        $lines[] = 'Presiding: ' . $meeting['presiding_officer'];
    }

    if (!empty($meeting['agenda_items'])) {
        $lines[] = '';
        $lines[] = 'Agenda items for this session:';
        foreach ($meeting['agenda_items'] as $item) {
            $priority = $item['confirmed_priority'] ? ' [' . $item['confirmed_priority'] . ' priority]' : '';
            $lines[] = '  - ' . $item['id'] . ' — ' . $item['title'] . $priority;
        }
    } else {
        $lines[] = '';
        $lines[] = 'No agenda items are attached to this session yet.';
    }

    $lines[] = '';
    $lines[] = 'Sangguniang Panlungsod of San Jose del Monte, Bulacan';
    $lines[] = 'Sent automatically by the Legislative Agenda and Calendar Management System.';

    return implode("\n", $lines);
}
