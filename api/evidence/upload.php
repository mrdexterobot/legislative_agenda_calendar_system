<?php
/**
 * upload.php — accepts ONE evidence file (deadline completion, session
 * completion, or Mayor-action confirmation) via multipart/form-data. Kept generic across
 * entity types via (entity_type, entity_id) rather than a separate
 * endpoint per module, since the validation/storage logic is identical.
 *
 * Files are stored under /uploads/evidence with a random filename (never
 * the original name) — uploads/evidence/.htaccess denies direct browser
 * access, so the ONLY way to retrieve a file is through download.php,
 * which re-checks the same permission this endpoint checks at upload time.
 *
 * This is intentionally attach-then-associate, not part of the
 * mark-complete request itself: the entity (deadline/session) already
 * exists with a real ID before completion, so the file can be uploaded
 * and linked immediately, then the mark-complete action just proceeds
 * normally — no extra plumbing needed in api/deadlines/update.php or
 * api/sessions/update.php.
 *
 * KNOWN LIMITATION: if a user attaches a file then cancels the modal
 * without completing, the attachment row stays (harmless orphan, still
 * tied to the correct entity — just uploaded before completion rather
 * than exactly at completion). Not cleaned up automatically; acceptable
 * for a capstone-scale system but worth knowing.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/evidence.php';

$user = requireApiAuth();
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$entityType = $_POST['entity_type'] ?? '';
$entityId   = trim($_POST['entity_id'] ?? '');

if (!in_array($entityType, ['deadline', 'session', 'agenda_item'], true) || $entityId === '') {
    jsonError('entity_type (deadline|session|agenda_item) and entity_id are required.', 400);
}

$db = getDb();

// ---- Confirm the entity exists, and that this user is allowed to attach
// evidence to it (same rule as who's allowed to mark it complete) ----
if ($entityType === 'deadline') {
    $stmt = $db->prepare('SELECT id, assigned_to_user_id, assigned_to_name FROM deadlines WHERE id = :id');
    $stmt->execute([':id' => $entityId]);
    $entity = $stmt->fetch();
    if (!$entity) {
        jsonError('Deadline not found.', 404);
    }
    $isAssignedToSomeoneElse = $entity['assigned_to_user_id'] !== null
        && (int) $entity['assigned_to_user_id'] !== (int) $user['id']
        && !isAdminOrAbove($user);
    if ($isAssignedToSomeoneElse) {
        jsonError('This deadline is assigned to ' . ($entity['assigned_to_name'] ?? 'another user') . '. Only they or an admin can attach evidence to it.', 403);
    }
} elseif ($entityType === 'agenda_item') {
    if (!isAdminOrAbove($user)) {
        jsonError('Only an administrator can attach Mayor-action evidence.', 403);
    }
    $stmt = $db->prepare('SELECT id FROM agenda_items WHERE id = :id AND is_archived = 0');
    $stmt->execute([':id' => $entityId]);
    if (!$stmt->fetch()) {
        jsonError('Agenda item not found.', 404);
    }
} else { // session — not per-user restricted, matches the shared calendar's existing access model
    $stmt = $db->prepare('SELECT id FROM sessions WHERE id = :id');
    $stmt->execute([':id' => $entityId]);
    if (!$stmt->fetch()) {
        jsonError('Session not found.', 404);
    }
}

// ---- File validation ----
if (!isset($_FILES['file']) || !is_array($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    jsonError('No file was uploaded.', 400);
}
$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jsonError('Upload failed (error code ' . $file['error'] . '). Try a smaller file.', 400);
}

$temporaryPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
    jsonError('The uploaded file could not be verified.', 400);
}

$fileSize = filesize($temporaryPath);
if ($fileSize === false) {
    jsonError('The uploaded file size could not be determined.', 400);
}
if ($fileSize > EVIDENCE_MAX_BYTES) {
    jsonError('File is too large — 10MB maximum.', 422);
}

$rawName = is_string($file['name'] ?? null) ? $file['name'] : '';
$ext = strtolower(pathinfo(str_replace('\\', '/', $rawName), PATHINFO_EXTENSION));
if (!in_array($ext, EVIDENCE_ALLOWED_EXTENSIONS, true)) {
    jsonError('Unsupported file type. Allowed: ' . implode(', ', EVIDENCE_ALLOWED_EXTENSIONS) . '.', 422);
}

try {
    $detectedMime = evidenceValidateFile($temporaryPath, $ext);
} catch (InvalidArgumentException $e) {
    jsonError('Unsupported or invalid document file.', 422);
} catch (RuntimeException $e) {
    error_log('Evidence file validation failed: ' . $e->getMessage());
    jsonError('The uploaded file could not be validated. Please try again later.', 500);
}
$originalName = evidenceSanitizeOriginalFilename($rawName, $ext);

$uploadDir = __DIR__ . '/../../uploads/evidence';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        error_log('Failed to create the evidence upload directory.');
        jsonError('Could not prepare file storage. Please try again later.', 500);
    }
}
if (!is_writable($uploadDir)) {
    error_log('Evidence upload directory is not writable.');
    jsonError('File storage is not writable. Please contact an administrator.', 500);
}

$storedName = bin2hex(random_bytes(16)) . '.' . $ext;
$destination = $uploadDir . '/' . $storedName;

if (!move_uploaded_file($temporaryPath, $destination)) {
    error_log('Failed to move uploaded evidence file into the evidence storage directory.');
    jsonError('Could not save the uploaded file. Please try again.', 500);
}

try {
    $stmt = $db->prepare(
        "INSERT INTO evidence_attachments (entity_type, entity_id, original_filename, stored_filename, file_size, mime_type, uploaded_by)
         VALUES (:type, :id, :orig, :stored, :size, :mime, :by)"
    );
    $stmt->execute([
        ':type'   => $entityType,
        ':id'     => $entityId,
        ':orig'   => $originalName,
        ':stored' => $storedName,
        ':size'   => $fileSize,
        ':mime'   => $detectedMime,
        ':by'     => $user['full_name'],
    ]);
} catch (Throwable $e) {
    @unlink($destination);
    error_log('Failed to record uploaded evidence metadata: ' . $e->getMessage());
    jsonError('Could not record the uploaded file. Please try again later.', 500);
}
$attachmentId = (int) $db->lastInsertId();

jsonSuccess([
    'id'                => $attachmentId,
    'original_filename' => $originalName,
    'file_size'         => (int) $fileSize,
], 201);
