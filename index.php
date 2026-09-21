<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Skip straight to the dashboard.
if (isLoggedIn()) {
    header('Location: ' . appBasePath() . '/dashboard.php');
    exit;
}

$reason = $_GET['reason'] ?? '';
$noticeMap = [
    'login_required'  => 'Please sign in to continue.',
    'session_expired' => 'Your session expired. Please sign in again.',
    'not_authorized'  => 'You do not have access to that page.',
    'password_reset'  => 'Your password was updated. Please sign in.',
];
$notice = $noticeMap[$reason] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Sign In — Legislative Agenda &amp; Calendar Management System</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="js/tailwind-config.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/styles.css" />
</head>
<body class="font-sans">

  <div class="min-h-screen grid lg:grid-cols-5">

    <div class="lg:col-span-2 app-sidebar text-white flex flex-col justify-between p-10">
      <div class="flex items-center gap-3">
        <div class="seal-badge"><i class="fa-solid fa-landmark"></i></div>
        <div>
          <div class="font-semibold text-sm">Legislative Services System</div>
          <div class="text-[#8C9BB5] text-xs">Agenda &amp; Calendar Management</div>
        </div>
      </div>

      <div>
        <p class="font-display text-3xl leading-snug mb-4">Every item has a route slip.<br/>This is where it gets stamped.</p>
        <p class="text-[#B9C2D6] text-sm max-w-sm mb-8">From first reading to the Mayor's desk — priority, schedule, logistics, deadlines, and sign-off, tracked in one place.</p>

        <div class="bg-white/5 rounded-lg p-4 border border-white/10 max-w-sm">
          <div class="text-[11px] text-[#8C9BB5] mb-3 font-mono">ORD-2026-014 · sample</div>
          <div class="flex items-center gap-2">
            <div class="stamp-circle is-done !w-7 !h-7 !text-[11px]"><i class="fa-solid fa-check"></i></div>
            <div class="h-px w-4 bg-white/20"></div>
            <div class="stamp-circle is-done !w-7 !h-7 !text-[11px]"><i class="fa-solid fa-check"></i></div>
            <div class="h-px w-4 bg-white/20"></div>
            <div class="stamp-circle is-current !w-7 !h-7 !text-[11px]"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="h-px w-4 bg-white/20"></div>
            <div class="stamp-circle !w-7 !h-7 !text-[11px]"><i class="fa-solid fa-ellipsis"></i></div>
          </div>
        </div>
      </div>

      <p class="text-[11px] text-[#8C9BB5]">Sangguniang Panlungsod of San Jose del Monte, Bulacan</p>
    </div>

    <div class="lg:col-span-3 flex items-center justify-center p-8">
      <div class="w-full max-w-sm">

        <!-- Step 1: username + password -->
        <div id="step-credentials">
          <h1 class="font-display text-2xl text-ink-900 mb-1">Sign in</h1>
          <p class="text-sm text-slate-500 mb-8">Access your office's agenda and calendar workspace.</p>

          <?php if ($notice): ?>
          <p class="mb-4 text-xs text-info-700 bg-info-100 border border-info-100 rounded-lg p-3">
            <i class="fa-solid fa-circle-info mr-1"></i><?php echo htmlspecialchars($notice); ?>
          </p>
          <?php endif; ?>

          <form id="login-form" class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1.5" for="username">Username</label>
              <input id="username" type="text" required autocomplete="username"
                class="w-full border border-[--line-200] rounded-lg px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-ink-700/30 focus:border-ink-700" />
            </div>
            <div>
              <div class="flex items-center justify-between mb-1.5">
                <label class="block text-xs font-semibold text-slate-600" for="password">Password</label>
                <a href="forgot-password.php" class="text-xs text-ink-700 hover:underline">Forgot password?</a>
              </div>
              <input id="password" type="password" required autocomplete="current-password"
                class="w-full border border-[--line-200] rounded-lg px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-ink-700/30 focus:border-ink-700" />
            </div>
            <p id="login-error" class="hidden text-xs text-maroon-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i><span id="login-error-text"></span></p>
            <button type="submit" id="login-btn" class="btn-primary w-full flex items-center justify-center gap-2">
              <span id="login-btn-text">Sign in</span> <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
          </form>

          <p class="text-[11px] text-slate-500 mt-6">
            <i class="fa-regular fa-circle-info mr-1"></i>
            Accounts are issued by your office administrator. Five incorrect attempts temporarily lock an account.
          </p>
        </div>

        <!-- Step 2: emailed sign-in code -->
        <div id="step-mfa" class="hidden">
          <h1 class="font-display text-2xl text-ink-900 mb-1">Check your email</h1>
          <p class="text-sm text-slate-500 mb-8">
            Your password was accepted. A 6-digit sign-in code is on its way to
            <span id="mfa-email-hint" class="font-mono text-ink-800"></span>. It expires in 10 minutes.
          </p>

          <form id="mfa-form" class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1.5" for="mfa-code">Sign-in code</label>
              <input id="mfa-code" type="text" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"
                class="w-full border border-[--line-200] rounded-lg px-3 py-2.5 text-sm bg-white font-mono tracking-[0.4em] text-center focus:outline-none focus:ring-2 focus:ring-ink-700/30 focus:border-ink-700" />
            </div>
            <p id="mfa-error" class="hidden text-xs text-maroon-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i><span id="mfa-error-text"></span></p>
            <p id="mfa-notice" class="hidden text-xs text-forest-700"></p>
            <button type="submit" id="mfa-btn" class="btn-primary w-full">Verify and sign in</button>
            <div class="flex items-center justify-between">
              <button type="button" id="mfa-resend" class="text-xs text-ink-700 hover:underline">Send a new code</button>
              <button type="button" id="mfa-back" class="text-xs text-slate-500 hover:underline">Start over</button>
            </div>
          </form>

          <p class="text-[11px] text-slate-500 mt-6">
            <i class="fa-regular fa-circle-info mr-1"></i>
            If this code arrives and you were not signing in, someone else has your password. Change it immediately
            and tell your administrator.
          </p>
        </div>

      </div>
    </div>
  </div>

