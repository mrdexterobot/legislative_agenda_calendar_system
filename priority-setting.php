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
<title>Priority Setting — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="priority">

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
        <div class="flex items-center justify-between flex-wrap gap-3">
          <p class="text-sm text-slate-500 max-w-2xl">
            Every filed ordinance or resolution can get an AI-suggested priority based on urgency, statutory deadlines,
            public interest, and affected-constituent count. Staff confirm or override before it takes effect —
            every confirmation below is logged with who acted and when.
          </p>
          <button id="new-item-btn" class="btn-primary text-xs !py-2 shrink-0"><i class="fa-solid fa-plus mr-1"></i>Encode new item</button>
        </div>

        <div id="new-item-panel" class="hidden dossier-card accent-info p-5">
          <h3 class="font-display text-base text-ink-900 mb-3">Encode a new ordinance/resolution</h3>
          <form id="new-item-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">ID (e.g. ORD-2026-020)</label>
              <input name="id" required placeholder="ORD-2026-020" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Type</label>
              <select name="item_type" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option>Ordinance</option><option>Resolution</option>
              </select>
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-semibold text-slate-600 mb-1">Title</label>
              <input name="title" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Committee</label>
              <input name="committee" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Submitted by</label>
              <input name="submitted_by" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Category</label>
              <select name="category" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option>Regular</option><option>Budget</option><option>Emergency</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Date filed</label>
              <input name="date_filed" type="date" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <p id="new-item-error" class="hidden sm:col-span-2 text-xs text-maroon-700"></p>
            <div class="sm:col-span-2 flex gap-2 justify-end">
              <button type="button" onclick="document.getElementById('new-item-panel').classList.add('hidden')" class="btn-outline text-xs !py-2">Cancel</button>
              <button type="submit" class="btn-primary text-xs !py-2">Save item</button>
            </div>
          </form>
        </div>

        <div id="filter-tabs" class="flex flex-wrap gap-2"></div>
        <div id="priority-list" class="space-y-4"></div>
      </main>
    </div>
  </div>

  <div id="priority-modal-root"></div>

<script src="js/api-client.js"></script>
<script src="js/helpers.js"></script>
<script src="js/nav.js"></script>
<script src="js/priority-setting.js"></script>
<script>
  document.getElementById("new-item-btn").addEventListener("click", () => {
    document.getElementById("new-item-panel").classList.toggle("hidden");
  });
</script>
</body>
</html>
