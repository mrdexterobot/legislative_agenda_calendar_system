<?php
/**
 * Safe, bounded audit-log view for superadmins.
 * Sensitive audit fields (details, user_id, and IP address) are deliberately
 * not returned to the browser.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireApiRole('superadmin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

try {
    $range = parseAuditDateRange($_GET['from'] ?? null, $_GET['to'] ?? null);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

$db = getDb();
$stmt = $db->prepare(
    'SELECT id, created_at, username, action, entity_type, entity_id
     FROM audit_log
     WHERE created_at >= :from_date AND created_at < :to_date
     ORDER BY created_at DESC, id DESC
     LIMIT :limit'
);
$stmt->bindValue(':from_date', $range['from_sql']);
$stmt->bindValue(':to_date', $range['to_sql']);
$stmt->bindValue(':limit', AUDIT_LOG_MAX_VIEW_ROWS + 1, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll();
$hasMore = count($rows) > AUDIT_LOG_MAX_VIEW_ROWS;
if ($hasMore) {
    array_pop($rows);
}
$rows = sanitizeAuditRows($rows);

jsonSuccess([
    'logs' => $rows,
    'has_more' => $hasMore,
    'range' => [
        'from' => $range['from_date'],
        'to' => $range['to_date'],
    ],
    'max_view_rows' => AUDIT_LOG_MAX_VIEW_ROWS,
    'max_export_rows' => AUDIT_LOG_MAX_EXPORT_ROWS,
    'max_range_days' => AUDIT_LOG_MAX_RANGE_DAYS,
]);
