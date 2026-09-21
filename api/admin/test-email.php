<?php
/**
 * test-email.php — admin-only SMTP diagnostics.
 *
 * Every other place that sends mail deliberately hides failures: the
 * forgot-password endpoint returns an identical response whether or not the
 * account exists (so it can't be used to enumerate users), and the meeting
 * notifier reports per-recipient results after the fact. That's correct
 * behaviour, but it also means a misconfigured SMTP setup is invisible.
 *
 * This endpoint is the one place that reports the raw failure, and it's
 * restricted to admins because SMTP errors leak configuration detail.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mailer.php';

$admin = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b  = getJsonBody();
$to = trim($b['to'] ?? '');

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    jsonError('Enter a real email address you can open — that is the only way to prove delivery.', 422);
}

$issues = smtpConfigIssues();
if ($issues) {
    jsonError('SMTP is not configured yet: ' . implode(' ', $issues), 422, [
        'stage'  => 'configuration',
        'issues' => $issues,
    ]);
}

$result = sendEmail(
    $to,
    'SMTP test — Legislative Agenda & Calendar Management System',
    "This is a test message sent from the system's Admin → Email Delivery Test page.\n\n"
    . "Sent by: {$admin['full_name']}\n"
    . 'Server time: ' . date('F j, Y g:i A') . "\n\n"
    . "If you are reading this, outbound email works: password reset codes and meeting notifications "
    . "will reach recipients the same way."
);

logAudit('test_email', 'system', $to, ($result['success'] ? 'Succeeded' : 'Failed: ' . $result['error']));

if (!$result['success']) {
    jsonError($result['error'], 502, ['stage' => 'delivery']);
}

jsonSuccess([
    'to'   => $to,
    'from' => SMTP_FROM_EMAIL,
    'note' => 'Accepted by the relay. If it does not appear within a minute, check the spam folder and the '
            . 'Brevo dashboard → Statistics → Email, which shows whether it was delivered, soft-bounced, or blocked.',
]);
