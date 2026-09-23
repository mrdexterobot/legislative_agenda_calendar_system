<?php
/**
 * Password sign-in, account lockout, MFA, and trusted-device handling.
 *
 * A trusted device is checked only after the password succeeds. It never
 * creates a session by itself and expires after 30 days.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mfa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

$body = getJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
$rememberDevice = ($body['remember_device'] ?? false) === true;

if ($username === '' || $password === '') {
    jsonError('Username and password are required.', 400);
}

$db = getDb();
$invalidMsg = 'Invalid username or password.';

try {
    // Lock the row for the complete password decision. This prevents two
    // concurrent failures from overwriting each other's lockout counter.
    $db->beginTransaction();
    $stmt = $db->prepare(
        'SELECT id, username, password_hash, full_name, email, role, is_active,
                mfa_enabled, failed_login_attempts, locked_until
         FROM users WHERE username = :u LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if ($user && $user['locked_until'] !== null) {
        $secondsLeft = strtotime($user['locked_until']) - time();
        if ($secondsLeft > 0) {
            $db->commit();
            $minutesLeft = max(1, (int) ceil($secondsLeft / 60));
            logAudit('login_blocked', 'user', $username, 'Attempt while account was locked');
            jsonError(
                "Too many failed sign-in attempts. Try again in $minutesLeft minute"
                . ($minutesLeft === 1 ? '' : 's') . ', or reset your password.',
                429
            );
        }

        $db->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id'
        )->execute([':id' => $user['id']]);
        $user['failed_login_attempts'] = 0;
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $detail = 'Failed login attempt';

        if ($user) {
            $attempts = (int) $user['failed_login_attempts'] + 1;
            if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                $lockedUntil = gmdate('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MINUTES * 60));
                $db->prepare(
                    'UPDATE users
                     SET failed_login_attempts = 0, locked_until = :locked_until
                     WHERE id = :id'
                )->execute([
                    ':locked_until' => $lockedUntil,
                    ':id' => $user['id'],
                ]);
                $detail = 'Failed login attempt - account locked for '
                    . LOGIN_LOCKOUT_MINUTES . ' minutes after ' . LOGIN_MAX_ATTEMPTS . ' failures';
            } else {
                $db->prepare(
                    'UPDATE users SET failed_login_attempts = :attempts WHERE id = :id'
                )->execute([
                    ':attempts' => $attempts,
                    ':id' => $user['id'],
                ]);
                $detail = 'Failed login attempt (' . $attempts . ' of ' . LOGIN_MAX_ATTEMPTS . ')';
            }
        }

        $db->commit();
        logAudit('login_failed', 'user', $username, $detail);
        jsonError($invalidMsg, 401);
    }

    $db->prepare(
        'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id'
    )->execute([':id' => $user['id']]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // A missing migration must never turn into password-only authentication.
    error_log('Login schema or database error: ' . $e->getMessage());
    jsonError('Sign-in is temporarily unavailable. Ask the administrator to verify the database schema.', 503);
}

if (!$user['is_active']) {
    jsonError('This account has been deactivated. Contact your administrator.', 403);
}

// A valid remembered device can skip only the emailed second factor. The
// password has already been verified and the token is checked server-side.
if (mfaRequiredFor($user) && hasTrustedDevice((int) $user['id'])) {
    $sessionUser = establishUserSession($user);
    $csrfToken = generateCsrfToken();
    logAudit('login_success', 'user', $user['username'], 'Signed in with a trusted device');

    jsonSuccess([
        'mfa_required' => false,
        'trusted_device' => true,
        'user' => $sessionUser,
        'csrf_token' => $csrfToken,
    ]);
}

if (mfaRequiredFor($user)) {
    if (empty($user['email'])) {
        logAudit('login_mfa_blocked', 'user', $user['username'], 'MFA required but no email on the account');
        jsonError(
            'This account requires a sign-in code, but it has no email address on file. '
            . 'Ask an administrator to add one.',
            409
        );
    }

    try {
        $send = issueMfaCode((int) $user['id'], $user['email'], $user['full_name']);
    } catch (Throwable $e) {
        error_log('MFA code storage error: ' . $e->getMessage());
        logAudit('login_mfa_failed', 'user', $user['username'], 'Could not store the sign-in code');
        jsonError('Your password was accepted, but the sign-in code could not be created. Try again later.', 503);
    }

    if (!$send['success']) {
        logAudit('login_mfa_failed', 'user', $user['username'], 'Could not email the sign-in code');
        jsonError('Your password was accepted, but the sign-in code could not be emailed. Contact the administrator.', 502);
    }

    // The pending marker contains no password or code. The user id is kept
    // server-side so the verify endpoint cannot be aimed at another account.
    $_SESSION['mfa_pending'] = [
        'user_id' => (int) $user['id'],
        'started_at' => time(),
        'remember_device' => $rememberDevice,
    ];

    logAudit('login_mfa_challenge', 'user', $user['username'], 'Password accepted; sign-in code emailed');

    jsonSuccess([
        'mfa_required' => true,
        'email_hint' => maskEmail($user['email']),
        'expires_in' => MFA_CODE_TTL_MINUTES * 60,
    ]);
}

$sessionUser = establishUserSession($user);
$csrfToken = generateCsrfToken();
logAudit('login_success', 'user', $user['username']);

jsonSuccess([
    'mfa_required' => false,
    'user' => $sessionUser,
    'csrf_token' => $csrfToken,
]);
