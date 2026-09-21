<?php
/**
 * login.php — password check, account lockout, and handoff to the second
 * factor.
 *
 * LOCKOUT: five failed attempts lock the account for fifteen minutes. The
 * counter lives on the users row rather than in the session, because a
 * session-based counter is defeated by discarding the cookie between
 * attempts.
 *
 * MFA: a correct password is no longer enough on an account that requires a
 * second factor. This endpoint records only $_SESSION['mfa_pending'] and
 * emails a code; api/auth/verify-mfa.php is the only place that creates a
 * signed-in session for those accounts. Nothing in $_SESSION['user'] is set
 * here in that case, so every page and API guard still treats the visitor as
 * logged out until the code is accepted.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mfa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

const LOGIN_MAX_ATTEMPTS    = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

$body     = getJsonBody();
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if ($username === '' || $password === '') {
    jsonError('Username and password are required.', 400);
}

$db = getDb();

// The lockout and MFA columns arrive via migrations. If a migration hasn't
// been applied to this database yet, fall back rather than failing every
// login with a SQL error.
$extraColumns = true;
try {
    $stmt = $db->prepare(
        'SELECT id, username, password_hash, full_name, email, role, is_active,
                mfa_enabled, failed_login_attempts, locked_until
         FROM users WHERE username = :u LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
} catch (PDOException $e) {
    $extraColumns = false;
    error_log('Login columns missing — run the round 7 and round 8 migrations. ' . $e->getMessage());
    $stmt = $db->prepare(
        'SELECT id, username, password_hash, full_name, email, role, is_active
         FROM users WHERE username = :u LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
}
$user = $stmt->fetch();

$invalidMsg = 'Invalid username or password.';

// ---- Locked out? Checked before the password is compared, so a locked
// account cannot be brute-forced by continuing to guess. ----
if ($user && $extraColumns && $user['locked_until'] !== null) {
    $secondsLeft = strtotime($user['locked_until']) - time();
    if ($secondsLeft > 0) {
        $minutesLeft = max(1, (int) ceil($secondsLeft / 60));
        logAudit('login_blocked', 'user', $username, 'Attempt while account was locked');
        jsonError("Too many failed sign-in attempts. Try again in $minutesLeft minute"
            . ($minutesLeft === 1 ? '' : 's') . ', or reset your password.', 429);
    }
    $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
       ->execute([':id' => $user['id']]);
    $user['failed_login_attempts'] = 0;
}

if (!$user || !password_verify($password, $user['password_hash'])) {
    $detail = 'Failed login attempt';

    if ($user && $extraColumns) {
        $attempts = (int) $user['failed_login_attempts'] + 1;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL :mins MINUTE) WHERE id = :id')
               ->execute([':mins' => LOGIN_LOCKOUT_MINUTES, ':id' => $user['id']]);
            $detail = 'Failed login attempt — account locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes after ' . LOGIN_MAX_ATTEMPTS . ' failures';
        } else {
            $db->prepare('UPDATE users SET failed_login_attempts = :n WHERE id = :id')
               ->execute([':n' => $attempts, ':id' => $user['id']]);
            $detail = "Failed login attempt ($attempts of " . LOGIN_MAX_ATTEMPTS . ')';
        }
    }

    logAudit('login_failed', 'user', $username, $detail);
    jsonError($invalidMsg, 401);
}

if (!$user['is_active']) {
    jsonError('This account has been deactivated. Contact your administrator.', 403);
}

if ($extraColumns) {
    $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
       ->execute([':id' => $user['id']]);
}

// ---- Second factor ----
if ($extraColumns && mfaRequiredFor($user)) {
    if (empty($user['email'])) {
        // Can't deliver a code, so don't pretend to. An admin has to put a
        // real address on the account first.
        logAudit('login_mfa_blocked', 'user', $user['username'], 'MFA required but no email on the account');
        jsonError('This account requires a sign-in code, but it has no email address on file. Ask an administrator to add one.', 409);
    }

    $send = issueMfaCode((int) $user['id'], $user['email'], $user['full_name']);

    if (!$send['success']) {
        logAudit('login_mfa_failed', 'user', $user['username'], 'Could not email the sign-in code: ' . $send['error']);
        jsonError('Your password was accepted, but the sign-in code could not be emailed: ' . $send['error'], 502);
    }

    // Deliberately NOT a signed-in session — only the pending marker.
    $_SESSION['mfa_pending'] = [
        'user_id'    => (int) $user['id'],
        'started_at' => time(),
    ];

    logAudit('login_mfa_challenge', 'user', $user['username'], 'Password accepted; sign-in code emailed');

    jsonSuccess([
        'mfa_required'  => true,
        'email_hint'    => maskEmail($user['email']),
        'expires_in'    => MFA_CODE_TTL_MINUTES * 60,
    ]);
}

$sessionUser = establishUserSession($user);
$csrfToken = generateCsrfToken();
logAudit('login_success', 'user', $user['username']);

jsonSuccess([
    'mfa_required' => false,
    'user'         => $sessionUser,
    'csrf_token'   => $csrfToken,
]);
