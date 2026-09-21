<?php
/**
 * auth.php — session handling + role-based access control.
 *
 * IMPORTANT (logical-loophole fix): the original front-end draft was
 * static HTML with a login page that accepted ANY username/password and
 * did no real checking. Every module page is now a .php file that calls
 * requirePageAuth() (and requirePageRole('admin') where relevant) BEFORE
 * any HTML is output, so a logged-out user hitting the URL directly gets
 * redirected server-side — not just hidden by client-side JS, which can
 * always be bypassed by viewing/editing the page's own script.
 *
 * Every API endpoint under /api also calls requireApiAuth() /
 * requireApiRole() independently. This matters even with page guards in
 * place: nothing stops someone from calling an API endpoint directly
 * (e.g. via curl) without ever loading the page, so the data layer has to
 * defend itself regardless of what the page layer does.
 *
 * IDLE TIMEOUT (audit checklist, Section 2 — "session management"): a
 * session with no activity for SESSION_IDLE_MINUTES is destroyed on the
 * next request. Enforced here, in one place, rather than per-endpoint, so
 * no endpoint can be added later that forgets to check it. This is
 * deliberately an idle timeout rather than an absolute one: an absolute
 * cap would sign a clerk out mid-session while they were actively encoding
 * a measure, which pushes people toward keeping a second tab logged in.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/response.php';

if (!defined('SESSION_IDLE_MINUTES')) {
    define('SESSION_IDLE_MINUTES', 60);
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !APP_IS_LOCAL, // requires HTTPS once deployed; off for localhost testing
        'httponly' => true,          // JS can't read the session cookie (mitigates XSS session theft)
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Destroys an idle session. Returns true if the session was expired. */
function enforceIdleTimeout(): bool {
    if (!isset($_SESSION['user'])) {
        return false;
    }

    $last = $_SESSION['last_activity'] ?? time();
    if ((time() - $last) > (SESSION_IDLE_MINUTES * 60)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        return true;
    }

    $_SESSION['last_activity'] = time();
    return false;
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool {
    return currentUser() !== null;
}

function currentRole(): ?string {
    return currentUser()['role'] ?? null;
}

/** For API endpoints: stop with a 401 JSON response if not logged in. */
function requireApiAuth(): array {
    if (enforceIdleTimeout()) {
        // 401 is what js/api-client.js already redirects to the login page on.
        jsonError('Your session expired after ' . SESSION_IDLE_MINUTES . ' minutes of inactivity. Please sign in again.', 401);
    }
    $user = currentUser();
    if (!$user) {
        jsonError('Not logged in.', 401);
    }
    return $user;
}

/** For API endpoints: stop with a 403 JSON response if role doesn't match. */
function requireApiRole(string $role): array {
    $user = requireApiAuth();
    if ($user['role'] !== $role) {
        jsonError('You do not have permission to do that.', 403);
    }
    return $user;
}

/** For .php pages: redirect to login if not authenticated. */
function requirePageAuth(): array {
    if (enforceIdleTimeout()) {
        header('Location: ' . appBasePath() . '/index.php?reason=session_expired');
        exit;
    }
    $user = currentUser();
    if (!$user) {
        header('Location: ' . appBasePath() . '/index.php?reason=login_required');
        exit;
    }
    return $user;
}

/** For .php pages: redirect non-admins away from admin-only pages. */
function requirePageRole(string $role): array {
    $user = requirePageAuth();
    if ($user['role'] !== $role) {
        header('Location: ' . appBasePath() . '/dashboard.php?reason=not_authorized');
        exit;
    }
    return $user;
}
