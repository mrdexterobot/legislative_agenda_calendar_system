let reminderLeadDays = 3;
let allDeadlines = [];
let deadlineFilter = "all"; // all | mine | completed-by-me | mayor-window
let deadlineSort = "due_date"; // due_date | status | assignee
let showDeletedDeadlines = false; // admin only

function currentUserId() { return window.CURRENT_USER?.id; }
function isAdminUser() { return isAdminOrAbove(); }

async function renderDeadlineModule() {
  document.getElementById("reminder-lead").value = reminderLeadDays;
  const container = document.getElementById("deadline-list");

  try {
    const query = isAdminUser() && showDeletedDeadlines ? "?include_deleted=1" : "";
    allDeadlines = await window.API.get(`api/deadlines/list.php${query}`);
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load deadlines: ${err.message}</p>`;
    return;
  }

  renderFilterTabs();

  let visible = [...allDeadlines];
  if (deadlineFilter === "mine") {
    visible = visible.filter(d => Number(d.assigned_to_user_id) === Number(currentUserId()));
  } else if (deadlineFilter === "completed-by-me") {
    visible = visible.filter(d => d.status.startsWith("Completed") && d.completed_by === window.CURRENT_USER?.full_name);
  } else if (deadlineFilter === "mayor-window") {
    visible = visible.filter(d => d.source === "computed");
  } else {
    // "All" never includes the Mayor's window — it has its own tab now,
    // so "All" means all internal deadlines instead of an always-mixed list.
    visible = visible.filter(d => d.source === "internal");
  }

  const sorters = {
    due_date: (a, b) => new Date(a.due_date) - new Date(b.due_date),
    status:   (a, b) => a.status.localeCompare(b.status),
    assignee: (a, b) => (a.assigned_to_name || "\uffff").localeCompare(b.assigned_to_name || "\uffff"),
  };
  const sorted = visible.sort(sorters[deadlineSort] || sorters.due_date);

  container.innerHTML = sorted.map(d => {
    const diff = daysBetween(todayIso(), d.due_date);
    const isCompleted = d.status.startsWith("Completed");
    const isStatutory = !!d.is_statutory;
    const isDeleted = !!d.is_deleted;

    // ASSIGNMENT: an assigned deadline can only be completed by that
    // person (or an admin) — see api/deadlines/update.php, which enforces
    // this server-side too. Unassigned deadlines keep the old behavior.
    const isAdmin = isAdminUser();
    const isAssignedToSomeoneElse = !!d.assigned_to_user_id && Number(d.assigned_to_user_id) !== Number(currentUserId()) && !isAdmin;

    // Computed deadlines (Mayor's window) resolve themselves once staff
    // records the mayor's action elsewhere (Admin -> Manage Agenda Items,
    // now with its own required evidence note — see update-mayor-status.php).
    const canMarkComplete = d.source === "internal" && !isCompleted && !isAssignedToSomeoneElse && !isDeleted;
    const canDelete = isAdmin && d.source === "internal" && !isDeleted;
    const canRestore = isAdmin && d.source === "internal" && isDeleted;

    return `
      <div class="dossier-card ${isStatutory ? "accent-maroon" : ""} p-4 flex items-center justify-between flex-wrap gap-3 ${isCompleted || isDeleted ? "opacity-60" : ""}">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1">
            <span class="pill ${isStatutory ? "pill-maroon" : "pill-slate"}">${d.deadline_type}</span>
            ${d.related_item_id ? `<span class="font-mono text-xs text-slate-500">${d.related_item_id}</span>` : ""}
            ${d.auto_generated ? `<span class="text-[10px] text-forest-700"><i class="fa-solid fa-robot mr-0.5"></i>auto-generated</span>` : ""}
            ${d.source_system ? `<span class="pill pill-info text-[10px]"><i class="fa-solid fa-arrow-left mr-0.5"></i>${d.source_system}</span>` : ""}
            ${d.assigned_to_name ? `<span class="pill pill-brass text-[10px]"><i class="fa-solid fa-user mr-0.5"></i>${d.assigned_to_name}</span>` : (d.source === "internal" ? `<span class="pill pill-slate text-[10px]">Unassigned</span>` : "")}
            ${isDeleted ? `<span class="pill pill-maroon text-[10px]"><i class="fa-solid fa-trash mr-0.5"></i>Deleted</span>` : ""}
          </div>
          <p class="text-sm text-ink-900 font-medium">${d.label}</p>
          <p class="text-xs text-slate-500 mt-0.5">Due ${fmtDate(d.due_date)}</p>
          ${d.generation_reason ? `<p class="text-[11px] text-slate-400 italic mt-1"><i class="fa-regular fa-lightbulb mr-1"></i>${d.generation_reason}</p>` : ""}
          ${isCompleted && d.completion_notes ? `<p class="text-[11px] text-forest-700 mt-1"><i class="fa-solid fa-check-double mr-1"></i>${d.source === "computed" ? `Confirmed` : `Completed by ${d.completed_by}`} on ${new Date(d.completed_at).toLocaleDateString()}: "${d.completion_notes}"</p>` : ""}
          ${isCompleted ? `<div id="evidence-${d.id}" class="text-[11px] mt-1"></div>` : ""}
          ${isDeleted ? `<p class="text-[11px] text-maroon-700 mt-1"><i class="fa-solid fa-trash mr-1"></i>Removed by ${d.deleted_by} on ${new Date(d.deleted_at).toLocaleDateString()}: "${d.delete_reason}"</p>` : ""}
        </div>
        <div class="flex flex-col items-end gap-1.5 shrink-0">
          ${countdownChip(diff, isCompleted, reminderLeadDays)}
          ${canMarkComplete ? `<button data-mark-complete="${d.id}" class="text-[11px] text-forest-700 hover:underline"><i class="fa-solid fa-check mr-0.5"></i>Mark complete</button>` : ""}
          ${isAssignedToSomeoneElse && !isCompleted ? `<span class="text-[10px] text-slate-400"><i class="fa-solid fa-lock mr-0.5"></i>Only ${d.assigned_to_name} or an admin can complete this</span>` : ""}
          ${canDelete ? `<button data-delete-deadline="${d.id}" class="text-[11px] text-maroon-700 hover:underline"><i class="fa-solid fa-trash mr-0.5"></i>Delete</button>` : ""}
          ${canRestore ? `<button data-restore-deadline="${d.id}" class="text-[11px] text-forest-700 hover:underline"><i class="fa-solid fa-rotate-left mr-0.5"></i>Restore</button>` : ""}
        </div>
      </div>
    `;
  }).join("") || `<p class="text-sm text-slate-400 text-center py-10">No deadlines match this view.</p>`;

  document.querySelectorAll("[data-mark-complete]").forEach(btn => {
    btn.addEventListener("click", () => openCompleteDeadlineModal(btn.dataset.markComplete));
  });
  document.querySelectorAll("[data-delete-deadline]").forEach(btn => {
    btn.addEventListener("click", () => {
      const id = btn.dataset.deleteDeadline;
      openReasonModal({
        title: `Delete deadline ${id}?`,
        description: "This archives it — an admin can restore it later from \"Show deleted.\" Nothing is permanently destroyed.",
        confirmLabel: "Delete",
        onConfirm: async (reason) => {
          await window.API.post("api/deadlines/delete.php", { id, reason });
          renderDeadlineModule();
        },
      });
    });
  });
  document.querySelectorAll("[data-restore-deadline]").forEach(btn => {
    btn.addEventListener("click", async () => {
      try {
        await window.API.post("api/deadlines/restore.php", { id: btn.dataset.restoreDeadline });
        renderDeadlineModule();
      } catch (err) {
        alert(`Could not restore: ${err.message}`);
      }
    });
  });

  // Show any attached evidence for already-completed items (read-only).
  // Computed (Mayor's window) items store evidence against the real
  // agenda_item id, not the synthetic MAYOR-WINDOW- id — see
  // api/agenda-items/update-mayor-status.php.
  sorted.filter(d => d.status.startsWith("Completed")).forEach(async d => {
    const el = document.getElementById(`evidence-${d.id}`);
    if (!el) return;
    const entityType = d.source === "computed" ? "agenda_item" : "deadline";
    const entityId = d.source === "computed" ? d.related_item_id : d.id;
    const files = await loadEvidenceList(entityType, entityId);
    if (files.length) {
      el.innerHTML = files.map(evidenceFileLink).join(" &middot; ");
    }
  });
}

function renderFilterTabs() {
  const tabs = [
    { key: "all", label: "All" },
    { key: "mine", label: "Assigned to me" },
    { key: "completed-by-me", label: "Completed by me" },
    { key: "mayor-window", label: "Mayor's Action Window" },
  ];
  document.getElementById("deadline-filter-tabs").innerHTML = tabs.map(t => `
    <button data-deadline-filter="${t.key}" class="px-3 py-1.5 rounded-md text-xs font-semibold ${deadlineFilter === t.key ? "bg-ink-800 text-white" : "bg-white border border-[--line-200] text-slate-600"}">
      ${t.label}
    </button>
  `).join("") + (isAdminUser() ? `
    <label class="flex items-center gap-1.5 text-xs text-slate-600 ml-2">
      <input type="checkbox" id="show-deleted-deadlines" ${showDeletedDeadlines ? "checked" : ""} class="rounded border-[--line-200]" /> Show deleted
    </label>
  ` : "");
  document.querySelectorAll("[data-deadline-filter]").forEach(btn => {
    btn.addEventListener("click", () => { deadlineFilter = btn.dataset.deadlineFilter; renderDeadlineModule(); });
  });
  document.getElementById("show-deleted-deadlines")?.addEventListener("change", e => {
    showDeletedDeadlines = e.target.checked;
    renderDeadlineModule();
  });
}

/** Mark Complete modal — evidence note (required) + one optional attached
 * file, uploaded immediately on selection (see uploadEvidenceFile in
 * helpers.js) so the file is already linked to this deadline by the time
 * the completion note is submitted. */
function openCompleteDeadlineModal(deadlineId, notesValue = "", showError = false, pendingAttachment = null, uploadError = null) {
  const deadline = allDeadlines.find(d => d.id === deadlineId);
  if (!deadline) return;

  document.getElementById("complete-modal-root").innerHTML = `
    <div id="complete-deadline-backdrop" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(22,36,61,0.55)">
      <div class="dossier-card w-full max-w-md p-6" style="background:#FBFAF5" onclick="event.stopPropagation()">
        <div class="flex items-start justify-between gap-3 mb-1">
          <div>
            <span class="pill ${deadline.is_statutory ? "pill-maroon" : "pill-slate"}">${deadline.deadline_type}</span>
            <h3 class="font-display text-base text-ink-900 leading-snug mt-2">${deadline.label}</h3>
            <p class="text-xs text-slate-500 mt-0.5">Due ${fmtDate(deadline.due_date)}${deadline.assigned_to_name ? ` &middot; Assigned to ${deadline.assigned_to_name}` : ""}</p>
          </div>
          <button id="complete-deadline-close" class="text-slate-400 hover:text-ink-800 shrink-0"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form id="complete-deadline-form" class="mt-4">
          <label class="block text-xs font-semibold text-slate-600 mb-1">
            How was this completed? <span class="text-maroon-700 font-semibold">— required</span>
          </label>
          <textarea id="complete-deadline-notes" rows="3" placeholder='e.g. "Report submitted by committee secretary, filed Sept 10"'
            class="w-full border ${showError ? "border-maroon-700" : "border-[--line-200]"} rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ink-700/20">${notesValue}</textarea>
          ${showError ? `<p class="text-xs text-maroon-700 mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>A completion note is required.</p>` : ""}

          <label class="block text-xs font-semibold text-slate-600 mb-1 mt-3">
            Attach supporting document <span class="font-normal text-slate-400">(optional — PDF, image, Word, or Excel, max 10MB)</span>
          </label>
          ${pendingAttachment
            ? `<p class="text-xs text-forest-700"><i class="fa-solid fa-circle-check mr-1"></i>Attached: ${pendingAttachment.original_filename}</p>`
            : `<input id="complete-deadline-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" class="w-full text-xs" />`}
          <p id="complete-deadline-upload-status" class="text-[11px] text-slate-400 mt-1"></p>
          ${uploadError ? `<p class="text-xs text-maroon-700 mt-1">${uploadError}</p>` : ""}

          <p id="complete-deadline-error" class="hidden text-xs text-maroon-700 mt-2"></p>

          <div class="mt-5 flex justify-end gap-2">
            <button type="button" id="complete-deadline-cancel" class="btn-outline text-xs">Cancel</button>
            <button type="submit" class="btn-primary text-xs">Mark complete</button>
          </div>
        </form>
      </div>
    </div>
  `;

  document.getElementById("complete-deadline-backdrop").addEventListener("click", closeCompleteDeadlineModal);
  document.getElementById("complete-deadline-close").addEventListener("click", closeCompleteDeadlineModal);
  document.getElementById("complete-deadline-cancel").addEventListener("click", closeCompleteDeadlineModal);

  const fileInput = document.getElementById("complete-deadline-file");
  fileInput?.addEventListener("change", async () => {
    const file = fileInput.files[0];
    if (!file) return;
    const currentNotes = document.getElementById("complete-deadline-notes").value;
    document.getElementById("complete-deadline-upload-status").textContent = "Uploading…";
    try {
      const attachment = await uploadEvidenceFile("deadline", deadlineId, file);
      openCompleteDeadlineModal(deadlineId, currentNotes, false, attachment);
    } catch (err) {
      openCompleteDeadlineModal(deadlineId, currentNotes, false, null, err.message);
    }
  });

  document.getElementById("complete-deadline-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const notes = document.getElementById("complete-deadline-notes").value.trim();
    if (!notes) { openCompleteDeadlineModal(deadlineId, "", true, pendingAttachment); return; }

    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = "Saving…";

    try {
      await window.API.post("api/deadlines/update.php", { id: deadlineId, status: "Completed", completion_notes: notes });
      closeCompleteDeadlineModal();
      renderDeadlineModule();
    } catch (err) {
      submitBtn.disabled = false;
      submitBtn.textContent = "Mark complete";
      const errorEl = document.getElementById("complete-deadline-error");
      errorEl.textContent = err.message;
      errorEl.classList.remove("hidden");
    }
  });
}

function closeCompleteDeadlineModal() {
  document.getElementById("complete-modal-root").innerHTML = "";
}

async function loadDeadlineAgendaItemOptions() {
  const select = document.getElementById("new-deadline-related-item");
  if (!select) return;
  try {
    const items = await window.API.get("api/agenda-items/list.php");
    select.replaceChildren(new Option("Not linked to an agenda item", ""));
    items.forEach(item => {
      select.add(new Option(`${item.id} — ${item.title}`, item.id));
    });
  } catch (err) {
    select.replaceChildren(new Option("Could not load agenda items", ""));
  }
}

document.getElementById("new-deadline-btn")?.addEventListener("click", () => {
  document.getElementById("new-deadline-panel").classList.toggle("hidden");
});

document.getElementById("new-deadline-form")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target));
  const errorEl = document.getElementById("new-deadline-error");
  errorEl.classList.add("hidden");
  try {
    await window.API.post("api/deadlines/create.php", fd);
    e.target.reset();
    document.getElementById("new-deadline-panel").classList.add("hidden");
    renderDeadlineModule();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove("hidden");
  }
});

document.addEventListener("DOMContentLoaded", () => {
  renderDeadlineModule();
  loadDeadlineAgendaItemOptions();
  document.getElementById("reminder-lead").addEventListener("change", e => {
    reminderLeadDays = parseInt(e.target.value, 10) || 3;
    renderDeadlineModule();
  });
  document.getElementById("deadline-sort-select").addEventListener("change", e => {
    deadlineSort = e.target.value;
    renderDeadlineModule();
  });
});
