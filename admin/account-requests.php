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
<title>Account Requests — Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="../js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
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
      <main class="p-8 max-w-3xl mx-auto space-y-6">
        <a href="index.php" class="text-xs text-slate-500 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Back to Admin</a>
        <h1 class="font-display text-xl text-ink-900">Account Requests</h1>
        <p class="text-sm text-slate-500">Staff-submitted requests to update their info or deactivate their own account. Approving applies the change immediately; you can't review your own request.</p>
        <div id="requests-list" class="space-y-3"></div>
      </main>
    </div>
  </div>
<script src="../js/api-client.js"></script>
<script src="../js/helpers.js"></script>
<script src="../js/nav.js"></script>
<script src="admin-account-requests.js"></script>
</body>
</html>
