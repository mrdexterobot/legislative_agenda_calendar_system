<?php
/**
 * integration_auth.php — separate from the session-based auth.php, since
 * calls from OTHER SUBSYSTEMS won't have a browser session/cookie. These
 * endpoints are authenticated with a bearer token instead, checked against
 * a SHA-256 hash (never the raw token) stored in integration_tokens.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

function requireIntegrationToken(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        jsonError('Missing or malformed Authorization header. Expected: Bearer <token>', 401);
    }

    $rawToken = trim($m[1]);
    $hash = hash('sha256', $rawToken);

    $stmt = getDb()->prepare('SELECT id, label FROM integration_tokens WHERE token_hash = :hash AND is_active = 1');
    $stmt->execute([':hash' => $hash]);
    $token = $stmt->fetch();

    if (!$token) {
        jsonError('Invalid or inactive integration token.', 401);
    }

    getDb()->prepare('UPDATE integration_tokens SET last_used_at = NOW() WHERE id = :id')
           ->execute([':id' => $token['id']]);

    return $token;
}
