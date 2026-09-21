<?php
/**
 * forgot-password.php — issues a reset code.
 *
 * A NOTE ON THE TRADEOFF, because this changed deliberately
 * ---------------------------------------------------------------------------
 * The earlier version answered identically whether or not an account existed,
 * so the page could not be used to discover who holds an account. That is the
 * right default for a public sign-up service. It is the wrong default here for
 * two reasons: accounts are issued by an administrator to a known, small set
 * of office staff (so there is no list to discover that an outsider could not
 * get by asking the Secretary), and the silence made a genuine delivery
 * failure indistinguishable from a typo in the address — which is exactly the
 * problem that made this flow look broken.
 *
 * So this endpoint now tells the person whether the account was found, and
 * reports the real reason when the email itself fails. AUTH_SHOW_ACCOUNT_LOOKUP
 * in includes/config.php flips it back to the silent behaviour in one place if
 * a panelist wants the stricter posture demonstrated.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/mfa.php'; // maskEmail()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

const RESET_CODE_TTL_MINUTES      = 15;
const RESET_RESEND_COOLDOWN_SECONDS = 30;

$showLookup = defined('AUTH_SHOW_ACCOUNT_LOOKUP') ? AUTH_SHOW_ACCOUNT_LOOKUP : true;
$generic = ['message' => 'If an account matches that username or email, a reset code has been sent.'];

$b = getJsonBody();
$identifier = trim($b['identifier'] ?? '');

if ($identifier === '') {
    jsonError('Enter your username or the email address on your account.', 400);
}

$db = getDb();

// PDO native prepared statements cannot reliably reuse a named placeholder.
// The connection deliberately has emulated prepares disabled, so bind the
// username and email comparisons separately instead of using :id twice.
$stmt = $db->prepare(
    'SELECT id, username, full_name, email, is_active
     FROM users
     WHERE username = :username OR email = :email'
);
$stmt->execute([':username' => $identifier, ':email' => $identifier]);
$user = $stmt->fetch();

// ---- No match ----
if (!$user || !$user['is_active'] || empty($user['email'])) {
    logAudit('password_reset_requested', 'user', $identifier, 'No matching active account — no code issued');

    if (!$showLookup) {
        jsonSuccess($generic);
    }

    if ($user && !$user['is_active']) {
        jsonError('That account has been deactivated. Contact your administrator.', 403);
    }
    if ($user && empty($user['email'])) {
        jsonError('That account has no email address on file, so a code cannot be sent. Ask an administrator to add one.', 409);
    }
    jsonError('No active account is registered with that username or email address.', 404);
}

// ---- Rate limit, so this cannot be used to flood an inbox ----
$recent = $db->prepare(
    'SELECT created_at FROM password_resets WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1'
);
try {
    $recent->execute([':uid' => $user['id']]);
    $last = $recent->fetch();
} catch (PDOException $e) {
    // The table itself is missing — see below; handled in one place.
    $last = null;
}

if ($last) {
    $elapsed = time() - strtotime($last['created_at']);
    if ($elapsed < RESET_RESEND_COOLDOWN_SECONDS) {
        $wait = RESET_RESEND_COOLDOWN_SECONDS - $elapsed;
        jsonError("A code was just sent. You can request another in $wait seconds.", 429);
    }
}

// ---- Issue the code ----
try {
    // One live code per account at a time, so there is never more than one
    // guessable code outstanding.
    $db->prepare('DELETE FROM password_resets WHERE user_id = :uid AND used_at IS NULL')
       ->execute([':uid' => $user['id']]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $db->prepare('INSERT INTO password_resets (user_id, code_hash, expires_at) VALUES (:uid, :hash, :exp)')
       ->execute([
           ':uid'  => $user['id'],
           ':hash' => hash('sha256', $code),
           ':exp'  => date('Y-m-d H:i:s', strtotime('+' . RESET_CODE_TTL_MINUTES . ' minutes')),
       ]);
} catch (PDOException $e) {
    // THE FAILURE THAT LOOKED LIKE "NOTHING HAPPENS": if password_resets does
    // not exist in this database, every request died here and the old catch
    // block turned it into a cheerful generic success. Say so instead.
    error_log('forgot-password.php: could not record a reset code — ' . $e->getMessage());
    jsonError('Password reset is not available: the password_resets table is missing from the database. '
        . 'Run database/migration_round8_mfa_and_accounts.sql, then try again.', 500);
}

$result = sendEmail(
    $user['email'],
    'Password reset code — Legislative Agenda & Calendar Management System',
    "Hello {$user['full_name']},\n\n"
    . "Your password reset code is: $code\n\n"
    . 'This code expires in ' . RESET_CODE_TTL_MINUTES . " minutes.\n"
    . "If you did not request it, you can ignore this email and your password stays unchanged.\n"
);

logAudit(
    'password_reset_requested',
    'user',
    $user['username'],
    $result['success'] ? 'Reset code emailed successfully' : 'Reset code generated but the email FAILED: ' . $result['error']
);

if (!$result['success']) {
    if (!$showLookup) {
        jsonSuccess($generic);
    }
    jsonError('The reset code could not be emailed: ' . $result['error'], 502);
}

jsonSuccess([
    'message'    => 'A 6-digit code is on its way to ' . maskEmail($user['email']) . '.',
    'email_hint' => maskEmail($user['email']),
    'expires_in' => RESET_CODE_TTL_MINUTES * 60,
]);
