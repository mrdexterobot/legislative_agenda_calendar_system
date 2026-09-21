<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/password_policy.php';

$user = requireApiAuth();
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$currentPassword = $b['current_password'] ?? '';
$newPassword     = $b['new_password'] ?? '';

if ($problem = passwordPolicyError($newPassword, ['username' => $user['username'], 'email' => $user['email'] ?? ''])) {
    jsonError($problem, 422);
}

$db = getDb();
$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    jsonError('Current password is incorrect.', 403);
}

// Changing a password to the same password is almost always an accident —
// someone believing they have rotated a credential they have not.
if (password_verify($newPassword, $row['password_hash'])) {
    jsonError('That is the password you are already using. Choose a different one.', 422);
}

$db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
   ->execute([':hash' => password_hash($newPassword, PASSWORD_BCRYPT), ':id' => $user['id']]);

logAudit('change_password', 'user', $user['username'], 'Changed own password from the profile page');

jsonSuccess(['message' => 'Password updated.']);
