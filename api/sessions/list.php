<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$db = getDb();

// RISK FIX: soft-deleted sessions never show in the normal calendar for
// anyone — only an admin explicitly asking for them (via the "Show
// deleted" toggle in js/calendar-scheduling.js) sees is_deleted=1 rows.
$includeDeleted = $user['role'] === 'admin' && ($_GET['include_deleted'] ?? '') === '1';
$deletedClause = $includeDeleted ? '' : 'WHERE is_deleted = 0';

$sessions = $db->query("SELECT * FROM sessions $deletedClause ORDER BY session_date ASC, session_time_24h ASC")->fetchAll();

if (!$sessions) {
    jsonSuccess([]);
}

$ids = array_column($sessions, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// LOOPHOLE FIX / IMPROVEMENT: this used to return bare agenda_item_ids
// (just ID strings), which meant every page that wanted to show an
// item's priority next to it had to separately fetch the full agenda
// items list and cross-reference by ID — duplicated logic, and easy to
// forget on a new page. Returning the full {id, title, confirmed_priority}
// object here, already sorted by priority, means Dashboard, Calendar
// Scheduling, and anywhere else this is consumed all get the same
// behavior for free. Nothing is stored redundantly — this is a JOIN at
// read time, not a persisted order column that could drift out of sync
// with the item's actual current priority.
$linkStmt = $db->prepare(
    "SELECT sai.session_id, ai.id, ai.title, ai.confirmed_priority
     FROM session_agenda_items sai
     JOIN agenda_items ai ON ai.id = sai.agenda_item_id
     WHERE sai.session_id IN ($placeholders)"
);
$linkStmt->execute($ids);

$priorityRank = ['High' => 0, 'Medium' => 1, 'Low' => 2, null => 3];
$itemsBySession = [];
foreach ($linkStmt->fetchAll() as $row) {
    $itemsBySession[$row['session_id']][] = [
        'id'                 => $row['id'],
        'title'              => $row['title'],
        'confirmed_priority' => $row['confirmed_priority'],
    ];
}
foreach ($itemsBySession as &$items) {
    usort($items, fn($a, $b) => $priorityRank[$a['confirmed_priority']] <=> $priorityRank[$b['confirmed_priority']]);
}
unset($items);

$meetingStmt = $db->prepare("SELECT * FROM meetings WHERE session_id IN ($placeholders)");
$meetingStmt->execute($ids);
$meetingsBySession = [];
foreach ($meetingStmt->fetchAll() as $m) {
    $meetingsBySession[$m['session_id']] = $m;
}

foreach ($sessions as &$s) {
    $s['agenda_items'] = $itemsBySession[$s['id']] ?? [];
    $s['meeting'] = $meetingsBySession[$s['id']] ?? null;
}
unset($s);

jsonSuccess($sessions);
