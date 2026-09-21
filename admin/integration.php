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
<title>Integration Access — Admin</title>
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
        <h1 class="font-display text-xl text-ink-900">Integration Access</h1>
        <p class="text-sm text-slate-500">
          Tokens here let OTHER subsystems (e.g. a peer group's Records Management system) pull confirmed
          agenda-item data from <span class="font-mono">api/integration/export-agenda-items.php</span> without a
          login session — authenticated with <span class="font-mono">Authorization: Bearer &lt;token&gt;</span> instead.
          Only the token's hash is stored; the raw value is shown once, at creation.
        </p>

        <div class="dossier-card accent-info p-5">
          <h3 class="font-display text-base text-ink-900 mb-3">Issue a new token</h3>
          <form id="new-token-form" class="flex gap-2">
            <input name="label" required placeholder="e.g. Records Management System — peer group"
              class="flex-1 border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            <button type="submit" class="btn-primary text-xs !py-2 shrink-0">Generate</button>
          </form>
          <div id="new-token-result" class="hidden mt-3 dossier-card accent-forest p-3 text-xs">
            <p class="font-semibold text-forest-700 mb-1"><i class="fa-solid fa-circle-check mr-1"></i>Copy this now — it will not be shown again:</p>
            <code id="new-token-value" class="block break-all bg-white border border-[--line-200] rounded p-2 font-mono"></code>
          </div>
        </div>

        <div id="tokens-list" class="space-y-2"></div>
      </main>
    </div>
  </div>
<script src="../js/api-client.js"></script>
<script src="../js/nav.js"></script>
<script src="admin-integration.js"></script>
</body>
</html>
