<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireApiAuth();
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$type              = $b['request_type'] ?? '';
$reason            = trim($b['reason'] ?? '');
$requestedFullName = trim($b['requested_full_name'] ?? '') ?: null;
$requestedEmail    = trim($b['requested_email'] ?? '') ?: null;
$requestedUsername = trim($b['requested_username'] ?? '') ?: null;

if (!in_array($type, ['info_update', 'deactivation'], true)) {
    jsonError('request_type must be info_update or deactivation.', 400);
}
if ($reason === '') {
    jsonError('Please explain the reason for this request.', 422);
}
if ($type === 'info_update' && !$requestedFullName && !$requestedEmail && !$requestedUsername) {
    jsonError('Provide at least a new name, username, or email for an info-update request.', 422);
}
if ($requestedEmail !== null && !filter_var($requestedEmail, FILTER_VALIDATE_EMAIL)) {
    jsonError('That does not look like a valid email address.', 422);
}
// Validated at request time as well as at approval, so the requester finds
// out immediately rather than waiting for an admin to hit a rejection.
if ($requestedUsername !== null && !preg_match('/^[a-zA-Z0-9._]{3,50}$/', $requestedUsername)) {
    jsonError('A username must be 3-50 characters, using only letters, numbers, dots, and underscores.', 422);
}

$db = getDb();

if ($requestedUsername !== null) {
    $taken = $db->prepare('SELECT id FROM users WHERE username = :u AND id != :id');
    $taken->execute([':u' => $requestedUsername, ':id' => $user['id']]);
    if ($taken->fetch()) {
        jsonError('That username is already taken by another account.', 409);
    }
}
if ($requestedEmail !== null) {
    $takenEmail = $db->prepare('SELECT id FROM users WHERE email = :e AND id != :id');
    $takenEmail->execute([':e' => $requestedEmail, ':id' => $user['id']]);
    if ($takenEmail->fetch()) {
        jsonError('That email is already in use by another account.', 409);
    }
}

// Don't let requests pile up — a user with an existing pending request of the
// same type should wait for it to be reviewed.
$dupCheck = $db->prepare("SELECT id FROM account_requests WHERE user_id = :uid AND request_type = :type AND status = 'pending'");
$dupCheck->execute([':uid' => $user['id'], ':type' => $type]);
if ($dupCheck->fetch()) {
    jsonError('You already have a pending request of this type — wait for it to be reviewed before submitting another.', 409);
}

try {
    $db->prepare(
        "INSERT INTO account_requests (user_id, requested_by, request_type, requested_username, requested_full_name, requested_email, reason)
         VALUES (:uid, :by, :type, :uname, :name, :email, :reason)"
    )->execute([
        ':uid'    => $user['id'],
        ':by'     => $user['full_name'],
        ':type'   => $type,
        ':uname'  => $requestedUsername,
        ':name'   => $requestedFullName,
        ':email'  => $requestedEmail,
        ':reason' => $reason,
    ]);
} catch (PDOException $e) {
    error_log('account_requests insert failed — run the round 8 migration for requested_username. ' . $e->getMessage());
    jsonError('Could not submit that request. If this persists, the requested_username column is missing — run database/migration_round8_mfa_and_accounts.sql.', 500);
}
$id = (int) $db->lastInsertId();

logAudit('create', 'account_request', (string) $id, "{$user['full_name']} requested $type: $reason");

jsonSuccess(['id' => $id], 201);
