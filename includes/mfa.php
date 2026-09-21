<?php
/**
 * mfa.php — second-factor codes emailed to the address on the account.
 *
 * Kept in one file, the same way includes/ai_priority.php bounds the AI
 * integration, so the whole second-factor mechanism can be read, tested, or
 * swapped (for TOTP, say) without touching the login endpoint's own logic.
 *
 * Flow across three requests:
 *   1. api/auth/login.php verifies the password. If the account requires a
 *      second factor it does NOT create a signed-in session — it records
 *      $_SESSION['mfa_pending'] and calls issueMfaCode().
 *   2. api/auth/verify-mfa.php checks the code against mfa_codes and only
 *      then establishes the real session.
 *   3. api/auth/resend-mfa.php issues a replacement, rate-limited.
 *
 * The pending user id lives in the server-side session, never in the request
 * body — otherwise anyone could post someone else's user id at step 2 and
 * brute-force a code against an account they never authenticated to.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const MFA_CODE_TTL_MINUTES = 10;
const MFA_MAX_ATTEMPTS     = 5;
const MFA_RESEND_COOLDOWN_SECONDS = 30;

/** Does this account have to pass a second factor? */
function mfaRequiredFor(array $user): bool {
    if (!empty($user['mfa_enabled'])) {
        return true;
    }
    $adminsRequired = defined('MFA_REQUIRED_FOR_ADMINS') ? MFA_REQUIRED_FOR_ADMINS : true;
    return $adminsRequired && ($user['role'] ?? '') === 'admin';
}

/**
 * Generates a code, stores only its hash, and emails it.
 * Returns ['success' => bool, 'error' => ?string].
 */
function issueMfaCode(int $userId, string $email, string $fullName): array {
    $db = getDb();

    // One live code per account — a new request invalidates the previous one,
    // so there is never more than one guessable code outstanding.
    $db->prepare('DELETE FROM mfa_codes WHERE user_id = :uid AND used_at IS NULL')
       ->execute([':uid' => $userId]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $db->prepare(
        'INSERT INTO mfa_codes (user_id, code_hash, expires_at, ip_address) VALUES (:uid, :hash, :exp, :ip)'
    )->execute([
        ':uid'  => $userId,
        ':hash' => hash('sha256', $code),
        ':exp'  => date('Y-m-d H:i:s', strtotime('+' . MFA_CODE_TTL_MINUTES . ' minutes')),
        ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    return sendEmail(
        $email,
        'Sign-in code: ' . $code . ' — Legislative Agenda & Calendar Management System',
        "Hello $fullName,\n\n"
        . "Your sign-in code is: $code\n\n"
        . 'It expires in ' . MFA_CODE_TTL_MINUTES . " minutes and can be used once.\n\n"
        . "If you did not just try to sign in, someone else has your password. "
        . "Change it immediately and tell your system administrator.\n"
    );
}

/**
 * Checks a submitted code. Returns ['ok' => bool, 'error' => ?string].
 * Consumes the code on success and counts the attempt on failure.
 */
function verifyMfaCode(int $userId, string $code): array {
    $db = getDb();

    $stmt = $db->prepare(
        'SELECT id, code_hash, attempts, expires_at FROM mfa_codes
         WHERE user_id = :uid AND used_at IS NULL ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();

    $generic = 'That code is incorrect or has expired. Request a new one.';

    if (!$row || strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'error' => $generic];
    }

    if ((int) $row['attempts'] >= MFA_MAX_ATTEMPTS) {
        $db->prepare('UPDATE mfa_codes SET used_at = NOW() WHERE id = :id')->execute([':id' => $row['id']]);
        return ['ok' => false, 'error' => 'Too many incorrect codes. Request a new one.'];
    }

    if (!hash_equals($row['code_hash'], hash('sha256', trim($code)))) {
        $db->prepare('UPDATE mfa_codes SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $row['id']]);
        return ['ok' => false, 'error' => $generic];
    }

    $db->prepare('UPDATE mfa_codes SET used_at = NOW() WHERE id = :id')->execute([':id' => $row['id']]);
    return ['ok' => true, 'error' => null];
}

/** Seconds remaining before another code may be sent, 0 if one may be sent now. */
function mfaResendCooldownRemaining(int $userId): int {
    $stmt = getDb()->prepare(
        'SELECT created_at FROM mfa_codes WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }
    $elapsed = time() - strtotime($row['created_at']);
    return max(0, MFA_RESEND_COOLDOWN_SECONDS - $elapsed);
}

/** Masks an address for display: jamesfulo90@gmail.com -> ja•••••••90@gmail.com */
function maskEmail(string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return '•••';
    }
    $len = strlen($local);
    if ($len <= 3) {
        return substr($local, 0, 1) . str_repeat('•', max(1, $len - 1)) . '@' . $domain;
    }
    return substr($local, 0, 2) . str_repeat('•', $len - 4) . substr($local, -2) . '@' . $domain;
}

/** Establishes the signed-in session. Shared by login.php and verify-mfa.php. */
function establishUserSession(array $user): array {
    session_regenerate_id(true);
    unset($_SESSION['mfa_pending']);

    $_SESSION['user'] = [
        'id'        => (int) $user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ];
    $_SESSION['last_activity'] = time();

    return $_SESSION['user'];
}
