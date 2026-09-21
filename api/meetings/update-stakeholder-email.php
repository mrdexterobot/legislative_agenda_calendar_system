<?php
/**
 * update-stakeholder-email.php — admin-only, same gate as actually
 * sending notifications (api/meetings/send-notifications.php), since
 * whoever controls where a real email lands should be under the same
 * privilege as whoever triggers the send.
 */

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
$id    = isset($b['id']) ? (int) $b['id'] : 0;
$email = trim($b['email'] ?? '');

if (!$id) {
    jsonError('id is required.', 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('That does not look like a valid email address.', 422);
}

$db = getDb();
$check = $db->prepare('SELECT id, stakeholder_name FROM meeting_stakeholders WHERE id = :id');
$check->execute([':id' => $id]);
$row = $check->fetch();
if (!$row) {
    jsonError('Stakeholder not found.', 404);
}

$db->prepare('UPDATE meeting_stakeholders SET email = :email WHERE id = :id')
   ->execute([':email' => $email !== '' ? $email : null, ':id' => $id]);

logAudit('update', 'meeting_stakeholder', $row['stakeholder_name'], "Email set by {$admin['full_name']}" . ($email ? ": $email" : ' (cleared)'));

jsonSuccess(['id' => $id, 'email' => $email ?: null]);
