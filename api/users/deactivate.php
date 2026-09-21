<?php
/**
 * LOOPHOLE FIX: "delete" for a user account means deactivate (is_active = 0),
 * not a hard row delete. Two reasons: (1) audit_log rows reference user_id
 * with ON DELETE SET NULL — a hard delete would still keep the username
 * snapshot, but deactivation is the more standard, reversible pattern for
 * account management; (2) it stops a compromised or malicious admin from
 * covering their tracks by deleting the account that performed an action.
 * Deactivated accounts can't log in (see api/auth/login.php's is_active
 * check) but their history stays intact.
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
$id = isset($b['id']) ? (int) $b['id'] : 0;
if (!$id) {
    jsonError('id is required.', 400);
}

if ($id === (int) $admin['id']) {
    jsonError('You cannot deactivate your own account while logged in as it.', 409);
}

$db = getDb();
$check = $db->prepare('SELECT id, username, role, is_active FROM users WHERE id = :id');
$check->execute([':id' => $id]);
$target = $check->fetch();
if (!$target) {
    jsonError('User not found.', 404);
}

if ($target['role'] === 'admin') {
    $activeAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    if ($activeAdmins <= 1) {
        jsonError('Cannot deactivate the last remaining admin account.', 409);
    }
}

$db->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);

logAudit('deactivate', 'user', $target['username'], "Deactivated by {$admin['full_name']}");

jsonSuccess(['id' => $id, 'deactivated' => true]);
