<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$tokens = getDb()->query(
    'SELECT id, label, is_active, created_at, last_used_at FROM integration_tokens ORDER BY created_at DESC'
)->fetchAll();

jsonSuccess($tokens);
