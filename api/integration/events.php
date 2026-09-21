<?php
/**
 * events.php — lists the simulated integration exchange log. Any
 * authenticated user can view this (not admin-only) since the point is
 * for whoever is demonstrating Calendar Scheduling / Meeting Coordination
 * to be able to show it inline, not have to switch to an admin account.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$sessionId = trim($_GET['session_id'] ?? '');

$db = getDb();
if ($sessionId !== '') {
    $stmt = $db->prepare(
        "SELECT * FROM integration_events WHERE related_session_id = :sid ORDER BY id ASC"
    );
    $stmt->execute([':sid' => $sessionId]);
} else {
    $stmt = $db->query(
        "SELECT * FROM integration_events ORDER BY id DESC LIMIT 20"
    );
}

jsonSuccess($stmt->fetchAll());
