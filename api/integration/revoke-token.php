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
$check = $db->prepare('SELECT id, label FROM integration_tokens WHERE id = :id');
$check->execute([':id' => $id]);
$token = $check->fetch();
if (!$token) {
    jsonError('Token not found.', 404);
}

$db->prepare('UPDATE integration_tokens SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);

logAudit('revoke', 'integration_token', $token['label'], "Revoked by {$admin['full_name']}");

jsonSuccess(['id' => $id, 'revoked' => true]);
