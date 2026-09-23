<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/mfa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireApiAuth();
requireCsrfToken();
forgetTrustedDevice((int) $user['id']);
logAudit('trusted_device_revoked', 'user', $user['username'], 'Current browser was forgotten');

jsonSuccess(['message' => 'This browser will ask for an MFA code at the next sign-in.']);
