<?php

/** Return the next available human-readable ID for an agenda item. */
function nextAgendaItemId(PDO $db, string $itemType, string $year): string
{
    $prefix = match ($itemType) {
        'Ordinance' => 'ORD',
        'Resolution' => 'RES',
        default => throw new InvalidArgumentException('Invalid agenda item type.'),
    };

    $stmt = $db->prepare('SELECT id FROM agenda_items WHERE id LIKE :pattern');
    $stmt->execute([':pattern' => $prefix . '-' . $year . '-%']);

    $maxNumber = 0;
    $idPattern = '/^' . $prefix . '-' . preg_quote($year, '/') . '-(\d+)$/D';
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingId) {
        if (preg_match($idPattern, (string) $existingId, $matches)) {
            $maxNumber = max($maxNumber, (int) $matches[1]);
        }
    }

    return sprintf('%s-%s-%03d', $prefix, $year, $maxNumber + 1);
}
