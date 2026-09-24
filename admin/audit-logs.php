<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requirePageRole('superadmin');
$csrfToken = $_SESSION['csrf_token'] ?? generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Audit Logs — Superadmin</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="../js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="audit">
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
    <main class="p-8 max-w-6xl mx-auto space-y-6">
      <div class="flex items-center justify-between gap-4 flex-wrap">
        <a href="index.php" class="text-xs text-slate-500 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Back to Admin</a>
        <span class="pill pill-maroon"><i class="fa-solid fa-user-shield mr-1"></i>Superadmin only</span>
      </div>

      <div>
        <h1 class="font-display text-xl text-ink-900">Audit Logs</h1>
        <p class="text-sm text-slate-500 mt-1">Review who performed recorded actions and when. This view and its CSV export omit details, user IDs, and IP addresses.</p>
      </div>

      <section class="dossier-card accent-info p-5">
        <form id="audit-filter-form" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-4 items-end">
          <div>
            <label for="audit-from" class="block text-xs font-semibold text-slate-600 mb-1">Start date (UTC)</label>
            <input id="audit-from" name="from" type="date" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
          </div>
          <div>
            <label for="audit-to" class="block text-xs font-semibold text-slate-600 mb-1">End date (UTC)</label>
            <input id="audit-to" name="to" type="date" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
          </div>
          <div class="flex gap-2">
            <button type="submit" class="btn-outline text-xs !py-2"><i class="fa-solid fa-filter mr-1"></i>Apply filter</button>
            <button type="button" id="export-audit-btn" class="btn-primary text-xs !py-2"><i class="fa-solid fa-file-csv mr-1"></i>Export CSV</button>
          </div>
        </form>
        <p class="text-[11px] text-slate-500 mt-3">Maximum range: 31 days. A CSV export must contain no more than 1,000 rows and is limited to once per minute per superadmin.</p>
        <p id="audit-status" class="text-xs mt-3" role="status"></p>
      </section>

      <section class="dossier-card overflow-hidden">
        <div class="px-4 py-3 border-b border-[--line-200] flex items-center justify-between gap-3">
          <h2 class="font-display text-base text-ink-900">Recorded activity</h2>
          <span id="audit-count" class="text-[11px] text-slate-500"></span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-paper-100 text-slate-600">
              <tr>
                <th class="px-4 py-3 font-semibold">Timestamp (UTC)</th>
                <th class="px-4 py-3 font-semibold">Actor</th>
                <th class="px-4 py-3 font-semibold">Action</th>
                <th class="px-4 py-3 font-semibold">Entity</th>
                <th class="px-4 py-3 font-semibold">Entity ID</th>
              </tr>
            </thead>
            <tbody id="audit-rows" class="divide-y divide-[--line-200]"></tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
</div>

<script src="../js/api-client.js"></script>
<script src="../js/nav.js"></script>
<script src="audit-logs.js"></script>
</body>
</html>
