<?php
/**
 * conflict_detection.php
 *
 * SCOPE NOTE: this checks the proposed session against sessions ALREADY
 * IN OUR OWN sessions table — venue double-booking, the same committee
 * scheduled twice at once, or the same presiding officer double-booked.
 * It does NOT check individual councilors' personal calendars, since we
 * have no visibility into that data (and tracking real-time attendance
 * during a session is explicitly out of scope — that belongs to the
 * Session and Legislative Meeting Management System's Attendance and
 * Quorum Monitoring Module). This keeps conflict detection self-contained
 * and demonstrable without depending on another team's system.
 *
 * Assumes a default 2-hour block per session for overlap purposes, since
 * the mock/real data doesn't track an explicit end time.
 */

define('SESSION_BLOCK_MINUTES', 120);

function timeToMinutes(string $hms): int {
    [$h, $m] = array_map('intval', explode(':', $hms));
    return $h * 60 + $m;
}

function blocksOverlap(int $startA, int $durA, int $startB, int $durB): bool {
    $endA = $startA + $durA;
    $endB = $startB + $durB;
    return $startA < $endB && $startB < $endA;
}

/**
 * Returns an array of human-readable conflict descriptions. Empty array
 * means no conflict found.
 *
 * @param PDO $db
 * @param string $date        'Y-m-d'
 * @param string $time24h     'H:i:s' or 'H:i'
 * @param string $venue
 * @param string|null $committee
 * @param string|null $presidingOfficer
 * @param string|null $excludeSessionId  session id to ignore (when editing an existing session)
 */
function findSessionConflictDetails(PDO $db, string $date, string $time24h, string $venue, ?string $committee, ?string $presidingOfficer, ?string $excludeSessionId = null): array {
    $proposedStart = timeToMinutes($time24h);

    $sql = "SELECT id, session_time, session_time_24h, session_type, venue, committee, presiding_officer
            FROM sessions
            WHERE session_date = :date AND status IN ('Scheduled', 'Rescheduled') AND is_deleted = 0";
    $params = [':date' => $date];

    if ($excludeSessionId) {
        $sql .= " AND id != :exclude";
        $params[':exclude'] = $excludeSessionId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sameDay = $stmt->fetchAll();

    $conflicts = [];

    foreach ($sameDay as $existing) {
        $existingStart = timeToMinutes($existing['session_time_24h']);
        if (!blocksOverlap($proposedStart, SESSION_BLOCK_MINUTES, $existingStart, SESSION_BLOCK_MINUTES)) {
            continue; // no time overlap at all, can't conflict on any dimension
        }

        $reasons = [];
        if (strcasecmp($existing['venue'], $venue) === 0) {
            $reasons[] = "Venue conflict: \"{$venue}\" is already booked";
        }
        if ($committee && $existing['committee'] && strcasecmp($existing['committee'], $committee) === 0) {
            $reasons[] = "Committee conflict: {$committee} is already scheduled";
        }
        if ($presidingOfficer && strcasecmp($existing['presiding_officer'], $presidingOfficer) === 0) {
            $reasons[] = "Presiding officer conflict: {$presidingOfficer} is already presiding";
        }
        if ($reasons) {
            $conflicts[] = [
                'session_id' => $existing['id'],
                'description' => implode('; ', $reasons)
                    . " for {$existing['session_type']} at {$existing['session_time']} (session {$existing['id']}).",
            ];
        }
    }

    return $conflicts;
}

/** Returns the conflict descriptions used by the existing edit flow. */
function checkSessionConflicts(PDO $db, string $date, string $time24h, string $venue, ?string $committee, ?string $presidingOfficer, ?string $excludeSessionId = null): array {
    return array_column(
        findSessionConflictDetails($db, $date, $time24h, $venue, $committee, $presidingOfficer, $excludeSessionId),
        'description'
    );
}

/**
 * Tries hourly slots between 8:00 and 17:00 on the SAME date and venue,
 * returns up to $limit times that have zero conflicts (per the same rules
 * as checkSessionConflicts). This is the "automatically suggest available
 * alternative times" behavior from the Definition of Terms.
 */
function suggestAlternativeTimes(PDO $db, string $date, string $venue, ?string $committee, ?string $presidingOfficer, int $limit = 3): array {
    $suggestions = [];
    for ($hour = 8; $hour <= 17 && count($suggestions) < $limit; $hour++) {
        $candidate = sprintf('%02d:00:00', $hour);
        $conflicts = checkSessionConflicts($db, $date, $candidate, $venue, $committee, $presidingOfficer);
        if (empty($conflicts)) {
            $suggestions[] = date('g:i A', strtotime($candidate));
        }
    }
    return $suggestions;
}
