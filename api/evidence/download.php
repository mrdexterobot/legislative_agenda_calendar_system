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
require_once __DIR__ . '/../../includes/evidence.php';

$user = requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) {
    jsonError('id is required.', 400);
}

$db = getDb();
$stmt = $db->prepare(
    'SELECT id, entity_type, entity_id, original_filename, stored_filename, file_size, mime_type
     FROM evidence_attachments WHERE id = :id'
);
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
            && !isAdminOrAbove($user);
        if ($isAssignedToSomeoneElse) {
            jsonError('This deadline is assigned to ' . ($deadline['assigned_to_name'] ?? 'another user') . '.', 403);
        }
    }
}
if ($attachment['entity_type'] === 'agenda_item' && !isAdminOrAbove($user)) {
    jsonError('Only an administrator can download Mayor-action evidence.', 403);
}

if (!evidenceStoredFilenameIsSafe($attachment['stored_filename'])) {
    jsonError('The stored file reference is invalid.', 404);
}

$path = __DIR__ . '/../../uploads/evidence/' . $attachment['stored_filename'];
if (!is_file($path)) {
    jsonError('The file is missing from storage.', 404);
}

$downloadName = evidenceDownloadFilename($attachment['original_filename'], 'evidence-download');
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $downloadName) ?? 'evidence-download';
$asciiName = str_replace(['\\', '"', ';'], '_', $asciiName);
$contentLength = filesize($path);

header('Content-Type: ' . evidenceDownloadMimeType($attachment['mime_type'], $attachment['stored_filename']));
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
if ($contentLength !== false) {
    header('Content-Length: ' . $contentLength);
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
