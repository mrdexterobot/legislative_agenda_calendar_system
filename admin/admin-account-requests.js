async function renderRequestsAdmin() {
  const container = document.getElementById("requests-list");
  let requests;
  try {
    requests = await window.API.get("api/account-requests/list.php");
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load: ${err.message}</p>`;
    return;
  }

  const statusPill = {
    pending:  `<span class="pill pill-brass">Pending</span>`,
    approved: `<span class="pill pill-forest">Approved</span>`,
    rejected: `<span class="pill pill-maroon">Rejected</span>`,
  };

  container.innerHTML = requests.length ? requests.map(r => {
    const isOwnRequest = r.user_id === window.CURRENT_USER.id;
    return `
    <div class="dossier-card ${r.request_type === "deactivation" ? "accent-maroon" : "accent-info"} p-4">
      <div class="flex items-center justify-between gap-2 flex-wrap mb-1">
        <div>
          <span class="text-sm font-semibold text-ink-900">${r.current_full_name}</span>
          <span class="text-xs text-slate-500 font-mono ml-1">${r.username}</span>
        </div>
        ${statusPill[r.status] || ""}
      </div>
      <p class="text-xs text-slate-600"><strong>${r.request_type === "info_update" ? "Info update" : "Deactivation"}</strong> requested ${new Date(r.created_at).toLocaleDateString()}</p>
      <p class="text-xs text-slate-600 italic mt-1">"${r.reason}"</p>
      ${r.requested_username ? `<p class="text-xs text-slate-600 mt-1">New username: <strong class="font-mono">${r.requested_username}</strong> <span class="text-slate-400">(current: ${r.username})</span></p>` : ""}
      ${r.requested_full_name ? `<p class="text-xs text-slate-600 mt-1">New name: <strong>${r.requested_full_name}</strong> <span class="text-slate-400">(current: ${r.current_full_name})</span></p>` : ""}
      ${r.requested_email ? `<p class="text-xs text-slate-600">New email: <strong>${r.requested_email}</strong> <span class="text-slate-400">(current: ${r.current_email})</span></p>` : ""}
      ${r.status === "pending" && r.requested_username ? `<p class="text-[11px] text-brass-700 mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Approving this changes what they sign in with — tell them before you approve it.</p>` : ""}
      ${r.status !== "pending" ? `<p class="text-[11px] text-slate-400 mt-1">Reviewed by ${r.reviewed_by} on ${new Date(r.reviewed_at).toLocaleDateString()}${r.admin_notes ? `: "${r.admin_notes}"` : ""}</p>` : ""}
      ${r.status === "pending" ? (
        isOwnRequest
          ? `<p class="text-[11px] text-slate-400 mt-2"><i class="fa-solid fa-lock mr-1"></i>This is your own request — another admin needs to review it.</p>`
          : `<div class="flex gap-2 mt-3">
              <button data-approve="${r.id}" class="btn-primary text-xs !py-1.5">Approve</button>
              <button data-reject="${r.id}" class="btn-outline text-xs !py-1.5 !border-maroon-600 !text-maroon-700">Reject</button>
            </div>`
      ) : ""}
    </div>
  `;
  }).join("") : `<p class="text-sm text-slate-400 text-center py-10">No requests yet.</p>`;

  document.querySelectorAll("[data-approve]").forEach(btn => {
    btn.addEventListener("click", async () => {
      const id = btn.dataset.approve;
      if (!confirm("Approve this request? The change will be applied immediately.")) return;
      try {
        const result = await window.API.post("api/account-requests/resolve.php", { id, action: "approve" });
        if (result.username_changed) {
          alert("Approved. That person now signs in with the new username — let them know.");
        }
        renderRequestsAdmin();
      } catch (err) {
        alert(`Could not approve: ${err.message}`);
      }
    });
  });

  document.querySelectorAll("[data-reject]").forEach(btn => {
    btn.addEventListener("click", () => {
      const id = btn.dataset.reject;
      openReasonModal({
        title: "Reject this request?",
        confirmLabel: "Reject",
        onConfirm: async (reason) => {
          await window.API.post("api/account-requests/resolve.php", { id, action: "reject", admin_notes: reason });
          renderRequestsAdmin();
        },
      });
    });
  });
}

document.addEventListener("DOMContentLoaded", renderRequestsAdmin);
