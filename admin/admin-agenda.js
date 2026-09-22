let showArchived = false;
let adminItems = [];

async function renderAgendaAdmin() {
  const container = document.getElementById("agenda-admin-list");
  try {
    adminItems = await window.API.get(`api/agenda-items/list.php${showArchived ? "?include_archived=1" : ""}`);
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load: ${err.message}</p>`;
    return;
  }

  container.innerHTML = adminItems.map(item => `
    <div class="dossier-card p-4 ${item.is_archived ? "opacity-60" : ""}">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1">
            <span class="font-mono text-xs text-slate-500">${item.id}</span>
            <span class="pill pill-slate">${item.item_type}</span>
            ${item.is_archived ? `<span class="pill pill-maroon">Archived</span>` : ""}
          </div>
          <h3 class="text-sm font-semibold text-ink-900">${item.title}</h3>
          <p class="text-xs text-slate-500 mt-0.5">${item.committee} &middot; ${item.submitted_by}</p>
        </div>
        <div class="flex gap-2 shrink-0">
          <button data-edit="${item.id}" class="btn-outline text-xs !py-1.5">Edit</button>
          ${!item.is_archived ? `<button data-archive="${item.id}" class="btn-outline text-xs !py-1.5 !border-maroon-600 !text-maroon-700">Archive</button>` : ""}
        </div>
      </div>
    </div>
  `).join("") || `<p class="text-sm text-slate-400 text-center py-10">No items.</p>`;

  document.querySelectorAll("[data-edit]").forEach(btn => btn.addEventListener("click", () => openEditModal(btn.dataset.edit)));
  document.querySelectorAll("[data-archive]").forEach(btn => btn.addEventListener("click", async () => {
    if (!confirm(`Archive ${btn.dataset.archive}? It will be hidden from staff views but the record (readings, priority history) is preserved.`)) return;
    try {
      await window.API.post("api/agenda-items/archive.php", { id: btn.dataset.archive });
      renderAgendaAdmin();
    } catch (err) {
      alert(`Could not archive: ${err.message}`);
    }
  }));
}

function openEditModal(itemId) {
  const item = adminItems.find(i => i.id === itemId);
  const mayorActionNotes = String(item.mayor_action_notes || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
  document.getElementById("edit-modal-root").innerHTML = `
    <div id="edit-backdrop" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(22,36,61,0.55)">
      <div class="dossier-card w-full max-w-lg p-6" style="background:#FBFAF5" onclick="event.stopPropagation()">
        <h3 class="font-display text-base text-ink-900 mb-3">${item.id} — Edit record</h3>
        <form id="edit-form" class="space-y-3">
          <div><label class="block text-xs font-semibold text-slate-600 mb-1">Title</label>
            <input name="title" value="${item.title.replace(/"/g, '&quot;')}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-xs font-semibold text-slate-600 mb-1">Committee</label>
              <input name="committee" value="${item.committee.replace(/"/g, '&quot;')}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
            <div><label class="block text-xs font-semibold text-slate-600 mb-1">Submitted by</label>
              <input name="submitted_by" value="${item.submitted_by.replace(/"/g, '&quot;')}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
          </div>

          <hr class="border-[--line-200]" />
          <p class="text-xs font-semibold text-slate-600">Mayor status (from Backstopping Committee — manual entry)</p>
           <div class="grid grid-cols-2 gap-3">
             <div><label class="block text-xs text-slate-600 mb-1">Transmitted to Mayor</label>
               <input type="date" name="transmitted_to_mayor_date" value="${item.transmitted_to_mayor_date || ""}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
             <div><label class="block text-xs text-slate-600 mb-1">Mayor action</label>
               <select name="mayor_action" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option value="">— none yet —</option>
                <option value="Signed" ${item.mayor_action === "Signed" ? "selected" : ""}>Signed</option>
                <option value="Vetoed" ${item.mayor_action === "Vetoed" ? "selected" : ""}>Vetoed</option>
                <option value="Deemed Approved" ${item.mayor_action === "Deemed Approved" ? "selected" : ""}>Deemed Approved</option>
               </select></div>
           </div>

           <div class="grid grid-cols-2 gap-3">
             <div><label class="block text-xs text-slate-600 mb-1">Mayor action date</label>
               <input type="date" name="mayor_action_date" value="${item.mayor_action_date || ""}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" /></div>
             <div>
               <label class="block text-xs text-slate-600 mb-1">How was this confirmed? <span class="text-maroon-700">— required when an action is selected</span></label>
               <textarea name="mayor_action_notes" rows="2" maxlength="500" placeholder="e.g. Signed copy received; Backstopping Committee report filed."
                 class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">${mayorActionNotes}</textarea>
             </div>
           </div>

           <p id="edit-error" class="hidden text-xs text-maroon-700"></p>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" id="edit-cancel" class="btn-outline text-xs">Cancel</button>
            <button type="submit" class="btn-primary text-xs">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  `;

  document.getElementById("edit-backdrop").addEventListener("click", closeEditModal);
  document.getElementById("edit-cancel").addEventListener("click", closeEditModal);

  const mayorActionInput = document.querySelector('#edit-form select[name="mayor_action"]');
  const mayorActionDateInput = document.querySelector('#edit-form input[name="mayor_action_date"]');
  const mayorActionNotesInput = document.querySelector('#edit-form textarea[name="mayor_action_notes"]');
  const syncMayorEvidenceRequirements = () => {
    const hasAction = mayorActionInput.value !== "";
    mayorActionDateInput.required = hasAction;
    mayorActionNotesInput.required = hasAction;
  };
  mayorActionInput.addEventListener("change", syncMayorEvidenceRequirements);
  syncMayorEvidenceRequirements();

  document.getElementById("edit-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const fd = Object.fromEntries(new FormData(e.target));
    const errorEl = document.getElementById("edit-error");
    errorEl.classList.add("hidden");
    if (fd.mayor_action && !String(fd.mayor_action_notes || "").trim()) {
      errorEl.textContent = "Please add a short note explaining how the Mayor's action was confirmed.";
      errorEl.classList.remove("hidden");
      return;
    }
    try {
      await window.API.post("api/agenda-items/update.php", { id: item.id, title: fd.title, committee: fd.committee, submitted_by: fd.submitted_by });
      await window.API.post("api/agenda-items/update-mayor-status.php", {
         item_id: item.id,
         transmitted_to_mayor_date: fd.transmitted_to_mayor_date || null,
         mayor_action: fd.mayor_action || null,
         mayor_action_date: fd.mayor_action_date || null,
         mayor_action_notes: fd.mayor_action_notes || "",
       });
      closeEditModal();
      renderAgendaAdmin();
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.classList.remove("hidden");
    }
  });
}

function closeEditModal() {
  document.getElementById("edit-modal-root").innerHTML = "";
}

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

document.getElementById("show-archived").addEventListener("change", (e) => {
  showArchived = e.target.checked;
  renderAgendaAdmin();
});

document.addEventListener("DOMContentLoaded", renderAgendaAdmin);
