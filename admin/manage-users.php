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
<title>Manage Users — Admin</title>
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
        <div class="flex items-center justify-between">
          <a href="index.php" class="text-xs text-slate-500 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Back to Admin</a>
          <button id="new-user-btn" class="btn-primary text-xs !py-2"><i class="fa-solid fa-plus mr-1"></i>New account</button>
        </div>
        <h1 class="font-display text-xl text-ink-900">Manage User Accounts</h1>
        <p class="text-sm text-slate-500">
          Accounts are never deleted — deactivating one blocks sign-in while keeping that person's actions
          attributable in the audit trail. Edit an account to correct its username or email, hand out a new
          password, or change whether it requires an emailed sign-in code.
        </p>

        <div id="new-user-panel" class="hidden dossier-card accent-info p-5">
          <h3 class="font-display text-base text-ink-900 mb-3">Create account</h3>
          <form id="new-user-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div><label class="block text-xs font-semibold text-slate-600 mb-1">Username</label>
              <input name="username" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
            <div><label class="block text-xs font-semibold text-slate-600 mb-1">Full name</label>
              <input name="full_name" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
            <div><label class="block text-xs font-semibold text-slate-600 mb-1">Email</label>
              <input name="email" type="email" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
            <div><label class="block text-xs font-semibold text-slate-600 mb-1">Password <span class="font-normal text-slate-400">(8+ chars, a letter and a number)</span></label>
              <input name="password" type="password" required minlength="8" autocomplete="new-password" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
            <div><label class="block text-xs font-semibold text-slate-600 mb-1">Role</label>
              <select name="role" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option value="staff">Staff (councilor/legislative staff)</option>
                <option value="admin">Admin</option>
              </select></div>
            <div class="flex items-end">
              <label class="flex items-center gap-2 text-sm text-ink-800 pb-2">
                <input type="checkbox" name="mfa_enabled" class="rounded border-[--line-200]" />
                Require an emailed sign-in code
              </label>
            </div>
            <p id="new-user-error" class="hidden sm:col-span-2 text-xs text-maroon-700"></p>
            <div class="sm:col-span-2 flex gap-2 justify-end">
              <button type="button" onclick="document.getElementById('new-user-panel').classList.add('hidden')" class="btn-outline text-xs !py-2">Cancel</button>
              <button type="submit" class="btn-primary text-xs !py-2">Create</button>
            </div>
          </form>
        </div>

        <div id="users-list" class="space-y-2"></div>
      </main>
    </div>
  </div>

  <div id="edit-user-modal-root"></div>

<script src="../js/api-client.js"></script>
<script src="../js/nav.js"></script>
<script src="../js/password-toggle.js"></script>
<script src="admin-users.js"></script>
<script>
  document.getElementById("new-user-btn").addEventListener("click", () => {
    document.getElementById("new-user-panel").classList.toggle("hidden");
  });
</script>
</body>
</html>
