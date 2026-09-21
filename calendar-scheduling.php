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
<title>Calendar Scheduling — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="calendar">

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
          <p class="text-sm text-slate-500 max-w-xl">
            Every regular session, special session, committee hearing, and public hearing your office has on the books —
            with venue, presiding officer, and the agenda items attached to each one. New sessions are checked for
            venue/committee/presiding-officer conflicts before they're saved.
          </p>
          <div class="flex items-center gap-2">
            <label class="flex items-center gap-2 text-xs text-slate-600 bg-white border border-[--line-200] rounded-lg px-3 py-2">
              <input type="checkbox" id="show-past" class="rounded border-[--line-200]" /> Show past/completed
            </label>
            <?php if ($currentUser['role'] === 'admin'): ?>
            <label class="flex items-center gap-2 text-xs text-slate-600 bg-white border border-[--line-200] rounded-lg px-3 py-2">
              <input type="checkbox" id="show-deleted-sessions" class="rounded border-[--line-200]" /> Show deleted
            </label>
            <?php endif; ?>
            <div id="view-toggle" class="flex gap-2"></div>
            <button id="new-session-btn" class="btn-primary text-xs !py-2"><i class="fa-solid fa-plus mr-1"></i>Schedule session</button>
          </div>
        </div>

        <div id="new-session-panel" class="hidden dossier-card accent-info p-5">
          <h3 class="font-display text-base text-ink-900 mb-3">Schedule a new session</h3>
          <form id="new-session-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Date</label>
              <input name="date" type="date" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Time</label>
              <input name="time" type="text" placeholder="9:00 AM" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Session type</label>
              <select name="type" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option>Regular Session</option><option>Special Session</option><option>Committee Hearing</option><option>Public Hearing</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Sequence # <span class="font-normal text-slate-400">(Regular Sessions only, e.g. "58" for the 58th)</span></label>
              <input name="sequence_number" type="number" min="1" placeholder="58" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Venue</label>
              <input name="venue" type="text" placeholder="Session Hall" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Presiding officer</label>
              <input name="presiding_officer" type="text" placeholder="Hon. Vice Mayor" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Committee (if Committee Hearing)</label>
              <input name="committee" type="text" placeholder="Committee on..." class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-semibold text-slate-600 mb-1">Agenda items to attach</label>
              <div id="agenda-item-checks" class="max-h-32 overflow-y-auto border border-[--line-200] rounded-lg p-2 bg-white"></div>
            </div>

            <div id="conflict-warning" class="hidden sm:col-span-2 dossier-card accent-maroon p-3 text-xs text-maroon-700"></div>

            <div class="sm:col-span-2 flex gap-2 justify-end">
              <button type="button" onclick="document.getElementById('new-session-panel').classList.add('hidden')" class="btn-outline text-xs !py-2">Cancel</button>
              <button type="submit" class="btn-primary text-xs !py-2">Save session</button>
            </div>
          </form>
        </div>

        <div id="integration-live" class="hidden dossier-card accent-forest p-4"></div>

        <div id="calendar-body"></div>

        <section class="mt-6">
          <button id="toggle-activity-log" class="text-xs font-semibold text-slate-500 hover:text-ink-800">
            <i class="fa-solid fa-chevron-right mr-1" id="activity-chevron"></i>Recent Integration Activity <span class="font-normal text-slate-400">(simulated exchange log with the Session Mgmt System)</span>
          </button>
          <div id="activity-log" class="hidden mt-3 space-y-2"></div>
        </section>
      </main>
    </div>
  </div>

  <div id="complete-modal-root"></div>
  <div id="detail-modal-root"></div>

<script src="js/api-client.js"></script>
<script src="js/helpers.js"></script>
<script src="js/nav.js"></script>
<script src="js/calendar-scheduling.js"></script>
<script>
  document.getElementById("new-session-btn").addEventListener("click", () => {
    document.getElementById("new-session-panel").classList.toggle("hidden");
  });
</script>
</body>
</html>
