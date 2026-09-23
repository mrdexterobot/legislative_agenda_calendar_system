<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/password_policy.php';

$user = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$username = trim($b['username'] ?? '');
$password = $b['password'] ?? '';
$fullName = trim($b['full_name'] ?? '');
$email    = trim($b['email'] ?? '');
$role     = $b['role'] ?? '';
$mfa      = array_key_exists('mfa_enabled', $b)
    ? (!empty($b['mfa_enabled']) ? 1 : 0)
    : (in_array($role, ['admin', 'superadmin'], true) ? 1 : 0);

// Keep the MFA policy server-side. A caller must not be able to create an
// admin-level account without the second factor simply by sending 0.
if (defined('MFA_REQUIRED_FOR_ADMINS') && MFA_REQUIRED_FOR_ADMINS && in_array($role, ['admin', 'superadmin'], true)) {
    $mfa = 1;
}

$errors = [];
if (!preg_match('/^[a-zA-Z0-9._]{3,50}$/', $username)) {
    $errors[] = 'Username must be 3-50 characters (letters, numbers, dots, underscores only).';
}
if ($fullName === '') {
    $errors[] = 'Full name is required.';
}
// An account with no email can neither receive a reset code nor a sign-in
// code, so it is required up front rather than added later.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if (!in_array($role, ['superadmin', 'admin', 'staff'], true)) {
    $errors[] = 'Role must be superadmin, admin, or staff.';
}
if (in_array($role, ['superadmin', 'admin'], true) && $user['role'] !== 'superadmin') {
    jsonError('Only a superadmin can create an admin-level account.', 403);
}
if ($problem = passwordPolicyError($password, ['username' => $username, 'email' => $email])) {
    $errors[] = $problem;
}
if ($errors) {
    jsonError('Please fix the following: ' . implode(' ', $errors), 422);
}

$db = getDb();
$exists = $db->prepare('SELECT id FROM users WHERE username = :u');
$exists->execute([':u' => $username]);
if ($exists->fetch()) {
    jsonError('That username is already taken.', 409);
}
$emailExists = $db->prepare('SELECT id FROM users WHERE email = :e');
$emailExists->execute([':e' => $email]);
if ($emailExists->fetch()) {
    jsonError('That email is already in use by another account.', 409);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, full_name, email, role, mfa_enabled) VALUES (:u, :p, :f, :e, :r, :m)'
    );
    $stmt->execute([':u' => $username, ':p' => $hash, ':f' => $fullName, ':e' => $email, ':r' => $role, ':m' => $mfa]);
} catch (PDOException $ex) {
    // mfa_enabled arrives with the round 8 migration; don't block account
    // creation on a database that hasn't had it applied yet.
    error_log('Creating user without mfa_enabled — run the round 8 migration. ' . $ex->getMessage());
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, full_name, email, role) VALUES (:u, :p, :f, :e, :r)'
    );
    $stmt->execute([':u' => $username, ':p' => $hash, ':f' => $fullName, ':e' => $email, ':r' => $role]);
}
$newId = $db->lastInsertId();

logAudit('create', 'user', $username, "Account created by {$user['full_name']} with role $role"
    . ($mfa ? ', sign-in code required' : ''));

jsonSuccess(['id' => (int) $newId, 'username' => $username], 201);
