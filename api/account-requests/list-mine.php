<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$stmt = getDb()->prepare('SELECT * FROM account_requests WHERE user_id = :uid ORDER BY created_at DESC');
$stmt->execute([':uid' => $user['id']]);

jsonSuccess($stmt->fetchAll());
