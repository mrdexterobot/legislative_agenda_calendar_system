<?php
/**
 * update.php — the admin's single edit endpoint for an account.
 *
 * WHAT CHANGED AND WHY
 * ---------------------------------------------------------------------------
 * The admin screen previously offered exactly two actions: create an account
 * and deactivate one. That left several dead ends with no route out through
 * the interface at all — a deactivated account could never be reactivated, a
 * locked-out account had to wait fifteen minutes with no way for an admin to
 * clear it, a mistyped username was permanent, and there was no way to give
 * somebody a new password after they had lost access to their mailbox. Each
 * of those ends with "edit the database directly," which is not an answer a
 * real office can use.
 *
 * So: username, full name, email, role, password, MFA, active state, and
 * lockout are all editable here, each with its own guard.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/password_policy.php';

$admin = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$id = isset($b['id']) ? (int) $b['id'] : 0;
if (!$id) {
    jsonError('id is required.', 400);
}

$db = getDb();
$check = $db->prepare('SELECT id, username, full_name, email, role, is_active FROM users WHERE id = :id');
$check->execute([':id' => $id]);
$target = $check->fetch();
if (!$target) {
    jsonError('User not found.', 404);
}

$isSelf = $id === (int) $admin['id'];

/** Counts active admins, used by the several "don't lock everyone out" guards. */
$activeAdmins = fn() => (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();

// Don't let the last remaining admin be demoted — that would lock everyone
// out of admin functions with no way back short of editing the database.
if (isset($b['role']) && $b['role'] !== 'admin' && $target['role'] === 'admin' && $activeAdmins() <= 1) {
    jsonError('Cannot change the role of the last remaining admin account.', 409);
}

$fields = [];
$params = [':id' => $id];
$changed = [];

// ---- Username ----
if (isset($b['username']) && trim($b['username']) !== '' && trim($b['username']) !== $target['username']) {
    $username = trim($b['username']);
    if (!preg_match('/^[a-zA-Z0-9._]{3,50}$/', $username)) {
        jsonError('Username must be 3-50 characters (letters, numbers, dots, underscores only).', 422);
    }
    $dup = $db->prepare('SELECT id FROM users WHERE username = :u AND id != :id');
    $dup->execute([':u' => $username, ':id' => $id]);
    if ($dup->fetch()) {
        jsonError('That username is already taken.', 409);
    }
    $fields[] = 'username = :username';
    $params[':username'] = $username;
    $changed[] = "username {$target['username']} -> $username";
}

// ---- Full name ----
if (isset($b['full_name']) && trim($b['full_name']) !== '') {
    $fields[] = 'full_name = :full_name';
    $params[':full_name'] = trim($b['full_name']);
    $changed[] = 'full name';
}

// ---- Email ----
if (isset($b['email']) && trim($b['email']) !== '') {
    $email = trim($b['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('That does not look like a valid email address.', 422);
    }
    $emailCheck = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
    $emailCheck->execute([':email' => $email, ':id' => $id]);
    if ($emailCheck->fetch()) {
        jsonError('That email is already in use by another account.', 409);
    }
    $fields[] = 'email = :email';
    $params[':email'] = $email;
    $changed[] = 'email';
}

// ---- Role ----
if (isset($b['role'])) {
    if (!in_array($b['role'], ['admin', 'staff'], true)) {
        jsonError('Role must be admin or staff.', 422);
    }
    $fields[] = 'role = :role';
    $params[':role'] = $b['role'];
    $changed[] = 'role -> ' . $b['role'];
}

// ---- Password ----
if (!empty($b['password'])) {
    $username = $params[':username'] ?? $target['username'];
    $email    = $params[':email'] ?? $target['email'];
    if ($problem = passwordPolicyError($b['password'], ['username' => $username, 'email' => $email])) {
        jsonError($problem, 422);
    }
    $fields[] = 'password_hash = :password_hash';
    $params[':password_hash'] = password_hash($b['password'], PASSWORD_BCRYPT);
    $changed[] = 'password reset by admin';
}

// ---- Second factor ----
if (array_key_exists('mfa_enabled', $b)) {
    $mfa = !empty($b['mfa_enabled']) ? 1 : 0;
    // Turning it off on an admin account is allowed here, but note that
    // MFA_REQUIRED_FOR_ADMINS in config.php still forces the challenge for
    // admins unless that is also switched off — so the switch reads as
    // "off" while the behaviour stays on, which would be misleading.
    $adminsForced = defined('MFA_REQUIRED_FOR_ADMINS') ? MFA_REQUIRED_FOR_ADMINS : true;
    $effectiveRole = $params[':role'] ?? $target['role'];
    if (!$mfa && $adminsForced && $effectiveRole === 'admin') {
        jsonError('Admin accounts always require a sign-in code while MFA_REQUIRED_FOR_ADMINS is on in includes/config.php. '
            . 'Change the role to staff, or switch that setting off, if this account genuinely should not use one.', 409);
    }
    $fields[] = 'mfa_enabled = :mfa';
    $params[':mfa'] = $mfa;
    $changed[] = 'sign-in code ' . ($mfa ? 'required' : 'not required');
}

// ---- Active / deactivated ----
if (array_key_exists('is_active', $b)) {
    $active = !empty($b['is_active']) ? 1 : 0;
    if (!$active) {
        if ($isSelf) {
            jsonError('You cannot deactivate your own account while signed in as it.', 409);
        }
        if ($target['role'] === 'admin' && $activeAdmins() <= 1) {
            jsonError('Cannot deactivate the last remaining admin account.', 409);
        }
    }
    $fields[] = 'is_active = :is_active';
    $params[':is_active'] = $active;
    $changed[] = $active ? 'reactivated' : 'deactivated';
}

// ---- Clear a lockout ----
if (!empty($b['unlock'])) {
    try {
        $db->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
           ->execute([':id' => $id]);
        $changed[] = 'lockout cleared';
    } catch (PDOException $e) {
        jsonError('Lockout columns are missing — run database/migration_round7_security.sql first.', 500);
    }
}

if (!$fields && !$changed) {
    jsonError('No editable fields provided.', 400);
}

if ($fields) {
    try {
        $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
    } catch (PDOException $e) {
        error_log('User update failed: ' . $e->getMessage());
        jsonError('Could not save those changes. If this mentions an unknown column, run the round 7 and round 8 migrations.', 500);
    }
}

logAudit('update', 'user', $target['username'], "Updated by {$admin['full_name']}: " . implode(', ', $changed));

jsonSuccess(['id' => $id, 'changed' => $changed]);
