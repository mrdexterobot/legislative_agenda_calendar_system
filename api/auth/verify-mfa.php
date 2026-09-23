<?php
/**
 * verify-mfa.php — step two of sign-in. The ONLY endpoint that creates a
 * signed-in session for an account with a second factor.
 *
 * The user id comes from $_SESSION['mfa_pending'], set by login.php after a
 * correct password, never from the request body — otherwise anyone could
 * post an arbitrary user id and grind codes against an account they had not
 * authenticated to.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mfa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$pending = $_SESSION['mfa_pending'] ?? null;
if (!$pending) {
    jsonError('This sign-in has expired. Enter your username and password again.', 440);
}

// The whole challenge dies with the code's own lifetime, so an abandoned
// half-finished sign-in can't sit open indefinitely.
if ((time() - (int) $pending['started_at']) > (MFA_CODE_TTL_MINUTES * 60)) {
    unset($_SESSION['mfa_pending']);
    jsonError('This sign-in has expired. Enter your username and password again.', 440);
}

$b = getJsonBody();
$code = trim($b['code'] ?? '');
if (!preg_match('/\A\d{6}\z/D', $code)) {
    jsonError('Enter the 6-digit code sent to your email.', 400);
}

$result = verifyMfaCode((int) $pending['user_id'], $code);

$stmt = getDb()->prepare('SELECT id, username, full_name, email, role, is_active FROM users WHERE id = :id');
$stmt->execute([':id' => $pending['user_id']]);
$user = $stmt->fetch();

if (!$user || !$user['is_active']) {
    unset($_SESSION['mfa_pending']);
    jsonError('This account is no longer active. Contact your administrator.', 403);
}

if (!$result['ok']) {
    logAudit('login_mfa_failed', 'user', $user['username'], $result['error']);
    jsonError($result['error'], 401);
}

$trustedDeviceCreated = false;
if (!empty($pending['remember_device'])) {
    try {
        rememberTrustedDevice((int) $user['id']);
        $trustedDeviceCreated = true;
    } catch (Throwable $e) {
        // The MFA challenge has already succeeded. Do not turn a temporary
        // token-storage problem into a failed login; simply require the code
        // again next time and leave the error in the server log.
        error_log('Trusted-device storage error: ' . $e->getMessage());
    }
}

$sessionUser = establishUserSession($user);
$csrfToken = generateCsrfToken();
logAudit('login_success', 'user', $user['username'], 'Signed in with a second factor');

jsonSuccess([
    'user'       => $sessionUser,
    'csrf_token' => $csrfToken,
    'trusted_device' => $trustedDeviceCreated,
]);
