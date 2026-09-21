<?php
/**
 * list-active.php — lightweight user list for "assign to" pickers (e.g.
 * the Integration Hub's simulated deadline-request form). Deliberately
 * separate from the admin-only api/users/list.php: this is reachable by
 * any logged-in user (staff need to assign deadlines too, not just
 * admins) and returns ONLY id + full_name — never username or role — so
 * it can't be used to enumerate accounts the way the admin endpoint can.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiAuth(); // any logged-in user

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$users = getDb()->query(
    "SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC"
)->fetchAll();

jsonSuccess($users);
