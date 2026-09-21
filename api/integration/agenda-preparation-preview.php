<?php
/**
 * agenda-preparation-preview.php
 *
 * Part of the Integration Hub — plays the role of the peer Agenda
 * Preparation Module (part of the Session and Legislative Meeting
 * Management System, per the ten-subsystem list) RECEIVING our confirmed
 * priority data. This is a read-only, live view: it doesn't copy or
 * duplicate the data anywhere, it just presents agenda_items filtered to
 * "confirmed" and sorted the way a consuming system would want them —
 * making visible where Priority Setting's output actually goes, since
 * nothing else in this codebase surfaced that before.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$db = getDb();

$items = $db->query(
    "SELECT id, title, item_type, committee, confirmed_priority, priority_confirmed_by, priority_confirmed_date
     FROM agenda_items
     WHERE confirmed_priority IS NOT NULL AND is_archived = 0
     ORDER BY FIELD(confirmed_priority, 'High', 'Medium', 'Low'), priority_confirmed_date ASC"
)->fetchAll();

if ($items) {
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $readingsStmt = $db->prepare("SELECT * FROM readings WHERE agenda_item_id IN ($placeholders) ORDER BY sort_order ASC");
    $readingsStmt->execute($ids);
    $readingsByItem = [];
    foreach ($readingsStmt->fetchAll() as $r) {
        $readingsByItem[$r['agenda_item_id']][] = ['stage' => $r['stage'], 'date' => $r['reading_date']];
    }
    foreach ($items as &$item) {
        $item['readings'] = $readingsByItem[$item['id']] ?? [];
    }
    unset($item);
}

jsonSuccess([
    'items'        => $items,
    'received_at'  => date('c'),
    'note'         => 'This is a live, read-only view — the Agenda Preparation Module would query the export endpoint for this same data in a real integration; nothing is duplicated here.',
]);
