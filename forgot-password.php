<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . appBasePath() . '/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Reset Password — Legislative Agenda &amp; Calendar Management System</title>

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
      <p class="text-[#B9C2D6] text-sm max-w-sm">A reset code is sent to the email on file for your account. If you are not sure which address that is, your office administrator can look it up.</p>
      <p class="text-[11px] text-[#8C9BB5]">Accounts here are issued by an administrator, not self-registered.</p>
    </div>

    <div class="lg:col-span-3 flex items-center justify-center p-8">
      <div class="w-full max-w-sm">

        <!-- Step 1: request a code -->
        <div id="step-request">
          <h1 class="font-display text-2xl text-ink-900 mb-1">Reset your password</h1>
          <p class="text-sm text-slate-500 mb-8">Enter the username or email address on your account.</p>

          <form id="request-form" class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1.5" for="identifier">Username or email</label>
              <input id="identifier" type="text" required autocomplete="username"
                class="w-full border border-[--line-200] rounded-lg px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-ink-700/30 focus:border-ink-700" />
            </div>
            <p id="request-error" class="hidden text-xs text-maroon-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i><span id="request-error-text"></span></p>
            <button type="submit" id="request-btn" class="btn-primary w-full">Send reset code</button>
          </form>
        </div>

        <!-- Step 2: enter code + new password -->
        <div id="step-reset" class="hidden">
          <h1 class="font-display text-2xl text-ink-900 mb-1">Enter your code</h1>
          <p id="step-reset-desc" class="text-sm text-slate-500 mb-8"></p>

          <form id="reset-form" class="space-y-4">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1.5" for="code">6-digit code</label>
              <input id="code" type="text" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"
                class="w-full border border-[--line-200] rounded-lg px-3 py-2.5 text-sm bg-white font-mono tracking-[0.4em] text-center focus:outline-none focus:ring-2 focus:ring-ink-700/30 focus:border-ink-700" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1.5" for="new-password">New password</label>
              <input id="new-password" type="password" required minlength="8" autocomplete="new-password"
                class="w-full border border-[--line-200] rounded-lg px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-ink-700/30 focus:border-ink-700" />
              <p class="text-[11px] text-slate-400 mt-1">At least 8 characters, including a letter and a number.</p>
            </div>
            <p id="reset-error" class="hidden text-xs text-maroon-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i><span id="reset-error-text"></span></p>
            <p id="reset-notice" class="hidden text-xs text-forest-700"></p>
            <button type="submit" id="reset-btn" class="btn-primary w-full">Update password</button>
            <div class="flex items-center justify-between">
              <button type="button" id="resend-code" class="text-xs text-ink-700 hover:underline">Send a new code</button>
              <button type="button" id="back-to-request" class="text-xs text-slate-500 hover:underline">Use a different account</button>
            </div>
          </form>
        </div>

        <p class="text-xs text-slate-500 mt-6"><a href="index.php" class="text-ink-700 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Back to sign in</a></p>
      </div>
    </div>
  </div>

<script src="js/password-toggle.js"></script>
<script>
  let currentIdentifier = "";

  async function readResetResponse(res) {
    const body = await res.text();
    try {
      return JSON.parse(body);
    } catch (_) {
      // This normally means PHP or the web server failed before the endpoint
      // could produce its JSON response. Keep that implementation detail out
      // of the form while preserving a useful next step for the user.
      throw new Error("The password-reset service returned an invalid response. Please try again or contact your administrator.");
    }
  }

  async function requestCode(identifier) {
    const res = await fetch("api/auth/forgot-password.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ identifier }),
    });
    const json = await readResetResponse(res);
    if (!res.ok || !json.success) throw new Error(json.error || "Could not send a reset code.");
    return json.data;
  }

  document.getElementById("request-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    currentIdentifier = document.getElementById("identifier").value.trim();
    const btn = document.getElementById("request-btn");
    const errorEl = document.getElementById("request-error");
    errorEl.classList.add("hidden");
    btn.disabled = true;
    btn.textContent = "Sending…";

    try {
      const data = await requestCode(currentIdentifier);
      document.getElementById("step-reset-desc").textContent =
        data.message || "A 6-digit code was sent. It expires in 15 minutes.";
      document.getElementById("step-request").classList.add("hidden");
      document.getElementById("step-reset").classList.remove("hidden");
      document.getElementById("code").focus();
    } catch (err) {
      document.getElementById("request-error-text").textContent = err.message;
      errorEl.classList.remove("hidden");
    } finally {
      btn.disabled = false;
      btn.textContent = "Send reset code";
    }
  });

  document.getElementById("resend-code").addEventListener("click", async () => {
    const noticeEl = document.getElementById("reset-notice");
    const errorEl = document.getElementById("reset-error");
    noticeEl.classList.add("hidden");
    errorEl.classList.add("hidden");
    try {
      await requestCode(currentIdentifier);
      noticeEl.textContent = "A new code is on its way. The previous one no longer works.";
      noticeEl.classList.remove("hidden");
    } catch (err) {
      document.getElementById("reset-error-text").textContent = err.message;
      errorEl.classList.remove("hidden");
    }
  });

  document.getElementById("back-to-request").addEventListener("click", () => {
    document.getElementById("step-reset").classList.add("hidden");
    document.getElementById("step-request").classList.remove("hidden");
  });

  document.getElementById("reset-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const code = document.getElementById("code").value.trim();
    const newPassword = document.getElementById("new-password").value;
    const errorEl = document.getElementById("reset-error");
    const btn = document.getElementById("reset-btn");
    errorEl.classList.add("hidden");
    btn.disabled = true;
    btn.textContent = "Updating…";

    try {
      const res = await fetch("api/auth/reset-password.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ identifier: currentIdentifier, code, new_password: newPassword }),
      });
      const json = await readResetResponse(res);
      if (!res.ok || !json.success) throw new Error(json.error || "Could not reset password.");
      window.location.href = "index.php?reason=password_reset";
    } catch (err) {
      document.getElementById("reset-error-text").textContent = err.message;
      errorEl.classList.remove("hidden");
      btn.disabled = false;
      btn.textContent = "Update password";
    }
  });
</script>
</body>
</html>
