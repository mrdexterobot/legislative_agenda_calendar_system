<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requirePageRole('admin');
$csrfToken = $_SESSION['csrf_token'] ?? generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Admin — Legislative Agenda &amp; Calendar Management System</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="../js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="admin">

<script>
  window.CURRENT_USER = <?php echo json_encode($currentUser); ?>;
  window.CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
  window.APP_BASE_PATH = <?php echo json_encode(appBasePath()); ?>;
  window.NAV_PREFIX = "../";
  window.API_PREFIX = "../";
</script>

  <div class="flex">
    <div id="app-sidebar"></div>
    <div class="flex-1 min-w-0">
      <div id="app-topbar"></div>
      <main class="p-8 max-w-4xl mx-auto space-y-6">
        <div class="dossier-card accent-maroon p-4 text-xs text-slate-600">
          <i class="fa-solid fa-shield-halved text-maroon-700 mr-1.5"></i>
          Admin-only area. Actions here (user accounts, record edits/archiving, integration tokens) are logged to
          the audit trail same as everywhere else in the system.
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <a href="manage-agenda.php" class="dossier-card accent-brass p-4 hover:shadow-sm transition-shadow">
            <i class="fa-solid fa-folder-open text-ink-700 mb-2"></i>
            <h3 class="text-sm font-semibold text-ink-900">Manage Agenda Items</h3>
            <p class="text-xs text-slate-500 mt-1">Correct records, archive withdrawn items.</p>
          </a>
          <a href="manage-users.php" class="dossier-card accent-info p-4 hover:shadow-sm transition-shadow">
            <i class="fa-solid fa-users-gear text-ink-700 mb-2"></i>
            <h3 class="text-sm font-semibold text-ink-900">Manage User Accounts</h3>
            <p class="text-xs text-slate-500 mt-1">Add staff, reset passwords, deactivate accounts.</p>
          </a>
          <a href="integration.php" class="dossier-card accent-forest p-4 hover:shadow-sm transition-shadow">
            <i class="fa-solid fa-plug text-ink-700 mb-2"></i>
            <h3 class="text-sm font-semibold text-ink-900">Integration Access</h3>
            <p class="text-xs text-slate-500 mt-1">Issue/revoke tokens for peer subsystems.</p>
          </a>
          <a href="account-requests.php" class="dossier-card accent-maroon p-4 hover:shadow-sm transition-shadow">
            <i class="fa-solid fa-user-clock text-ink-700 mb-2"></i>
            <h3 class="text-sm font-semibold text-ink-900">Account Requests</h3>
            <p class="text-xs text-slate-500 mt-1">Review staff info-update and deactivation requests.</p>
          </a>
          <a href="email-test.php" class="dossier-card accent-info p-4 hover:shadow-sm transition-shadow">
            <i class="fa-solid fa-envelope-circle-check text-ink-700 mb-2"></i>
            <h3 class="text-sm font-semibold text-ink-900">Email Delivery Test</h3>
            <p class="text-xs text-slate-500 mt-1">Check SMTP and see the real error when a send fails.</p>
          </a>
        </div>
      </main>
    </div>
  </div>

<script src="../js/api-client.js"></script>
<script src="../js/nav.js"></script>
</body>
</html>
