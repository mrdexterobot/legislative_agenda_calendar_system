<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireApiAuth(); // staff or admin
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();

$id           = trim($b['id'] ?? '');
$title        = trim($b['title'] ?? '');
$itemType     = $b['item_type'] ?? '';
$committee    = trim($b['committee'] ?? '');
$submittedBy  = trim($b['submitted_by'] ?? '');
$category     = $b['category'] ?? 'Regular';
$dateFiled    = $b['date_filed'] ?? '';

// ---- Validation (server-side — never trust the client's JS validation alone) ----
$errors = [];
if ($id === '' || !preg_match('/^[A-Z]{2,5}-\d{4}-\d{3}$/', $id)) {
    $errors[] = "ID is required and should look like 'ORD-2026-014' or 'RES-2026-022'.";
}
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

$exists = $db->prepare('SELECT id FROM agenda_items WHERE id = :id');
$exists->execute([':id' => $id]);
if ($exists->fetch()) {
    jsonError("An agenda item with ID $id already exists.", 409);
}

$db->beginTransaction();
try {
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
    $db->rollBack();
    error_log('Failed to create agenda item: ' . $e->getMessage());
    jsonError('Failed to save the agenda item. Please try again.', 500);
}

logAudit('create', 'agenda_item', $id, "Encoded by {$user['full_name']}");

jsonSuccess(['id' => $id], 201);
