<?php
/**
 * download.php — the ONLY way to actually retrieve an uploaded evidence
 * file's bytes. uploads/evidence/.htaccess blocks direct browser access
 * to the folder, so this permission check is the real gate, not the
 * .htaccess alone (same "PHP-level guard, not just .htaccess" principle
 * used for includes/config.php).
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) {
    jsonError('id is required.', 400);
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM evidence_attachments WHERE id = :id');
$stmt->execute([':id' => $id]);
$attachment = $stmt->fetch();
if (!$attachment) {
    jsonError('Attachment not found.', 404);
}

if ($attachment['entity_type'] === 'deadline') {
    $dStmt = $db->prepare('SELECT assigned_to_user_id, assigned_to_name FROM deadlines WHERE id = :id');
    $dStmt->execute([':id' => $attachment['entity_id']]);
    $deadline = $dStmt->fetch();
    if ($deadline) {
        $isAssignedToSomeoneElse = $deadline['assigned_to_user_id'] !== null
            && (int) $deadline['assigned_to_user_id'] !== (int) $user['id']
            && $user['role'] !== 'admin';
        if ($isAssignedToSomeoneElse) {
            jsonError('This deadline is assigned to ' . ($deadline['assigned_to_name'] ?? 'another user') . '.', 403);
        }
    }
}

$path = __DIR__ . '/../../uploads/evidence/' . $attachment['stored_filename'];
if (!is_file($path)) {
    jsonError('The file is missing from storage.', 404);
}

header('Content-Type: ' . ($attachment['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $attachment['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
