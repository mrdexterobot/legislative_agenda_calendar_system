<?php
/**
 * resend-mfa.php — issues a replacement sign-in code for a challenge that is
 * already in progress. Rate-limited so this can't be used to hammer someone's
 * inbox (or the Brevo daily quota) by holding down a button.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mfa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$pending = $_SESSION['mfa_pending'] ?? null;
if (!$pending) {
    jsonError('This sign-in has expired. Enter your username and password again.', 440);
}

$userId = (int) $pending['user_id'];

$cooldown = mfaResendCooldownRemaining($userId);
if ($cooldown > 0) {
    jsonError("A code was just sent. You can request another in $cooldown seconds.", 429);
}

$stmt = getDb()->prepare('SELECT id, username, full_name, email, is_active FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user || !$user['is_active'] || !$user['email']) {
    unset($_SESSION['mfa_pending']);
    jsonError('This account can no longer receive a code. Contact your administrator.', 403);
}

$send = issueMfaCode($userId, $user['email'], $user['full_name']);
if (!$send['success']) {
    jsonError('The code could not be emailed: ' . $send['error'], 502);
}

// Restart the challenge clock, otherwise a resent code could outlive the
// window the original challenge opened.
$_SESSION['mfa_pending']['started_at'] = time();

logAudit('login_mfa_resend', 'user', $user['username'], 'New sign-in code emailed');

jsonSuccess([
    'email_hint' => maskEmail($user['email']),
    'expires_in' => MFA_CODE_TTL_MINUTES * 60,
]);
