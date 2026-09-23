<?php
/**
 * MFA codes and trusted-device sessions.
 *
 * A trusted device never replaces the password. It only skips the emailed
 * second factor after the password has already been verified. The browser
 * receives a random opaque token; only its SHA-256 hash is stored in MySQL.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const MFA_CODE_TTL_MINUTES = 10;
const MFA_MAX_ATTEMPTS = 5;
const MFA_RESEND_COOLDOWN_SECONDS = 30;
const TRUSTED_DEVICE_COOKIE = 'sjdm_trusted_device';
const TRUSTED_DEVICE_TTL_DAYS = 30;

/** Does this account have to pass a second factor? */
function mfaRequiredFor(array $user): bool
{
    if (!empty($user['mfa_enabled'])) {
        return true;
    }

    $adminsRequired = defined('MFA_REQUIRED_FOR_ADMINS') ? MFA_REQUIRED_FOR_ADMINS : true;
    return $adminsRequired && in_array($user['role'] ?? '', ['admin', 'superadmin'], true);
}

/**
 * Generates a code, stores only its hash, and emails it.
 * Returns ['success' => bool, 'error' => ?string].
 */
function issueMfaCode(int $userId, string $email, string $fullName): array
{
    $db = getDb();
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Delete + insert is one unit, so concurrent login/resend requests cannot
    // leave two valid codes behind.
    $db->beginTransaction();
    try {
        // Serialize code issuance per account, not globally.
        $db->prepare('SELECT id FROM users WHERE id = :uid FOR UPDATE')
            ->execute([':uid' => $userId]);
        $db->prepare('DELETE FROM mfa_codes WHERE user_id = :uid AND used_at IS NULL')
            ->execute([':uid' => $userId]);

        $db->prepare(
            'INSERT INTO mfa_codes (user_id, code_hash, expires_at, ip_address)
             VALUES (:uid, :hash, :exp, :ip)'
        )->execute([
            ':uid' => $userId,
            ':hash' => hash('sha256', $code),
            ':exp' => gmdate('Y-m-d H:i:s', time() + (MFA_CODE_TTL_MINUTES * 60)),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return sendEmail(
        $email,
        'Sign-in code: ' . $code . ' - Legislative Agenda & Calendar Management System',
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
function verifyMfaCode(int $userId, string $code): array
{
    $db = getDb();
    $generic = 'That code is incorrect or has expired. Request a new one.';

    // Lock the row while checking and consuming it. Without this, two
    // concurrent requests could both verify the same one-time code.
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT id, code_hash, attempts, expires_at FROM mfa_codes
             WHERE user_id = :uid AND used_at IS NULL
             ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expires_at']) < time()) {
            $db->commit();
            return ['ok' => false, 'error' => $generic];
        }

        if ((int) $row['attempts'] >= MFA_MAX_ATTEMPTS) {
            $db->prepare('UPDATE mfa_codes SET used_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute([':id' => $row['id']]);
            $db->commit();
            return ['ok' => false, 'error' => 'Too many incorrect codes. Request a new one.'];
        }

        if (!hash_equals($row['code_hash'], hash('sha256', trim($code)))) {
            $db->prepare('UPDATE mfa_codes SET attempts = attempts + 1 WHERE id = :id')
                ->execute([':id' => $row['id']]);
            $db->commit();
            return ['ok' => false, 'error' => $generic];
        }

        $db->prepare('UPDATE mfa_codes SET used_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute([':id' => $row['id']]);
        $db->commit();
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Seconds remaining before another code may be sent. */
function mfaResendCooldownRemaining(int $userId): int
{
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

/** Masks an address for display. */
function maskEmail(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return '***';
    }
    $len = strlen($local);
    if ($len <= 3) {
        return substr($local, 0, 1) . str_repeat('*', max(1, $len - 1)) . '@' . $domain;
    }
    return substr($local, 0, 2) . str_repeat('*', $len - 4) . substr($local, -2) . '@' . $domain;
}

/** Returns the cookie path for a root install or a subdirectory install. */
function trustedDeviceCookiePath(): string
{
    $base = appBasePath();
    return $base === '' ? '/' : rtrim($base, '/') . '/';
}

function trustedDeviceCookieSecure(): bool
{
    return defined('APP_IS_LOCAL')
        ? !APP_IS_LOCAL
        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

function clearTrustedDeviceCookie(): void
{
    setcookie(TRUSTED_DEVICE_COOKIE, '', [
        'expires' => 1,
        'path' => trustedDeviceCookiePath(),
        'secure' => trustedDeviceCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Checks the remember-device token only after the password was verified. */
function hasTrustedDevice(int $userId): bool
{
    $token = $_COOKIE[TRUSTED_DEVICE_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
        return false;
    }

    $stmt = getDb()->prepare(
        'SELECT id FROM trusted_devices
         WHERE user_id = :uid AND token_hash = :hash
           AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':hash' => hash('sha256', $token),
    ]);
    $row = $stmt->fetch();

    if (!$row) {
        clearTrustedDeviceCookie();
        return false;
    }

    getDb()->prepare('UPDATE trusted_devices SET last_used_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute([':id' => $row['id']]);
    return true;
}

/** Creates a 30-day trusted-device token after a successful MFA challenge. */
function rememberTrustedDevice(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $db = getDb();

    $db->beginTransaction();
    try {
        $db->prepare(
            'DELETE FROM trusted_devices
             WHERE user_id = :uid AND (revoked_at IS NOT NULL OR expires_at <= UTC_TIMESTAMP())'
        )->execute([':uid' => $userId]);

        $db->prepare(
            'INSERT INTO trusted_devices
                (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (:uid, :hash, :expires, :ip, :agent)'
        )->execute([
            ':uid' => $userId,
            ':hash' => hash('sha256', $token),
            ':expires' => gmdate('Y-m-d H:i:s', time() + (TRUSTED_DEVICE_TTL_DAYS * 86400)),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    setcookie(TRUSTED_DEVICE_COOKIE, $token, [
        'expires' => time() + (TRUSTED_DEVICE_TTL_DAYS * 86400),
        'path' => trustedDeviceCookiePath(),
        'secure' => trustedDeviceCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Revokes the current browser's trusted-device token and clears its cookie. */
function forgetTrustedDevice(int $userId): void
{
    $token = $_COOKIE[TRUSTED_DEVICE_COOKIE] ?? '';
    if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
        getDb()->prepare(
            'DELETE FROM trusted_devices WHERE user_id = :uid AND token_hash = :hash'
        )->execute([
            ':uid' => $userId,
            ':hash' => hash('sha256', $token),
        ]);
    }
    clearTrustedDeviceCookie();
}

/** Forces MFA again on every browser after a password/security change. */
function revokeAllTrustedDevices(int $userId): void
{
    getDb()->prepare('DELETE FROM trusted_devices WHERE user_id = :uid')
        ->execute([':uid' => $userId]);
}

/** Establishes the signed-in session. Shared by login.php and verify-mfa.php. */
function establishUserSession(array $user): array
{
    session_regenerate_id(true);
    unset($_SESSION['mfa_pending']);

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
    $_SESSION['last_activity'] = time();

    return $_SESSION['user'];
}
