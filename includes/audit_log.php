<?php
/**
 * Shared audit-log filtering and export limits.
 *
 * Audit exports intentionally expose only the accountability fields needed
 * to answer who did what and when. Details, user IDs, and IP addresses stay
 * out of the export because they may contain personal or operationally
 * sensitive data.
 */

const AUDIT_LOG_MAX_RANGE_DAYS = 31;
const AUDIT_LOG_MAX_EXPORT_ROWS = 1000;
const AUDIT_LOG_MAX_VIEW_ROWS = 100;
const AUDIT_EXPORT_COOLDOWN_SECONDS = 60;

// Some existing audit events use entity_id for usernames, stakeholder names,
// email addresses, or integration labels. Only these internal record types
// are safe to expose as references in the superadmin view/export.
const AUDIT_SAFE_ENTITY_TYPES = [
    'agenda_item',
    'account_request',
    'audit_log',
    'deadline',
    'meeting',
    'session',
];

/**
 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, from_sql: string, to_sql: string, from_date: string, to_date: string}
 */
function parseAuditDateRange(mixed $fromInput, mixed $toInput): array
{
    $utc = new DateTimeZone('UTC');
    $today = new DateTimeImmutable('today', $utc);

    if ($fromInput === null && $toInput === null) {
        $fromInput = $today->modify('-6 days')->format('Y-m-d');
        $toInput = $today->format('Y-m-d');
    }

    if (!is_string($fromInput) || !is_string($toInput)) {
        throw new InvalidArgumentException('Both start and end dates are required.');
    }

    $from = parseAuditDate($fromInput, 'start');
    $to = parseAuditDate($toInput, 'end');

    if ($from > $to) {
        throw new InvalidArgumentException('The start date cannot be after the end date.');
    }

    $inclusiveDays = ((int) $from->diff($to)->format('%a')) + 1;
    if ($inclusiveDays > AUDIT_LOG_MAX_RANGE_DAYS) {
        throw new InvalidArgumentException(
            'The date range cannot exceed ' . AUDIT_LOG_MAX_RANGE_DAYS . ' days.'
        );
    }

    $toExclusive = $to->modify('+1 day');

    return [
        'from' => $from,
        'to' => $toExclusive,
        'from_sql' => $from->format('Y-m-d 00:00:00'),
        'to_sql' => $toExclusive->format('Y-m-d 00:00:00'),
        'from_date' => $from->format('Y-m-d'),
        'to_date' => $to->format('Y-m-d'),
    ];
}

function parseAuditDate(string $value, string $label): DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        throw new InvalidArgumentException("The {$label} date must use YYYY-MM-DD format.");
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    if (!$date || $hasErrors || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException("The {$label} date is invalid.");
    }

    return $date;
}

/** Protect spreadsheet users from formula injection in exported text cells. */
function auditCsvCell(mixed $value): string
{
    $text = (string) ($value ?? '');
    if ($text !== '' && preg_match('/^[=+\-@]/', $text)) {
        return "'" . $text;
    }
    return $text;
}

/** Return only the fields and entity references approved for superadmin output. */
function sanitizeAuditRows(array $rows): array
{
    return array_map(static function (array $row): array {
        $entityType = (string) ($row['entity_type'] ?? '');
        $safeEntityId = in_array($entityType, AUDIT_SAFE_ENTITY_TYPES, true)
            ? ($row['entity_id'] ?? null)
            : null;

        return [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'username' => $row['username'] !== null ? (string) $row['username'] : null,
            'action' => (string) $row['action'],
            'entity_type' => $entityType,
            'entity_id' => $safeEntityId !== null ? (string) $safeEntityId : null,
        ];
    }, $rows);
}
