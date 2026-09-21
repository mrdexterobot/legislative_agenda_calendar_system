<?php
/**
 * export-agenda-items.php
 *
 * This is the "exposed but access-controlled" side of integration
 * readiness — a peer subsystem (e.g. a Records Management or Ordinance
 * Lifecycle group) could pull confirmed legislative priority data from us
 * without needing a login session, authenticating instead with a bearer
 * token issued via api/integration/create-token.php.
 *
 * WHAT THIS DOES NOT DO: encrypt data in transit. That's a hosting-level
 * concern (HTTPS/TLS on the server), not something PHP code can guarantee
 * on its own — see README.md "Deploying to real hosting" for how to turn
 * HTTPS on. What this DOES do: require a valid, hashed, revocable token
 * before returning anything, and only returns already-CONFIRMED data
 * (never a bare AI suggestion nobody has reviewed yet).
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/integration_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$token = requireIntegrationToken();

$items = getDb()->query(
    "SELECT id, title, item_type, committee, category, date_filed,
            confirmed_priority, priority_confirmed_date,
            transmitted_to_mayor_date, mayor_action, mayor_action_date
     FROM agenda_items
     WHERE is_archived = 0
     ORDER BY date_filed DESC"
)->fetchAll();

jsonSuccess([
    'items'         => $items,
    'exported_at'   => date('c'),
    'requested_by'  => $token['label'],
]);
