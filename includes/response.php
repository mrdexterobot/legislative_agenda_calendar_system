<?php
/**
 * response.php — consistent JSON response shape across every endpoint.
 * Every API response looks like: { "success": bool, "data": ..., "error": ... }
 *
 * BUG FIX: this used to call header('Content-Type: application/json') at
 * the top level of the file. Since includes/auth.php requires this file
 * (for jsonError(), used inside requireApiAuth()/requirePageAuth()), and
 * every protected HTML page requires auth.php, that header was firing on
 * EVERY page load — including plain HTML pages like dashboard.php — which
 * made browsers treat the HTML response as JSON/plain text instead of
 * rendering it. The header now only gets set inside jsonSuccess()/
 * jsonError() themselves, right when JSON is actually about to be sent.
 */

function jsonSuccess($data = null, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400, array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** Reads and JSON-decodes the raw request body. Returns [] if empty/invalid. */
function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
