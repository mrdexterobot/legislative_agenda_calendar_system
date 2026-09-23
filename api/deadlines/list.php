<?php
/**
 * LOOPHOLE FIX (original): the Mayor's-action-window deadline is NOT
 * stored as its own row in `deadlines` — it's computed here directly from
 * agenda_items.transmitted_to_mayor_date + mayor_action_window_days, for
 * every item that's been transmitted but has no mayor_action yet. This
 * avoids two copies of the same deadline silently drifting apart.
 *
 * VISIBILITY: staff (non-admin) should not see every colleague's assigned
 * workload — only deadlines that are (a) assigned to them, (b) unassigned
 * (nobody "owns" it yet, so anyone with access can still pick it up and
 * complete it — see api/deadlines/update.php's assignment check), or
 * (c) the computed Mayor's-action-window items, which are statutory
 * compliance tracking rather than a specific person's task and are
 * relevant to the whole office regardless of who's watching them.
 * Admins see everything, since they're the ones who may need to
 * reassign or delete a deadline nobody is acting on.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$db = getDb();
$isAdmin = isAdminOrAbove($user);
// RISK FIX: soft-deleted deadlines never show in the normal list for
// anyone — only an admin explicitly asking for them (via the "Show
// deleted" toggle in js/deadline-tracking.js) sees is_deleted=1 rows.
$includeDeleted = $isAdmin && ($_GET['include_deleted'] ?? '') === '1';
$deletedClause = $includeDeleted ? '' : 'AND is_deleted = 0';

// ---- Explicit internal deadlines ----
if ($isAdmin) {
    $internal = $db->query("SELECT * FROM deadlines WHERE 1=1 $deletedClause ORDER BY due_date ASC")->fetchAll();
} else {
    $stmt = $db->prepare(
        "SELECT * FROM deadlines
         WHERE (assigned_to_user_id IS NULL OR assigned_to_user_id = :uid) AND is_deleted = 0
         ORDER BY due_date ASC"
    );
    $stmt->execute([':uid' => $user['id']]);
    $internal = $stmt->fetchAll();
}
foreach ($internal as &$d) {
    $d['source'] = 'internal';
}
unset($d);

// ---- Computed Sec. 54 mayor's-action-window deadlines ----
// Visible to everyone (see note above) — not filtered by assignment.
$transmitted = $db->query(
    "SELECT id, title, transmitted_to_mayor_date, mayor_action_window_days, mayor_action, mayor_action_date, mayor_action_notes
     FROM agenda_items
     WHERE transmitted_to_mayor_date IS NOT NULL AND is_archived = 0"
)->fetchAll();

$computed = [];
foreach ($transmitted as $item) {
    $dueDate = date('Y-m-d', strtotime($item['transmitted_to_mayor_date'] . " +{$item['mayor_action_window_days']} days"));

    if ($item['mayor_action']) {
        $status = 'Completed — ' . $item['mayor_action'];
        $reason = "{$item['id']} was {$item['mayor_action']}"
            . ($item['mayor_action_date'] ? ' on ' . date('M j, Y', strtotime($item['mayor_action_date'])) : '')
            . '. Recorded via Admin \u2192 Manage Agenda Items.';
    } else {
        $daysLeft = (int) floor((strtotime($dueDate) - strtotime('today')) / 86400);
        $status = 'Scheduled';
        if ($daysLeft < 0) $status = 'Overdue — status update needed';
        elseif ($daysLeft <= 3) $status = 'Due soon';
        $reason = "Computed live from {$item['id']}'s transmittal date (" . date('M j, Y', strtotime($item['transmitted_to_mayor_date'])) . ") + {$item['mayor_action_window_days']}-day window — not stored separately, so it can't drift out of sync.";
    }

    $computed[] = [
        'id'                 => 'MAYOR-WINDOW-' . $item['id'],
        'label'              => "Mayor's action window — {$item['id']}",
        'related_item_id'    => $item['id'],
        'deadline_type'      => 'Mayor Action Window (Sec. 54)',
        'due_date'           => $dueDate,
        'status'             => $status,
        'is_statutory'       => 1,
        'source'             => 'computed',
        'auto_generated'     => 1,
        'generation_reason'  => $reason,
        'completion_notes'   => $item['mayor_action_notes'],
        'completed_at'       => $item['mayor_action_date'],
    ];
}

$all = array_merge($internal, $computed);
usort($all, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));

jsonSuccess($all);
