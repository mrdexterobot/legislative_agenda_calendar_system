<?php
/**
 * Bounded CSV export for superadmins.
 *
 * The per-user cooldown is protected by a database row lock so two concurrent
 * requests cannot both pass the one-export-per-minute rule.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/audit_log.php';

$user = requireApiRole('superadmin');
requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$body = getJsonBody();
if (!array_key_exists('from', $body) || !array_key_exists('to', $body)) {
    jsonError('Both start and end dates are required.', 422);
}
try {
    $range = parseAuditDateRange($body['from'] ?? null, $body['to'] ?? null);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

$db = getDb();
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cutoff = $now->modify('-' . AUDIT_EXPORT_COOLDOWN_SECONDS . ' seconds');

try {
    $db->beginTransaction();

    // Seed a lock row without allowing a duplicate-key race to bypass it.
    $seed = $db->prepare(
        'INSERT IGNORE INTO audit_export_rate_limits (user_id, last_export_at)
         VALUES (:user_id, :initial_time)'
    );
    $seed->execute([
        ':user_id' => $user['id'],
        ':initial_time' => '1970-01-01 00:00:00',
    ]);

    $lock = $db->prepare(
        'SELECT last_export_at
         FROM audit_export_rate_limits
         WHERE user_id = :user_id
         FOR UPDATE'
    );
    $lock->execute([':user_id' => $user['id']]);
    $lastExportAt = $lock->fetchColumn();

    if ($lastExportAt !== false && $lastExportAt > $cutoff->format('Y-m-d H:i:s')) {
        $lastExportDate = new DateTimeImmutable($lastExportAt, new DateTimeZone('UTC'));
        $retryAt = $lastExportDate->modify('+' . AUDIT_EXPORT_COOLDOWN_SECONDS . ' seconds');
        $retryAfter = max(1, $retryAt->getTimestamp() - $now->getTimestamp());
        $db->rollBack();
        jsonError(
            'Export rate limit reached. Please wait before exporting again.',
            429,
            ['retry_after_seconds' => $retryAfter]
        );
    }

    $stmt = $db->prepare(
        'SELECT id, created_at, username, action, entity_type, entity_id
         FROM audit_log
         WHERE created_at >= :from_date AND created_at < :to_date
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':from_date', $range['from_sql']);
    $stmt->bindValue(':to_date', $range['to_sql']);
    $stmt->bindValue(':limit', AUDIT_LOG_MAX_EXPORT_ROWS + 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (count($rows) > AUDIT_LOG_MAX_EXPORT_ROWS) {
        $db->rollBack();
        jsonError(
            'This date range contains more than ' . AUDIT_LOG_MAX_EXPORT_ROWS
            . ' logs. Narrow the date range before exporting.',
            422,
            ['max_export_rows' => AUDIT_LOG_MAX_EXPORT_ROWS]
        );
    }
    $rows = sanitizeAuditRows($rows);

    $csv = fopen('php://temp/maxmemory:1048576', 'w+');
    if ($csv === false) {
        throw new RuntimeException('Could not create the export buffer.');
    }

    fputcsv($csv, [
        'audit_id',
        'occurred_at_utc',
        'actor_username',
        'action',
        'entity_type',
        'entity_id',
    ], ',', '"', '');

    foreach ($rows as $row) {
        fputcsv($csv, [
            auditCsvCell($row['id']),
            auditCsvCell($row['created_at']),
            auditCsvCell($row['username']),
            auditCsvCell($row['action']),
            auditCsvCell($row['entity_type']),
            auditCsvCell($row['entity_id']),
        ], ',', '"', '');
    }

    $update = $db->prepare(
        'UPDATE audit_export_rate_limits
         SET last_export_at = :last_export_at
         WHERE user_id = :user_id'
    );
    $update->execute([
        ':last_export_at' => $now->format('Y-m-d H:i:s'),
        ':user_id' => $user['id'],
    ]);

    logAudit(
        'audit_export',
        'audit_log',
        null,
        sprintf(
            'CSV export completed for %s through %s (%d rows)',
            $range['from_date'],
            $range['to_date'],
            count($rows)
        )
    );

    $db->commit();
    rewind($csv);
    $filename = sprintf('audit-log-%s-to-%s.csv', $range['from_date'], $range['to_date']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    fpassthru($csv);
    fclose($csv);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Audit CSV export failed: ' . $e->getMessage());
    jsonError('The audit export could not be completed. Please try again later.', 500);
}
