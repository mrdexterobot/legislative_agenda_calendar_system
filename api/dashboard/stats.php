<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$db = getDb();
$isAdmin = isAdminOrAbove($user);

$pendingPriority = (int) $db->query(
    "SELECT COUNT(*) FROM agenda_items WHERE confirmed_priority IS NULL AND is_archived = 0"
)->fetchColumn();

$upcoming7 = (int) $db->query(
    "SELECT COUNT(*) FROM sessions
     WHERE status = 'Scheduled' AND is_deleted = 0
       AND session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
)->fetchColumn();

// VISIBILITY FIX: this used to count every internal deadline due within 7
// days regardless of who's viewing, so a staff member could see a bigger
// number here than they'd ever see in Deadline Tracking's own (assignment-
// filtered) list — same rule as api/deadlines/list.php: admins see all,
// staff see only what's assigned to them or unassigned.
if ($isAdmin) {
    $deadlinesInternal = (int) $db->query(
        "SELECT COUNT(*) FROM deadlines WHERE due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status != 'Completed'"
    )->fetchColumn();
} else {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM deadlines
         WHERE due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status != 'Completed'
           AND (assigned_to_user_id IS NULL OR assigned_to_user_id = :uid)"
    );
    $stmt->execute([':uid' => $user['id']]);
    $deadlinesInternal = (int) $stmt->fetchColumn();
}
// Mayor's-action-window deadlines stay visible to everyone (see
// api/deadlines/list.php), so this count is unchanged by role.
$deadlinesComputed = (int) $db->query(
    "SELECT COUNT(*) FROM agenda_items
     WHERE transmitted_to_mayor_date IS NOT NULL AND mayor_action IS NULL AND is_archived = 0
       AND DATE_ADD(transmitted_to_mayor_date, INTERVAL mayor_action_window_days DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
)->fetchColumn();

$awaitingMayor = (int) $db->query(
    "SELECT COUNT(*) FROM agenda_items WHERE transmitted_to_mayor_date IS NOT NULL AND mayor_action IS NULL AND is_archived = 0"
)->fetchColumn();

$upcomingSessions = $db->query(
    "SELECT * FROM sessions
     WHERE status = 'Scheduled' AND is_deleted = 0
     ORDER BY session_date ASC, session_time_24h ASC LIMIT 5"
)->fetchAll();

$priorityRank = ['High' => 0, 'Medium' => 1, 'Low' => 2, null => 3];
foreach ($upcomingSessions as &$s) {
    $stmt = $db->prepare(
        "SELECT ai.id, ai.title, ai.confirmed_priority
         FROM session_agenda_items sai
         JOIN agenda_items ai ON ai.id = sai.agenda_item_id
         WHERE sai.session_id = :id"
    );
    $stmt->execute([':id' => $s['id']]);
    $items = $stmt->fetchAll();
    usort($items, fn($a, $b) => $priorityRank[$a['confirmed_priority']] <=> $priorityRank[$b['confirmed_priority']]);
    $s['agenda_items'] = $items;
}
unset($s);

// Keep superadmin actions out of the dashboard feed for all other roles.
// Enforce this in the API query so those rows are never sent to the browser.
$activityVisibility = ($user['role'] ?? '') === 'superadmin'
    ? ''
    : "AND NOT EXISTS (
           SELECT 1 FROM users activity_actor
           WHERE activity_actor.id = audit_log.user_id
             AND activity_actor.role = 'superadmin'
       )";
$recentActivity = $db->query(
    "SELECT username, action, entity_type, entity_id, details, created_at FROM audit_log
     WHERE action NOT LIKE 'login%' AND action != 'logout'
       $activityVisibility
     ORDER BY created_at DESC LIMIT 8"
)->fetchAll();

jsonSuccess([
    'stats' => [
        'pending_priority'  => $pendingPriority,
        'upcoming_7_days'   => $upcoming7,
        'deadlines_7_days'  => $deadlinesInternal + $deadlinesComputed,
        'awaiting_mayor'    => $awaitingMayor,
    ],
    'upcoming_sessions' => $upcomingSessions,
    'recent_activity'   => $recentActivity,
]);
