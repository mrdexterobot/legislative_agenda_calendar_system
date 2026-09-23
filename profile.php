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
<title>My Profile — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans bg-paper-50" data-page="profile">

<script>
  window.CURRENT_USER = <?php echo json_encode($currentUser); ?>;
  window.CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
  window.APP_BASE_PATH = <?php echo json_encode(appBasePath()); ?>;
</script>

  <div class="flex">
    <div id="app-sidebar"></div>

    <div class="flex-1 min-w-0">
      <div id="app-topbar"></div>

      <main class="p-8 max-w-2xl mx-auto space-y-6">

        <section class="dossier-card p-5">
          <h2 class="font-display text-base text-ink-900 mb-3">Account</h2>
          <dl class="text-sm space-y-1.5">
            <div class="flex justify-between"><dt class="text-slate-500">Username</dt><dd class="font-mono"><?php echo htmlspecialchars($currentUser['username']); ?></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Full name</dt><dd id="profile-full-name"></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Email</dt><dd id="profile-email"></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Role</dt><dd class="capitalize"><?php echo htmlspecialchars($currentUser['role']); ?></dd></div>
          </dl>
          <p class="text-[11px] text-slate-400 mt-3"><i class="fa-regular fa-circle-info mr-1"></i>Your username, name, and email are set by an admin. Use "Request info update" below if any of them needs to change.</p>
        </section>

        <section class="dossier-card accent-info p-5">
          <h2 class="font-display text-base text-ink-900 mb-1">Trusted sign-in device</h2>
          <p class="text-xs text-slate-500 mb-3">If you selected “Remember this device,” this browser can skip the emailed MFA code for up to 30 days. Your password is still required.</p>
          <button id="forget-device-btn" class="btn-outline text-xs !py-2" type="button">Forget this browser</button>
          <p id="forget-device-success" class="hidden text-xs text-forest-700 mt-2"><i class="fa-solid fa-circle-check mr-1"></i>This browser has been forgotten.</p>
          <p id="forget-device-error" class="hidden text-xs text-maroon-700 mt-2"></p>
        </section>

        <section class="dossier-card p-5">
          <h2 class="font-display text-base text-ink-900 mb-3">Change password</h2>
          <form id="change-password-form" class="space-y-3">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Current password</label>
              <input name="current_password" type="password" required autocomplete="current-password" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">New password <span class="font-normal text-slate-400">(8+ characters, with a letter and a number)</span></label>
              <input name="new_password" type="password" required minlength="8" autocomplete="new-password" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <p id="change-password-error" class="hidden text-xs text-maroon-700"></p>
            <p id="change-password-success" class="hidden text-xs text-forest-700"><i class="fa-solid fa-circle-check mr-1"></i>Password updated.</p>
            <button type="submit" class="btn-primary text-xs !py-2">Update password</button>
          </form>
        </section>

        <section class="dossier-card p-5">
          <h2 class="font-display text-base text-ink-900 mb-1">Request info update</h2>
          <p class="text-xs text-slate-500 mb-3">Submitted to an admin for review — your current details stay unchanged until it is approved.</p>
          <form id="info-update-form" class="space-y-3">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">New username <span class="font-normal text-slate-400">(leave blank to keep current)</span></label>
              <input name="requested_username" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm font-mono" />
              <p class="text-[11px] text-slate-400 mt-1">If approved, this becomes what you sign in with. Letters, numbers, dots, and underscores only.</p>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">New full name <span class="font-normal text-slate-400">(leave blank to keep current)</span></label>
              <input name="requested_full_name" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">New email <span class="font-normal text-slate-400">(leave blank to keep current)</span></label>
              <input name="requested_email" type="email" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
              <p class="text-[11px] text-slate-400 mt-1">Sign-in codes and reset codes go to this address, so it has to be a mailbox you can open.</p>
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Reason</label>
              <textarea name="reason" rows="2" required class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm"></textarea>
            </div>
            <p id="info-update-error" class="hidden text-xs text-maroon-700"></p>
            <button type="submit" class="btn-outline text-xs !py-2">Submit request</button>
          </form>
        </section>

        <section class="dossier-card accent-maroon p-5">
          <h2 class="font-display text-base text-ink-900 mb-1">Request account deactivation</h2>
          <p class="text-xs text-slate-500 mb-3">Requires admin approval — your account stays active until then.</p>
          <button id="request-deactivation-btn" class="text-xs text-maroon-700 font-semibold hover:underline"><i class="fa-solid fa-user-slash mr-1"></i>Request deactivation</button>
          <p id="deactivation-error" class="hidden text-xs text-maroon-700 mt-2"></p>
        </section>

        <section>
          <h2 class="font-display text-base text-ink-900 mb-3">Your requests</h2>
          <div id="my-requests-list" class="space-y-2"></div>
        </section>

      </main>
    </div>
  </div>

<script src="js/api-client.js"></script>
<script src="js/helpers.js"></script>
<script src="js/nav.js"></script>
<script src="js/password-toggle.js"></script>
<script src="js/profile.js"></script>
</body>
</html>
