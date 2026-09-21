<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$admin = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$id = isset($b['id']) ? (int) $b['id'] : 0;
if (!$id) {
    jsonError('id is required.', 400);
}

$db = getDb();
$check = $db->prepare('SELECT id, stakeholder_name FROM meeting_stakeholders WHERE id = :id');
$check->execute([':id' => $id]);
$row = $check->fetch();
if (!$row) {
    jsonError('Stakeholder not found.', 404);
}

$db->prepare('DELETE FROM meeting_stakeholders WHERE id = :id')->execute([':id' => $id]);

logAudit('remove', 'meeting_stakeholder', $row['stakeholder_name'], "Removed by {$admin['full_name']}");

jsonSuccess(['id' => $id, 'removed' => true]);