<script src="js/password-toggle.js"></script>
<script>
  const show = (id) => document.getElementById(id).classList.remove("hidden");
  const hide = (id) => document.getElementById(id).classList.add("hidden");

  document.getElementById("login-form").addEventListener("submit", async function (e) {
    e.preventDefault();
    const username = document.getElementById("username").value.trim();
    const password = document.getElementById("password").value;
    const errorEl = document.getElementById("login-error");
    const errorText = document.getElementById("login-error-text");
    const btn = document.getElementById("login-btn");
    const btnText = document.getElementById("login-btn-text");

    errorEl.classList.add("hidden");
    btn.disabled = true;
    btnText.textContent = "Signing in…";

    try {
      const res = await fetch("api/auth/login.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, password }),
      });
      const json = await res.json();
      if (!res.ok || !json.success) throw new Error(json.error || "Sign in failed.");

      if (json.data.mfa_required) {
        document.getElementById("mfa-email-hint").textContent = json.data.email_hint;
        hide("step-credentials");
        show("step-mfa");
        document.getElementById("mfa-code").focus();
        return;
      }

      window.location.href = "dashboard.php";
    } catch (err) {
      errorText.textContent = err.message;
      errorEl.classList.remove("hidden");
    } finally {
      btn.disabled = false;
      btnText.textContent = "Sign in";
    }
  });

  document.getElementById("mfa-form").addEventListener("submit", async function (e) {
    e.preventDefault();
    const code = document.getElementById("mfa-code").value.trim();
    const errorEl = document.getElementById("mfa-error");
    const errorText = document.getElementById("mfa-error-text");
    const btn = document.getElementById("mfa-btn");

    errorEl.classList.add("hidden");
    hide("mfa-notice");
    btn.disabled = true;
    btn.textContent = "Verifying…";

    try {
      const res = await fetch("api/auth/verify-mfa.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ code }),
      });
      const json = await res.json();
      if (!res.ok || !json.success) throw new Error(json.error || "Could not verify that code.");
      window.location.href = "dashboard.php";
    } catch (err) {
      errorText.textContent = err.message;
      errorEl.classList.remove("hidden");
      btn.disabled = false;
      btn.textContent = "Verify and sign in";
    }
  });

  document.getElementById("mfa-resend").addEventListener("click", async () => {
    const noticeEl = document.getElementById("mfa-notice");
    const errorEl = document.getElementById("mfa-error");
    errorEl.classList.add("hidden");
    noticeEl.classList.add("hidden");
    try {
      const res = await fetch("api/auth/resend-mfa.php", { method: "POST", headers: { "Content-Type": "application/json" } });
      const json = await res.json();
      if (!res.ok || !json.success) throw new Error(json.error || "Could not send a new code.");
      noticeEl.textContent = "A new code is on its way. The previous one no longer works.";
      noticeEl.classList.remove("hidden");
    } catch (err) {
      document.getElementById("mfa-error-text").textContent = err.message;
      errorEl.classList.remove("hidden");
    }
  });

  document.getElementById("mfa-back").addEventListener("click", () => {
    document.getElementById("mfa-code").value = "";
    hide("step-mfa");
    show("step-credentials");
  });
</script>
</body>
</html>
