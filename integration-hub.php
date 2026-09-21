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
<title>Integration Hub — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="hub">

<script>
  window.CURRENT_USER = <?php echo json_encode($currentUser); ?>;
  window.CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
  window.APP_BASE_PATH = <?php echo json_encode(appBasePath()); ?>;
</script>

  <div class="flex">
    <div id="app-sidebar"></div>

    <div class="flex-1 min-w-0">
      <div id="app-topbar"></div>

      <main class="p-8 max-w-5xl mx-auto space-y-8">
        <div class="dossier-card accent-maroon p-4 text-xs text-slate-600">
          <i class="fa-solid fa-diagram-project text-maroon-700 mr-1.5"></i>
          <strong>This page plays the role of the peer subsystems we integrate with.</strong> Nothing below is a
          real external connection — it's this same codebase, deliberately structured as a visible stand-in so the
          exchange is demonstrable instead of an invisible background function call. Each section below corresponds
          to one real data boundary in the system.
        </div>

        <!-- Section A: Session and Legislative Meeting Management System -->
        <section>
          <h2 class="font-display text-lg text-ink-900 mb-1"><i class="fa-solid fa-calendar-check text-info-700 mr-1.5"></i>Session and Legislative Meeting Management System</h2>
          <p class="text-xs text-slate-500 mb-3">Receives proposed schedules from Calendar Scheduling, replies with attendee/agenda confirmation, which then unlocks Meeting Coordination's "Send notifications" step.</p>
          <div id="hub-scheduling-events" class="dossier-card p-4 space-y-2">
            <p class="text-xs text-slate-400">Loading…</p>
          </div>
        </section>

        <!-- Section B: Agenda Preparation Module -->
        <section>
          <h2 class="font-display text-lg text-ink-900 mb-1"><i class="fa-solid fa-scale-balanced text-brass-700 mr-1.5"></i>Agenda Preparation Module <span class="font-normal text-sm text-slate-400">(receives confirmed priority from Priority Setting)</span></h2>
          <p class="text-xs text-slate-500 mb-3">Select which confirmed items this push covers — a real Agenda Preparation Module compiling a specific session's agenda wouldn't hand over its entire backlog at once.</p>
          <div id="hub-agenda-prep" class="dossier-card p-4 space-y-2 mb-3">
            <p class="text-xs text-slate-400">Loading…</p>
          </div>
          <div class="flex items-center gap-3">
            <button id="hub-select-all-btn" class="text-[11px] text-ink-700 hover:underline">Select all</button>
            <button id="hub-push-agenda-btn" class="btn-primary text-xs !py-2" disabled><i class="fa-solid fa-paper-plane mr-1"></i>Push selected to Calendar Scheduling</button>
          </div>
          <p class="text-[11px] text-slate-400 mt-1">This is what makes items show up in Calendar Scheduling's "items to attach" checklist — Calendar Scheduling no longer shows every eligible item, only what's been sent here.</p>
          <div id="hub-push-result" class="hidden mt-2 dossier-card accent-forest p-3 text-xs text-forest-700"></div>
        </section>

        <!-- Section C: Committee Management and Assignment System -->
        <section>
          <h2 class="font-display text-lg text-ink-900 mb-1"><i class="fa-solid fa-users-gear text-forest-700 mr-1.5"></i>Committee Management and Assignment System <span class="font-normal text-sm text-slate-400">(sends deadline-tracking requests to us)</span></h2>
          <p class="text-xs text-slate-500 mb-3">
            Unlike the Mayor's-window and Sec. 56 deadlines (which our own system generates internally), a committee
            report deadline would realistically be requested BY this peer subsystem. Use the form below to simulate
            that request arriving — it'll appear in Deadline Tracking exactly as if it came from outside.
          </p>
          <form id="hub-deadline-form" class="dossier-card p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="sm:col-span-2">
              <label class="block text-xs font-semibold text-slate-600 mb-1">Related agenda item</label>
              <select name="related_item_id" id="hub-item-select" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm"></select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Due date</label>
              <input name="due_date" type="date" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Label <span class="font-normal text-slate-400">(optional)</span></label>
              <input name="label" placeholder="e.g. Committee report due" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-semibold text-slate-600 mb-1">Assigned to <span class="font-normal text-slate-400">(who owns this deadline — only they, or an admin, can mark it complete)</span></label>
              <select name="assigned_to" id="hub-assignee-select" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option value="">Unassigned — anyone with access can complete it</option>
              </select>
            </div>
            <p id="hub-deadline-error" class="hidden sm:col-span-2 text-xs text-maroon-700"></p>
            <div class="sm:col-span-2 flex justify-end">
              <button type="submit" class="btn-primary text-xs !py-2"><i class="fa-solid fa-paper-plane mr-1"></i>Simulate: send request to Deadline Tracking</button>
            </div>
          </form>
          <div id="hub-deadline-result" class="hidden mt-3 dossier-card accent-forest p-3 text-xs text-forest-700"></div>
        </section>

      </main>
    </div>
  </div>

<script src="js/api-client.js"></script>
<script src="js/helpers.js"></script>
<script src="js/nav.js"></script>
<script src="js/integration-hub.js"></script>
</body>
</html>
