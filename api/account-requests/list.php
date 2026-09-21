<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$rows = getDb()->query(
    "SELECT ar.*, u.username, u.email AS current_email, u.full_name AS current_full_name, u.is_active
     FROM account_requests ar
     JOIN users u ON u.id = ar.user_id
     ORDER BY (ar.status = 'pending') DESC, ar.created_at DESC"
)->fetchAll();

jsonSuccess($rows);
