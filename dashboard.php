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
<title>Dashboard — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="dashboard">

<script>
  window.CURRENT_USER = <?php echo json_encode($currentUser); ?>;
  window.CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
  window.APP_BASE_PATH = <?php echo json_encode(appBasePath()); ?>;
</script>

  <div class="flex">
    <div id="app-sidebar"></div>

    <div class="flex-1 min-w-0">
      <div id="app-topbar"></div>

      <main class="p-8 max-w-6xl mx-auto space-y-8">

        <section id="stats" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"></section>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

          <section class="lg:col-span-2">
            <div class="flex items-center justify-between mb-3">
              <h2 class="font-display text-lg text-ink-900">What's coming up</h2>
              <a href="calendar-scheduling.php" class="text-xs font-semibold text-ink-700 hover:underline">Open full calendar <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
            </div>
            <p class="text-xs text-slate-500 mb-4">Every scheduled session, committee hearing, and public hearing, with the items attached to it — so nobody is surprised by what's on the floor.</p>
            <div id="upcoming-agenda" class="space-y-3"></div>
          </section>

          <section class="min-w-0">
            <h2 class="font-display text-lg text-ink-900 mb-3">Recent activity</h2>
            <div class="dossier-card p-4 divide-y divide-[--line-200] overflow-hidden" id="activity-feed"></div>
          </section>
        </div>

        <section>
          <h2 class="font-display text-lg text-ink-900 mb-3">Modules</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <a href="priority-setting.php" class="dossier-card accent-brass p-4 hover:shadow-sm transition-shadow">
              <i class="fa-solid fa-scale-balanced text-ink-700 mb-2"></i>
              <h3 class="text-sm font-semibold text-ink-900">Priority Setting</h3>
              <p class="text-xs text-slate-500 mt-1">Classify and confirm agenda-item priority.</p>
            </a>
            <a href="calendar-scheduling.php" class="dossier-card accent-info p-4 hover:shadow-sm transition-shadow">
              <i class="fa-solid fa-calendar-days text-ink-700 mb-2"></i>
              <h3 class="text-sm font-semibold text-ink-900">Calendar Scheduling</h3>
              <p class="text-xs text-slate-500 mt-1">Sessions, committee &amp; public hearings.</p>
            </a>
            <a href="meeting-coordination.php" class="dossier-card p-4 hover:shadow-sm transition-shadow">
              <i class="fa-solid fa-people-group text-ink-700 mb-2"></i>
              <h3 class="text-sm font-semibold text-ink-900">Meeting Coordination</h3>
              <p class="text-xs text-slate-500 mt-1">Logistics, notices, minutes.</p>
            </a>
            <a href="deadline-tracking.php" class="dossier-card accent-maroon p-4 hover:shadow-sm transition-shadow">
              <i class="fa-solid fa-hourglass-half text-ink-700 mb-2"></i>
              <h3 class="text-sm font-semibold text-ink-900">Deadline Tracking</h3>
              <p class="text-xs text-slate-500 mt-1">Statutory &amp; internal deadlines.</p>
            </a>
            <?php if ($currentUser['role'] === 'superadmin'): ?>
            <a href="admin/audit-logs.php" class="dossier-card accent-maroon p-4 hover:shadow-sm transition-shadow">
              <i class="fa-solid fa-file-shield text-ink-700 mb-2"></i>
              <h3 class="text-sm font-semibold text-ink-900">Audit Logs</h3>
              <p class="text-xs text-slate-500 mt-1">Review safe activity fields and export bounded CSV records.</p>
            </a>
            <?php endif; ?>
          </div>
        </section>

      </main>
    </div>
  </div>

<script src="js/api-client.js"></script>
<script src="js/helpers.js"></script>
<script src="js/nav.js?v=audit-logs"></script>
<script src="js/dashboard.js?v=2"></script>
</body>
</html>
