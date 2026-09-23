<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/password_policy.php';
require_once __DIR__ . '/../../includes/mfa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$identifier  = trim($b['identifier'] ?? '');
$code        = trim($b['code'] ?? '');
$newPassword = $b['new_password'] ?? '';

if ($identifier === '' || $code === '') {
    jsonError('Username/email and the emailed code are both required.', 400);
}

$db = getDb();
// Keep separate bindings: native PDO prepares do not support one named
// placeholder appearing more than once in a statement.
$stmt = $db->prepare(
    'SELECT id, username, email
     FROM users
     WHERE (username = :username OR email = :email) AND is_active = 1'
);
$stmt->execute([':username' => $identifier, ':email' => $identifier]);
$user = $stmt->fetch();

// Same generic failure whether the account doesn't exist, the code is wrong,
// or it expired — at this step there is nothing to gain from distinguishing
// them, and a specific message would tell an attacker holding a guessed code
// which half they got right.
$invalidMsg = 'That code is invalid or has expired. Please request a new one.';

if (!$user) {
    jsonError($invalidMsg, 400);
}

// Policy is checked against this specific account, so the new password can't
// simply be the username or the email local part.
if ($problem = passwordPolicyError($newPassword, ['username' => $user['username'], 'email' => $user['email']])) {
    jsonError($problem, 422);
}

$resetStmt = $db->prepare(
    'SELECT id, code_hash, attempts, expires_at FROM password_resets
     WHERE user_id = :uid AND used_at IS NULL ORDER BY created_at DESC LIMIT 1'
);
$resetStmt->execute([':uid' => $user['id']]);
$reset = $resetStmt->fetch();

if (!$reset || strtotime($reset['expires_at']) < time()) {
    jsonError($invalidMsg, 400);
}

// A 6-digit code is only ~1 million possibilities — with no attempt limit it
// is brute-forceable well within the 15-minute expiry.
if ((int) $reset['attempts'] >= 5) {
    $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')->execute([':id' => $reset['id']]);
    jsonError('Too many incorrect attempts. Please request a new code.', 429);
}

if (!hash_equals($reset['code_hash'], hash('sha256', $code))) {
    $db->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $reset['id']]);
    jsonError($invalidMsg, 400);
}

$db->beginTransaction();
try {
    $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
       ->execute([':hash' => password_hash($newPassword, PASSWORD_BCRYPT), ':id' => $user['id']]);
    $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')->execute([':id' => $reset['id']]);
    revokeAllTrustedDevices((int) $user['id']);

    // A completed reset also clears any lockout: the person has just proven
    // control of the mailbox on the account, so continuing to hold the lock
    // only punishes the legitimate owner.
    try {
        $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
           ->execute([':id' => $user['id']]);
    } catch (PDOException $e) {
        // Lockout columns not migrated yet — not fatal to the reset itself.
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('Failed to reset password: ' . $e->getMessage());
    jsonError('Something went wrong. Please try again.', 500);
}

logAudit('password_reset', 'user', $user['username'], 'Password reset via emailed code');

jsonSuccess(['message' => 'Password updated. You can now sign in.']);
