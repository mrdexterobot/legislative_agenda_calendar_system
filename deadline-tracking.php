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
<title>Deadline Tracking — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="deadline">

<script>
  window.CURRENT_USER = <?php echo json_encode($currentUser); ?>;
  window.CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
  window.APP_BASE_PATH = <?php echo json_encode(appBasePath()); ?>;
</script>

  <div class="flex">
    <div id="app-sidebar"></div>

    <div class="flex-1 min-w-0">
      <div id="app-topbar"></div>

      <main class="p-8 max-w-4xl mx-auto space-y-6">
        <div class="flex items-center justify-between flex-wrap gap-3">
          <p class="text-sm text-slate-500 max-w-xl">
            Statutory clocks (the Mayor's action window on a passed ordinance, auto-generated when 3rd Reading is
            recorded in Calendar Scheduling) and internal ones (committee reports, referral responses) you define
            yourself below, so nothing lapses unnoticed.
          </p>
          <div class="flex items-center gap-2">
            <label class="flex items-center gap-2 text-xs text-slate-600 bg-white border border-[--line-200] rounded-lg px-3 py-2">
              Remind
              <input id="reminder-lead" type="number" min="1" max="14" value="3" class="w-12 border border-[--line-200] rounded px-1 py-0.5 text-center" />
              days ahead
            </label>
            <button id="new-deadline-btn" class="btn-primary text-xs !py-2"><i class="fa-solid fa-plus mr-1"></i>Add deadline</button>
          </div>
        </div>

        <div id="new-deadline-panel" class="hidden dossier-card accent-info p-5">
          <h3 class="font-display text-base text-ink-900 mb-1">Track a new deadline</h3>
          <p class="text-xs text-slate-500 mb-3">
            For anything that doesn't have an automatic trigger yet — e.g. a committee report due date, a referral
            response, or any other internal target your office wants reminders for.
          </p>
          <form id="new-deadline-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
              <label class="block text-xs font-semibold text-slate-600 mb-1">What are you tracking?</label>
              <input name="label" required placeholder="e.g. Committee report due — Transportation & Traffic" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Type/category</label>
              <input name="deadline_type" required placeholder="e.g. Committee Report" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Due date</label>
              <input name="due_date" type="date" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-semibold text-slate-600 mb-1">Related agenda item ID <span class="font-normal text-slate-400">(optional, e.g. ORD-2026-014)</span></label>
              <input name="related_item_id" placeholder="Leave blank if not tied to a specific item" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm font-mono" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-semibold text-slate-600 mb-1">Assign to <span class="font-normal text-slate-400">(optional — leave unassigned for anyone with access to complete)</span></label>
              <select name="assigned_to" id="new-deadline-assignee" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option value="">Unassigned</option>
              </select>
            </div>
            <p id="new-deadline-error" class="hidden sm:col-span-2 text-xs text-maroon-700"></p>
            <div class="sm:col-span-2 flex gap-2 justify-end">
              <button type="button" onclick="document.getElementById('new-deadline-panel').classList.add('hidden')" class="btn-outline text-xs !py-2">Cancel</button>
              <button type="submit" class="btn-primary text-xs !py-2">Save</button>
            </div>
          </form>
        </div>

        <div class="dossier-card accent-maroon p-4 text-xs text-slate-600">
          <i class="fa-solid fa-circle-info text-maroon-700 mr-1.5"></i>
          Red-flagged deadlines are statutory (fixed by law) rather than internal office targets — currently the
          Mayor's <span class="font-mono">10-day</span> action window on a passed ordinance/resolution
          (<span class="font-mono">15 days</span> if this LGU is a province), per Sec. 54(b) of the Local Government
          Code. If that window lapses with no signature and no veto, the item is <strong>deemed approved</strong>.
          This is computed live from each item's transmittal date — there's no separate copy to keep in sync.
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div id="deadline-filter-tabs" class="flex flex-wrap gap-2"></div>
          <label class="flex items-center gap-2 text-xs text-slate-600">
            Sort by
            <select id="deadline-sort-select" class="border border-[--line-200] rounded-lg px-2 py-1.5 text-xs bg-white">
              <option value="due_date">Due date</option>
              <option value="status">Status</option>
              <option value="assignee">Assignee</option>
            </select>
          </label>
        </div>

        <div id="deadline-list" class="space-y-3"></div>
      </main>
    </div>
  </div>

  <div id="complete-modal-root"></div>

<script src="js/api-client.js"></script>
<script src="js/helpers.js"></script>
<script src="js/nav.js"></script>
<script src="js/deadline-tracking.js"></script>
</body>
</html>
