<?php
/**
 * config.sample.php
 *
 * TEMPLATE — copy this file to "config.php" and fill in real values there.
 * config.php is excluded from git (see .gitignore) so real credentials never
 * get committed. This sample file is safe to commit.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Direct access not permitted.');
}


// ---- Database ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'legislative_agenda_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---- AI (Groq) ----
define('GROQ_API_KEY', 'PASTE_YOUR_GROQ_KEY_HERE');
define('GROQ_MODEL', 'openai/gpt-oss-20b');

// ---- Email (SMTP via Brevo) ----
//   SMTP_USERNAME    SMTP & API -> SMTP tab -> "Login" (9xxxxx@smtp-brevo.com)
//   SMTP_PASSWORD    the generated key on that same tab ("xsmtpsib-..."),
//                    NOT your Brevo account password
//   SMTP_FROM_EMAIL  an address shown as Verified under Senders, Domains &
//                    Dedicated IPs -> Senders. It does not have to match
//                    SMTP_USERNAME. An unverified sender is rejected at
//                    "MAIL FROM" before the message is ever queued.
// If port 587 is blocked on your network or host, Brevo also accepts 2525.
// Check all of this at Admin -> Email Delivery Test.
define('SMTP_HOST', 'smtp-relay.brevo.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'PASTE_YOUR_SMTP_LOGIN_HERE');
define('SMTP_PASSWORD', 'PASTE_YOUR_SMTP_KEY_HERE');
define('SMTP_FROM_EMAIL', 'PASTE_YOUR_VERIFIED_SENDER_EMAIL_HERE');
define('SMTP_FROM_NAME', 'Legislative Agenda & Calendar Management System');

// ---- Authentication ----
// Random secret; replace before deploying anywhere reachable.
define('APP_SECRET', 'change-this-to-a-long-random-string');

// Set to false once deployed on a real HTTPS domain. Controls whether the
// session cookie is marked "Secure" — browsers refuse to send a Secure cookie
// over plain HTTP, which would break login on localhost.
define('APP_IS_LOCAL', true);

// Minutes of inactivity before a signed-in session is destroyed.
define('SESSION_IDLE_MINUTES', 60);

// Every admin account requires an emailed sign-in code, regardless of the
// per-account setting in Admin -> Manage User Accounts. THIS IS THE ESCAPE
// HATCH: if email delivery breaks and an admin cannot receive a code, set
// this to false, sign in, fix the mail settings, then set it back.
define('MFA_REQUIRED_FOR_ADMINS', true);

// Whether the forgot-password page says out loud that no account matched.
// True is correct for this system — accounts are issued by an administrator
// to a known set of office staff, so there is no user list to protect, and
// silence makes a mistyped address indistinguishable from a delivery
// failure. Set to false to demonstrate the stricter, non-enumerable posture
// used on public sign-up services.
define('AUTH_SHOW_ACCOUNT_LOOKUP', true);

/**
 * Computes the app's base path so the same code works at a domain root or in
 * a subfolder such as htdocs/legislative-agenda-system/.
 */
function appBasePath(): string {
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';

    if ($docRoot !== '' && str_starts_with($projectRoot, $docRoot)) {
        $base = substr($projectRoot, strlen($docRoot));
    } else {
        $base = '';
    }

    return $base;
}
