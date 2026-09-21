function renderAccountInfo() {
  document.getElementById("profile-full-name").textContent = window.CURRENT_USER?.full_name || "—";
  document.getElementById("profile-email").textContent = window.CURRENT_USER?.email || "—";
}

async function renderMyRequests() {
  const container = document.getElementById("my-requests-list");
  let requests;
  try {
    requests = await window.API.get("api/account-requests/list-mine.php");
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load: ${err.message}</p>`;
    return;
  }

  const statusPill = {
    pending:  `<span class="pill pill-brass">Pending</span>`,
    approved: `<span class="pill pill-forest">Approved</span>`,
    rejected: `<span class="pill pill-maroon">Rejected</span>`,
  };

  container.innerHTML = requests.length ? requests.map(r => `
    <div class="dossier-card p-4">
      <div class="flex items-center justify-between gap-2 flex-wrap mb-1">
        <span class="text-sm font-semibold text-ink-900">${r.request_type === "info_update" ? "Info update" : "Deactivation"}</span>
        ${statusPill[r.status] || ""}
      </div>
      <p class="text-xs text-slate-500">Submitted ${new Date(r.created_at).toLocaleDateString()}</p>
      ${r.reason ? `<p class="text-xs text-slate-600 italic mt-1">"${r.reason}"</p>` : ""}
      ${r.requested_username ? `<p class="text-xs text-slate-600 mt-1">Requested username: <span class="font-mono">${r.requested_username}</span></p>` : ""}
      ${r.requested_full_name ? `<p class="text-xs text-slate-600 mt-1">Requested name: ${r.requested_full_name}</p>` : ""}
      ${r.requested_email ? `<p class="text-xs text-slate-600">Requested email: ${r.requested_email}</p>` : ""}
      ${r.status === "approved" && r.requested_username ? `<p class="text-[11px] text-forest-700 mt-1"><i class="fa-solid fa-circle-info mr-1"></i>Sign in with your new username from now on.</p>` : ""}
      ${r.status !== "pending" ? `<p class="text-[11px] text-slate-400 mt-1">Reviewed by ${r.reviewed_by} on ${new Date(r.reviewed_at).toLocaleDateString()}${r.admin_notes ? `: "${r.admin_notes}"` : ""}</p>` : ""}
    </div>
  `).join("") : `<p class="text-sm text-slate-400">No requests yet.</p>`;
}

document.getElementById("change-password-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target));
  const errorEl = document.getElementById("change-password-error");
  const successEl = document.getElementById("change-password-success");
  errorEl.classList.add("hidden");
  successEl.classList.add("hidden");
  try {
    await window.API.post("api/auth/change-password.php", fd);
    e.target.reset();
    successEl.classList.remove("hidden");
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove("hidden");
  }
});

document.getElementById("info-update-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target));
  const errorEl = document.getElementById("info-update-error");
  errorEl.classList.add("hidden");
  try {
    await window.API.post("api/account-requests/create.php", { request_type: "info_update", ...fd });
    e.target.reset();
    renderMyRequests();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove("hidden");
  }
});

document.getElementById("request-deactivation-btn").addEventListener("click", () => {
  const errorEl = document.getElementById("deactivation-error");
  errorEl.classList.add("hidden");
  openReasonModal({
    title: "Request account deactivation",
    description: "An admin will review this before your account is actually deactivated.",
    confirmLabel: "Submit request",
    onConfirm: async (reason) => {
      await window.API.post("api/account-requests/create.php", { request_type: "deactivation", reason });
      renderMyRequests();
    },
  });
});

document.addEventListener("DOMContentLoaded", () => {
  renderAccountInfo();
  renderMyRequests();
});
