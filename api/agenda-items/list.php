<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiAuth(); // any logged-in user (staff or admin) can view

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';

$db = getDb();

$sql = "SELECT ai.*,
               EXISTS (
                   SELECT 1
                   FROM session_agenda_items sai
                   JOIN sessions s ON s.id = sai.session_id
                   WHERE sai.agenda_item_id = ai.id
                     AND s.is_deleted = 0
                     AND s.status IN ('Scheduled', 'Rescheduled')
               ) AS has_active_schedule,
               EXISTS (
                   SELECT 1
                   FROM session_agenda_items sai
                   JOIN sessions s ON s.id = sai.session_id
                   WHERE sai.agenda_item_id = ai.id
                     AND (
                         s.status IN ('Completed', 'Cancelled')
                         OR s.is_deleted = 1
                     )
               ) AS has_finished_schedule
        FROM agenda_items ai";
if (!$includeArchived) {
    $sql .= " WHERE ai.is_archived = 0";
}
$sql .= " ORDER BY ai.date_filed DESC";

$items = $db->query($sql)->fetchAll();

if (!$items) {
    jsonSuccess([]);
}

$ids = array_column($items, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Readings, grouped by item
$readingsStmt = $db->prepare("SELECT * FROM readings WHERE agenda_item_id IN ($placeholders) ORDER BY sort_order ASC");
$readingsStmt->execute($ids);
$readingsByItem = [];
foreach ($readingsStmt->fetchAll() as $r) {
    $readingsByItem[$r['agenda_item_id']][] = ['stage' => $r['stage'], 'date' => $r['reading_date']];
}

// Priority history, grouped by item
$historyStmt = $db->prepare("SELECT * FROM priority_history WHERE agenda_item_id IN ($placeholders) ORDER BY recorded_at ASC");
$historyStmt->execute($ids);
$historyByItem = [];
foreach ($historyStmt->fetchAll() as $h) {
    $historyByItem[$h['agenda_item_id']][] = [
        'priority'      => $h['priority'],
        'by'            => $h['confirmed_by'],
        'date'          => $h['confirmed_date'],
        'notes'         => $h['notes'],
    ];
}

foreach ($items as &$item) {
    $item['readings']         = $readingsByItem[$item['id']] ?? [];
    $item['priority_history'] = $historyByItem[$item['id']] ?? [];
}
unset($item);

jsonSuccess($items);
