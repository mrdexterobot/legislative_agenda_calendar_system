<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

$user = currentUser();
if (!$user) {
    jsonError('Not logged in.', 401);
}

$token = $_SESSION['csrf_token'] ?? generateCsrfToken();

jsonSuccess([
    'user'       => $user,
    'csrf_token' => $token,
]);
