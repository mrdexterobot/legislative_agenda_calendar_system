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
$label = trim($b['label'] ?? '');
if ($label === '') {
    jsonError('A label is required (e.g. "Records Management System — peer group").', 400);
}

$rawToken = bin2hex(random_bytes(32)); // shown ONCE — only the hash is stored
$hash = hash('sha256', $rawToken);

$db = getDb();
$db->prepare('INSERT INTO integration_tokens (label, token_hash) VALUES (:label, :hash)')
   ->execute([':label' => $label, ':hash' => $hash]);

logAudit('create', 'integration_token', $label, "Created by {$admin['full_name']}");

jsonSuccess([
    'label' => $label,
    'token' => $rawToken,
    'note'  => 'Save this now — it will not be shown again. Only its hash is stored.',
], 201);
