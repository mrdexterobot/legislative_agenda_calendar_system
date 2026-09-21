<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$db = getDb();

// mfa_enabled / locked_until come from the round 7 and 8 migrations. Degrade
// to the original column set rather than breaking the whole admin screen on a
// database that hasn't had them applied.
try {
    $users = $db->query(
        'SELECT id, username, full_name, email, role, is_active, mfa_enabled,
                failed_login_attempts, locked_until, created_at
         FROM users ORDER BY created_at ASC'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Admin user list falling back — run the round 7 and 8 migrations. ' . $e->getMessage());
    $users = $db->query(
        'SELECT id, username, full_name, email, role, is_active, created_at FROM users ORDER BY created_at ASC'
    )->fetchAll();
    foreach ($users as &$u) {
        $u['mfa_enabled'] = null;
        $u['locked_until'] = null;
        $u['failed_login_attempts'] = 0;
    }
    unset($u);
}

// Surface the lock as a plain boolean so the client doesn't have to compare
// timestamps against a clock that may differ from the server's.
foreach ($users as &$u) {
    $u['is_locked'] = !empty($u['locked_until']) && strtotime($u['locked_until']) > time();
}
unset($u);

jsonSuccess($users);
