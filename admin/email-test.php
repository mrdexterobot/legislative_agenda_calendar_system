<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';

$currentUser = requirePageRole('admin');
$csrfToken = $_SESSION['csrf_token'] ?? generateCsrfToken();
$configIssues = smtpConfigIssues();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Email Delivery Test — Admin</title>
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
        <h1 class="font-display text-xl text-ink-900">Email Delivery Test</h1>
        <p class="text-sm text-slate-500">
          Password reset codes and meeting notifications both go out through the same SMTP settings in
          <span class="font-mono">includes/config.php</span>. Those two flows hide failures on purpose — the reset
          form gives the same answer whether or not an account exists, so nobody can use it to discover usernames.
          This page is the one place that reports the actual SMTP response.
        </p>

        <?php if ($configIssues): ?>
        <div class="dossier-card accent-maroon p-4">
          <p class="text-sm font-semibold text-maroon-700 mb-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i>SMTP is not fully configured yet</p>
          <ul class="text-xs text-slate-600 list-disc list-inside space-y-1">
            <?php foreach ($configIssues as $issue): ?>
              <li><?php echo htmlspecialchars($issue); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php else: ?>
        <div class="dossier-card accent-forest p-4 text-xs text-slate-600">
          <i class="fa-solid fa-circle-check text-forest-700 mr-1.5"></i>
          Settings look complete. Sending as <span class="font-mono"><?php echo htmlspecialchars(SMTP_FROM_EMAIL); ?></span>
          through <span class="font-mono"><?php echo htmlspecialchars(SMTP_HOST . ':' . SMTP_PORT); ?></span>.
          That sender address has to be verified in Brevo or the relay will refuse it.
        </div>
        <?php endif; ?>

        <div class="dossier-card accent-info p-5">
          <h2 class="font-display text-base text-ink-900 mb-3">Send a test message</h2>
          <form id="test-email-form" class="flex gap-2 flex-wrap">
            <input name="to" type="email" required placeholder="your.own.address@gmail.com"
              class="flex-1 min-w-[240px] border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            <button type="submit" class="btn-primary text-xs !py-2 shrink-0"><i class="fa-solid fa-paper-plane mr-1"></i>Send test</button>
          </form>
          <div id="test-email-result" class="hidden mt-3 text-xs"></div>
        </div>

        <div class="dossier-card p-5 text-xs text-slate-600 space-y-2">
          <p class="font-semibold text-ink-900 text-sm">If the test fails, read the error above first — it names the cause.</p>
          <p><span class="font-semibold">535 authentication failed:</span> <span class="font-mono">SMTP_PASSWORD</span> must be the long <span class="font-mono">xsmtpsib-…</span> key from Brevo → SMTP &amp; API → SMTP, not your Brevo account password.</p>
          <p><span class="font-semibold">501 / 550 on MAIL FROM:</span> <span class="font-mono">SMTP_FROM_EMAIL</span> must be an address verified under Brevo → Senders. It does not need to match <span class="font-mono">SMTP_USERNAME</span>.</p>
          <p><span class="font-semibold">Could not open a connection:</span> outbound port <span class="font-mono">587</span> is blocked. Set <span class="font-mono">SMTP_PORT</span> to <span class="font-mono">2525</span>, which Brevo also accepts and which fewer networks block.</p>
          <p><span class="font-semibold">openssl extension not enabled:</span> local XAMPP only — open <span class="font-mono">php.ini</span>, delete the leading <span class="font-mono">;</span> from <span class="font-mono">;extension=openssl</span>, restart Apache.</p>
          <p>The full outcome of the last attempt is also written to <span class="font-mono">includes/debug_last_email.php</span>.</p>
        </div>
      </main>
    </div>
  </div>
<script src="../js/api-client.js"></script>
<script src="../js/nav.js"></script>
<script>
  document.getElementById("test-email-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const to = new FormData(e.target).get("to");
    const box = document.getElementById("test-email-result");
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    box.className = "mt-3 text-xs dossier-card p-3 text-slate-500";
    box.textContent = "Connecting to the mail relay…";
    box.classList.remove("hidden");
    try {
      const result = await window.API.post("api/admin/test-email.php", { to });
      box.className = "mt-3 text-xs dossier-card accent-forest p-3 text-forest-700";
      box.innerHTML = `<i class="fa-solid fa-circle-check mr-1"></i>Accepted by the relay and addressed to <strong>${result.to}</strong>. ${result.note}`;
    } catch (err) {
      box.className = "mt-3 text-xs dossier-card accent-maroon p-3 text-maroon-700";
      box.innerHTML = `<i class="fa-solid fa-triangle-exclamation mr-1"></i>${err.message}`;
    } finally {
      btn.disabled = false;
    }
  });
</script>
</body>
</html>
