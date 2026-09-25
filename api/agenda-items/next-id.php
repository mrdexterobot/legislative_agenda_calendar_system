<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/agenda_item_ids.php';

requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$itemType = $_GET['item_type'] ?? '';
$year = $_GET['year'] ?? date('Y');

if (!in_array($itemType, ['Ordinance', 'Resolution'], true)) {
    jsonError('Type must be Ordinance or Resolution.', 422);
}
if (!is_string($year) || !preg_match('/^\d{4}$/D', $year)) {
    jsonError('Year must contain four digits.', 422);
}

jsonSuccess(['id' => nextAgendaItemId(getDb(), $itemType, $year)]);
