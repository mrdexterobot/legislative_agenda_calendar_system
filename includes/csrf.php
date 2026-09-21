<?php
/**
 * csrf.php — CSRF protection for state-changing (POST/PUT/DELETE) requests.
 *
 * The token is generated at login, stored in the session, and returned to
 * the client once. The client must echo it back in an "X-CSRF-Token"
 * header on every mutating request. GET requests are exempt (they should
 * never change state in this API — see the loophole notes in each
 * endpoint that only reads data).
 */

require_once __DIR__ . '/response.php';

function generateCsrfToken(): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

function requireCsrfToken(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return; // safe methods, no state change expected
    }

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? null;

    if (!$expected || !$sent || !hash_equals($expected, $sent)) {
        jsonError('Invalid or missing CSRF token. Please refresh and try again.', 403);
    }
}
