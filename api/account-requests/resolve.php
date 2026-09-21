<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';

$admin = requireApiRole('admin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$b = getJsonBody();
$id     = isset($b['id']) ? (int) $b['id'] : 0;
$action = $b['action'] ?? '';
$notes  = trim($b['admin_notes'] ?? '');

if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    jsonError('id and a valid action (approve|reject) are required.', 400);
}
if ($action === 'reject' && $notes === '') {
    jsonError('Please explain why this request is being rejected.', 422);
}

$db = getDb();
$stmt = $db->prepare('SELECT ar.*, u.role FROM account_requests ar JOIN users u ON u.id = ar.user_id WHERE ar.id = :id');
$stmt->execute([':id' => $id]);
$req = $stmt->fetch();

if (!$req) {
    jsonError('Request not found.', 404);
}
if ($req['status'] !== 'pending') {
    jsonError('This request was already reviewed.', 409);
}

// GOVERNANCE CHECK: an admin approving their OWN request while signed in
// bypasses the point of routing it through review — the same principle as
// refusing to let an admin deactivate the account they are using.
if ((int) $req['user_id'] === (int) $admin['id']) {
    jsonError('You cannot review your own account request — ask another admin to handle it.', 403);
}

$db->beginTransaction();
try {
    if ($action === 'approve') {
        if ($req['request_type'] === 'deactivation') {
            if ($req['role'] === 'admin') {
                $activeAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                if ($activeAdmins <= 1) {
                    throw new RuntimeException('Cannot deactivate the last remaining admin account.');
                }
            }
            $db->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute([':id' => $req['user_id']]);
        } else { // info_update
            $fields = [];
            $params = [':id' => $req['user_id']];

            if (!empty($req['requested_full_name'])) {
                $fields[] = 'full_name = :full_name';
                $params[':full_name'] = $req['requested_full_name'];
            }

            // Uniqueness is re-checked here, not just at request time: another
            // account may have taken the name or address in the meantime.
            if (!empty($req['requested_username'])) {
                if (!preg_match('/^[a-zA-Z0-9._]{3,50}$/', $req['requested_username'])) {
                    throw new RuntimeException('The requested username is not a valid format — ask the requester to submit a different one.');
                }
                $check = $db->prepare('SELECT id FROM users WHERE username = :u AND id != :id');
                $check->execute([':u' => $req['requested_username'], ':id' => $req['user_id']]);
                if ($check->fetch()) {
                    throw new RuntimeException('That username is now used by another account — ask the requester to pick a different one.');
                }
                $fields[] = 'username = :username';
                $params[':username'] = $req['requested_username'];
            }

            if (!empty($req['requested_email'])) {
                $emailCheck = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
                $emailCheck->execute([':email' => $req['requested_email'], ':id' => $req['user_id']]);
                if ($emailCheck->fetch()) {
                    throw new RuntimeException('That email is now used by another account — ask the requester to pick a different one.');
                }
                $fields[] = 'email = :email';
                $params[':email'] = $req['requested_email'];
            }

            if ($fields) {
                $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
            }
        }
    }

    $db->prepare(
        "UPDATE account_requests SET status = :status, admin_notes = :notes, reviewed_by = :by, reviewed_at = NOW() WHERE id = :id"
    )->execute([
        ':status' => $action === 'approve' ? 'approved' : 'rejected',
        ':notes'  => $notes ?: null,
        ':by'     => $admin['full_name'],
        ':id'     => $id,
    ]);

    $db->commit();
} catch (RuntimeException $e) {
    $db->rollBack();
    jsonError($e->getMessage(), 409);
} catch (Exception $e) {
    $db->rollBack();
    error_log('Failed to resolve account request: ' . $e->getMessage());
    jsonError('Something went wrong. Please try again.', 500);
}

$detail = "$action by {$admin['full_name']}"
    . (!empty($req['requested_username']) && $action === 'approve' ? " (username changed to {$req['requested_username']} — they now sign in with it)" : '')
    . ($notes ? ": $notes" : '');
logAudit('resolve_account_request', 'account_request', (string) $id, $detail);

jsonSuccess([
    'id'     => $id,
    'status' => $action === 'approve' ? 'approved' : 'rejected',
    'username_changed' => $action === 'approve' && !empty($req['requested_username']),
]);
