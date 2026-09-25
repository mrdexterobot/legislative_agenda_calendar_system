<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/agenda_item_ids.php';

$user = requireApiAuth(); // staff or admin
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();

$title        = trim($b['title'] ?? '');
$itemType     = $b['item_type'] ?? '';
$committee    = trim($b['committee'] ?? '');
$submittedBy  = trim($b['submitted_by'] ?? '');
$category     = $b['category'] ?? 'Regular';
$dateFiled    = $b['date_filed'] ?? '';

// ---- Validation (server-side — never trust the client's JS validation alone) ----
$errors = [];
if ($title === '') $errors[] = 'Title is required.';
if (!in_array($itemType, ['Ordinance', 'Resolution'], true)) $errors[] = 'Type must be Ordinance or Resolution.';
if ($committee === '') $errors[] = 'Committee is required.';
if ($submittedBy === '') $errors[] = 'Submitted by is required.';
if (!in_array($category, ['Regular', 'Budget', 'Emergency'], true)) $errors[] = 'Category must be Regular, Budget, or Emergency.';
$dateFiledObj = DateTime::createFromFormat('Y-m-d', $dateFiled);
if (!$dateFiledObj) $errors[] = 'Date filed must be a valid date (YYYY-MM-DD).';

if ($errors) {
    jsonError('Please fix the following: ' . implode(' ', $errors), 422);
}

$db = getDb();

$year = substr($dateFiled, 0, 4);
$prefix = $itemType === 'Ordinance' ? 'ORD' : 'RES';
$lockName = "agenda_item_id_{$prefix}_{$year}";
$lock = $db->prepare('SELECT GET_LOCK(:lock_name, 10)');
$lock->execute([':lock_name' => $lockName]);
if ((int) $lock->fetchColumn() !== 1) {
    jsonError('Could not reserve an agenda item number. Please try again.', 503);
}

try {
    $db->beginTransaction();
    // Serialize allocation for this type and filing year so simultaneous
    // submissions cannot receive the same generated number.
    $id = nextAgendaItemId($db, $itemType, $year);

    $stmt = $db->prepare(
        "INSERT INTO agenda_items (id, title, item_type, committee, submitted_by, category, date_filed)
         VALUES (:id, :title, :item_type, :committee, :submitted_by, :category, :date_filed)"
    );
    $stmt->execute([
        ':id'           => $id,
        ':title'        => $title,
        ':item_type'    => $itemType,
        ':committee'    => $committee,
        ':submitted_by' => $submittedBy,
        ':category'     => $category,
        ':date_filed'   => $dateFiled,
    ]);

    // Every item starts its route-slip with a "Filed" reading.
    $db->prepare(
        "INSERT INTO readings (agenda_item_id, stage, reading_date, sort_order) VALUES (:id, 'Filed', :date, 1)"
    )->execute([':id' => $id, ':date' => $dateFiled]);

    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->prepare('SELECT RELEASE_LOCK(:lock_name)')->execute([':lock_name' => $lockName]);
    error_log('Failed to create agenda item: ' . $e->getMessage());
    jsonError('Failed to save the agenda item. Please try again.', 500);
}
$db->prepare('SELECT RELEASE_LOCK(:lock_name)')->execute([':lock_name' => $lockName]);

logAudit('create', 'agenda_item', $id, "Encoded by {$user['full_name']}");

jsonSuccess(['id' => $id], 201);
