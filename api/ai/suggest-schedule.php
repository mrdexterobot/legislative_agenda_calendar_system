<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/conflict_detection.php';
require_once __DIR__ . '/../../includes/ai_schedule.php';

$user = requireApiAuth();
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = getJsonBody();
$rawItemIds = $body['agenda_item_ids'] ?? [];
if (!is_array($rawItemIds) || !$rawItemIds || count($rawItemIds) > 25) {
    jsonError('Select between 1 and 25 agenda items before asking for schedule suggestions.', 422);
}

$itemIds = array_values(array_unique(array_map(static fn($id): string => trim((string) $id), $rawItemIds)));
foreach ($itemIds as $itemId) {
    if ($itemId === '' || !preg_match('/^[A-Za-z0-9._-]{1,50}$/', $itemId)) {
        jsonError('One or more agenda item IDs are invalid.', 422);
    }
}

$venue = trim((string) ($body['venue'] ?? ''));
if ($venue === '' || mb_strlen($venue) > 255) {
    jsonError('Enter a venue before asking for schedule suggestions.', 422);
}

$sessionType = trim((string) ($body['type'] ?? 'Regular Session'));
if (!in_array($sessionType, ['Regular Session', 'Special Session', 'Committee Hearing', 'Public Hearing'], true)) {
    jsonError('Invalid session type.', 422);
}

$committee = trim((string) ($body['committee'] ?? '')) ?: null;
$presidingOfficer = trim((string) ($body['presiding_officer'] ?? '')) ?: 'TBD';
$timeDisplay = trim((string) ($body['time'] ?? ''));
$timeStamp = $timeDisplay !== '' ? strtotime($timeDisplay) : strtotime('09:00 AM');
if ($timeStamp === false) {
    jsonError('Time could not be understood. Try a format like "9:00 AM".', 422);
}
$time24h = date('H:i:s', $timeStamp);
$normalizedTime = date('g:i A', $timeStamp);

$dateFrom = trim((string) ($body['date_from'] ?? ''));
if ($dateFrom === '') {
    $dateFrom = date('Y-m-d');
}
$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
$dateErrors = DateTimeImmutable::getLastErrors();
if (!$dateObject || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
    jsonError('date_from must use YYYY-MM-DD format.', 422);
}
if ($dateObject < new DateTimeImmutable('today')) {
    jsonError('Suggestions must start today or later.', 422);
}

$db = getDb();
$placeholders = implode(',', array_fill(0, count($itemIds), '?'));
$itemStmt = $db->prepare(
    "SELECT id, title, item_type, committee, category, date_filed, confirmed_priority
     FROM agenda_items
     WHERE id IN ($placeholders) AND is_archived = 0"
);
$itemStmt->execute($itemIds);
$agendaItems = $itemStmt->fetchAll();
$foundIds = array_column($agendaItems, 'id');
if (count($foundIds) !== count($itemIds) || array_diff($itemIds, $foundIds)) {
    jsonError('One or more selected agenda items do not exist or are archived.', 422);
}

$candidateSlots = [];
$lastDate = $dateObject->modify('+45 days');
for ($candidateDate = $dateObject; $candidateDate <= $lastDate; $candidateDate = $candidateDate->modify('+1 day')) {
    if ((int) $candidateDate->format('N') >= 6) {
        continue;
    }

    $date = $candidateDate->format('Y-m-d');
    $conflicts = checkSessionConflicts($db, $date, $time24h, $venue, $committee, $presidingOfficer);
    if (!$conflicts) {
        $candidateSlots[] = [
            'date'     => $date,
            'time'     => $normalizedTime,
            'time_24h' => $time24h,
        ];
    }
}

if (!$candidateSlots) {
    jsonError('No conflict-free weekday slots were found in the next 45 days for that venue and time.', 409);
}

$aiResult = getAIScheduleSuggestions($agendaItems, $candidateSlots, [
    'session_type'      => $sessionType,
    'venue'             => $venue,
    'time'              => $normalizedTime,
    'committee'         => $committee ?? '',
    'presiding_officer' => $presidingOfficer,
    'date_from'         => $dateFrom,
    'date_to'           => $lastDate->format('Y-m-d'),
]);

if ($aiResult['error']) {
    jsonError($aiResult['message'] ?? 'AI scheduling is unavailable. Please assess manually.', 502);
}

$candidateByDate = [];
foreach ($candidateSlots as $slot) {
    $candidateByDate[$slot['date']] = $slot;
}
$suggestions = array_map(static function (array $suggestion) use ($candidateByDate): array {
    $slot = $candidateByDate[$suggestion['date']];
    return [
        'date'      => $slot['date'],
        'time'      => $slot['time'],
        'reasoning' => $suggestion['reasoning'],
    ];
}, $aiResult['suggestions']);

logAudit('ai_suggest_schedule', 'calendar', null, "Suggested " . count($suggestions) . " dates for " . count($agendaItems) . " agenda item(s) by {$user['full_name']}.");

jsonSuccess([
    'suggestions' => $suggestions,
    'evaluated'   => count($candidateSlots),
]);
