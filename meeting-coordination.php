<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

$currentUser = requirePageAuth();
$csrfToken = $_SESSION['csrf_token'] ?? generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Meeting Coordination — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="meeting">

<script>
  window.CURRENT_USER = <?php echo json_encode($currentUser); ?>;
  window.CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
  window.APP_BASE_PATH = <?php echo json_encode(appBasePath()); ?>;
</script>

  <div class="flex">
    <div id="app-sidebar"></div>

    <div class="flex-1 min-w-0">
      <div id="app-topbar"></div>

      <main class="p-8 max-w-5xl mx-auto space-y-6">
        <p class="text-sm text-slate-500 max-w-2xl">
          The logistics behind each session: venue confirmation, document distribution, minutes, and notifying
          stakeholders. Notifications can only be sent once the Session and Legislative Meeting Management System
          confirms the schedule is ready (attendees set, agenda ready) — see the badge on each card below. Only an
          admin can actually send notifications once the checklist is complete.
        </p>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div id="meeting-filter-tabs" class="flex flex-wrap gap-2"></div>
          <label class="flex items-center gap-2 text-xs text-slate-600">
            Sort by
            <select id="meeting-sort-select" class="border border-[--line-200] rounded-lg px-2 py-1.5 text-xs bg-white">
              <option value="session_date">Session date</option>
              <option value="readiness">Readiness</option>
              <option value="status">Send status</option>
            </select>
          </label>
        </div>

        <div id="meeting-list" class="space-y-4"></div>
      </main>
    </div>
  </div>

<script src="js/api-client.js"></script>
<script src="js/helpers.js"></script>
<script src="js/nav.js"></script>
<script src="js/meeting-coordination.js"></script>
</body>
</html>
