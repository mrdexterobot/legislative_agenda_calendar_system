let priorityFilter = "all";
let allItems = [];
let modalState = { itemId: null, selectedLevel: null };

async function renderPriorityModule() {
  try {
    allItems = await window.API.get("api/agenda-items/list.php");
  } catch (err) {
    document.getElementById("priority-list").innerHTML = `<p class="text-sm text-maroon-700">Could not load agenda items: ${err.message}</p>`;
    return;
  }

  let items = [...allItems];
  if (priorityFilter === "awaiting") items = items.filter(i => !i.confirmed_priority);
  else if (priorityFilter !== "all") items = items.filter(i => i.confirmed_priority === priorityFilter);

  document.getElementById("filter-tabs").innerHTML = ["all", "awaiting", "High", "Medium", "Low"].map(f => `
    <button data-filter="${f}" class="filter-btn px-3 py-1.5 rounded-md text-xs font-semibold ${priorityFilter === f ? "bg-ink-800 text-white" : "bg-white border border-[--line-200] text-slate-600"}">
      ${f === "all" ? "All items" : f === "awaiting" ? "Awaiting confirmation" : f}
    </button>
  `).join("");

  document.getElementById("priority-list").innerHTML = items.length ? items.map(item => {
    const autoFlag = item.category === "Emergency" || item.category === "Budget";
    const history = item.priority_history || [];
    const lastHistory = history.length ? history[history.length - 1] : null;

    return `
    <div class="dossier-card ${item.confirmed_priority ? "" : "accent-brass"} p-5">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1">
            <span class="font-mono text-xs text-slate-500">${item.id}</span>
            <span class="pill pill-slate">${item.item_type}</span>
            ${autoFlag ? `<span class="pill pill-maroon"><i class="fa-solid fa-bolt"></i>${item.category} — auto-flagged</span>` : ""}
          </div>
          <h3 class="font-display text-base text-ink-900 leading-snug">${item.title}</h3>
          <p class="text-xs text-slate-500 mt-1">${item.committee} &middot; filed by ${item.submitted_by} &middot; ${fmtDate(item.date_filed)}</p>
          <div class="mt-1.5">${renderReadingProgress(item.readings)}</div>
        </div>
        <div class="text-right shrink-0">${priorityPill(item.confirmed_priority)}</div>
      </div>

      <div class="mt-4 bg-paper-100/70 border border-[--line-200] rounded-lg p-3">
        ${item.ai_suggested_priority ? `
          <div class="flex items-center gap-2 text-xs font-semibold text-ink-800 mb-1">
            <i class="fa-solid fa-wand-magic-sparkles text-brass-700"></i> AI-suggested priority: ${item.ai_suggested_priority}
          </div>
          <p class="text-xs text-slate-600">${item.ai_suggested_reasoning}</p>
        ` : `
          <div class="flex items-center justify-between gap-2">
            <p class="text-xs text-slate-500"><i class="fa-regular fa-circle-question mr-1"></i>No AI suggestion generated yet.</p>
            <button data-ai-suggest="${item.id}" class="btn-outline text-[11px] !py-1 !px-2"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i>Generate AI suggestion</button>
          </div>
        `}
      </div>

      ${item.priority_notes ? `
        <p class="text-xs text-slate-600 italic mt-3"><i class="fa-regular fa-message mr-1"></i>"${item.priority_notes}"</p>
      ` : ""}
      ${lastHistory ? `
        <p class="text-[11px] text-slate-400 mt-2"><i class="fa-regular fa-clock-rotate-left mr-1"></i>Previously ${lastHistory.priority} — confirmed by ${lastHistory.by}, ${fmtDate(lastHistory.date)}</p>
      ` : ""}

      <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
        <button data-open-modal="${item.id}" class="${item.confirmed_priority ? "btn-outline" : "btn-primary"} text-xs !py-2">
          <i class="fa-solid ${item.confirmed_priority ? "fa-pen" : "fa-clipboard-check"} mr-1.5"></i>
          ${item.confirmed_priority ? "Change priority" : "Review & confirm priority"}
        </button>
        <div class="text-[11px] text-slate-500 text-right">
          ${item.confirmed_priority
            ? `<i class="fa-solid fa-user-check mr-1"></i>Confirmed by ${item.priority_confirmed_by} &middot; ${fmtDate(item.priority_confirmed_date)}`
            : `<i class="fa-regular fa-clock mr-1"></i>Awaiting staff confirmation`}
        </div>
      </div>
    </div>
  `;
  }).join("") : `<p class="text-sm text-slate-500 text-center py-10">No items match this filter.</p>`;

  document.querySelectorAll(".filter-btn").forEach(btn => {
    btn.addEventListener("click", () => { priorityFilter = btn.dataset.filter; renderPriorityModule(); });
  });
  document.querySelectorAll("[data-open-modal]").forEach(btn => {
    btn.addEventListener("click", () => openPriorityModal(btn.dataset.openModal));
  });
  document.querySelectorAll("[data-ai-suggest]").forEach(btn => {
    btn.addEventListener("click", () => triggerAISuggestion(btn.dataset.aiSuggest, btn));
  });
}

async function triggerAISuggestion(itemId, btn) {
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-1"></i>Asking AI… (may take a few seconds)`;
  try {
    await window.API.post("api/ai/suggest-priority.php", { item_id: itemId });
    renderPriorityModule();
  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = original;
    alert(`Could not get an AI suggestion: ${err.message}`);
  }
}

function openPriorityModal(itemId) {
  const item = allItems.find(i => i.id === itemId);
  modalState = { itemId, selectedLevel: item.confirmed_priority || item.ai_suggested_priority || "Medium" };
  renderModal("");
}

function closePriorityModal() {
  document.getElementById("priority-modal-root").innerHTML = "";
}

function levelBtnClasses(level, selected) {
  if (level !== selected) return "bg-white border-[--line-200] text-slate-600 hover:border-ink-700";
  const map = {
    High: "bg-maroon-100 border-maroon-700 text-maroon-700",
    Medium: "bg-brass-100 border-brass-700 text-brass-700",
    Low: "bg-forest-100 border-forest-700 text-forest-700",
  };
  return map[level];
}

function renderModal(notesValue, showError) {
  const item = allItems.find(i => i.id === modalState.itemId);
  const selected = modalState.selectedLevel;
  const differsFromAI = item.ai_suggested_priority && selected !== item.ai_suggested_priority;

  document.getElementById("priority-modal-root").innerHTML = `
    <div id="modal-backdrop" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(22,36,61,0.55)">
      <div class="dossier-card w-full max-w-lg p-6" style="background:#FBFAF5" onclick="event.stopPropagation()">
        <div class="flex items-start justify-between gap-3 mb-1">
          <div>
            <span class="font-mono text-xs text-slate-500">${item.id}</span>
            <h3 class="font-display text-lg text-ink-900 leading-snug mt-0.5">${item.title}</h3>
          </div>
          <button id="modal-close" class="text-slate-400 hover:text-ink-800 shrink-0"><i class="fa-solid fa-xmark"></i></button>
        </div>

        ${item.ai_suggested_priority ? `
          <div class="mt-3 bg-paper-100/70 border border-[--line-200] rounded-lg p-3">
            <div class="flex items-center gap-2 text-xs font-semibold text-ink-800 mb-1">
              <i class="fa-solid fa-wand-magic-sparkles text-brass-700"></i> AI-suggested priority: ${item.ai_suggested_priority}
            </div>
            <p class="text-xs text-slate-600">${item.ai_suggested_reasoning}</p>
          </div>
        ` : `<p class="mt-3 text-xs text-slate-500">No AI suggestion was generated for this item.</p>`}

        <div class="mt-4">
          <label class="block text-xs font-semibold text-slate-600 mb-2">Set priority level</label>
          <div class="flex gap-2">
            ${["High", "Medium", "Low"].map(level => `
              <button type="button" data-level="${level}" class="modal-level-btn flex-1 px-3 py-2 rounded-lg text-sm font-semibold border-2 transition-colors ${levelBtnClasses(level, selected)}">
                ${level}
              </button>
            `).join("")}
          </div>
        </div>

        <div class="mt-4">
          <label class="block text-xs font-semibold text-slate-600 mb-1">
            Notes
            ${differsFromAI
              ? `<span class="text-maroon-700 font-semibold">— required, you're changing this from the AI's suggestion</span>`
              : `<span class="text-slate-400 font-normal">(optional)</span>`}
          </label>
          <textarea id="modal-notes" rows="3" placeholder="Why this level? e.g., affects 3 barangays, statutory deadline in 2 weeks"
            class="w-full border ${showError ? "border-maroon-700" : "border-[--line-200]"} rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ink-700/20">${notesValue}</textarea>
          ${showError ? `<p class="text-xs text-maroon-700 mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Please add a short note explaining the change before confirming.</p>` : ""}
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button id="modal-cancel" class="btn-outline text-xs">Cancel</button>
          <button id="modal-submit" class="btn-primary text-xs">Confirm priority</button>
        </div>
      </div>
    </div>
  `;

  document.getElementById("modal-backdrop").addEventListener("click", closePriorityModal);
  document.getElementById("modal-close").addEventListener("click", closePriorityModal);
  document.getElementById("modal-cancel").addEventListener("click", closePriorityModal);

  document.querySelectorAll(".modal-level-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const currentNotes = document.getElementById("modal-notes").value;
      modalState.selectedLevel = btn.dataset.level;
      renderModal(currentNotes);
    });
  });

  document.getElementById("modal-submit").addEventListener("click", async () => {
    const notes = document.getElementById("modal-notes").value.trim();
    const stillDiffers = item.ai_suggested_priority && modalState.selectedLevel !== item.ai_suggested_priority;
    if (stillDiffers && !notes) { renderModal(notes, true); return; }

    const submitBtn = document.getElementById("modal-submit");
    submitBtn.disabled = true;
    submitBtn.textContent = "Saving…";

    try {
      await window.API.post("api/agenda-items/confirm-priority.php", {
        item_id: modalState.itemId,
        priority: modalState.selectedLevel,
        notes,
      });
      closePriorityModal();
      renderPriorityModule();
      showHubToast(`Priority confirmed for ${item.id}. This is now visible to the Agenda Preparation Module.`);
    } catch (err) {
      submitBtn.disabled = false;
      submitBtn.textContent = "Confirm priority";
      alert(`Could not save: ${err.message}`);
    }
  });
}

document.getElementById("new-item-form")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const errorEl = document.getElementById("new-item-error");
  errorEl.classList.add("hidden");
  try {
    await window.API.post("api/agenda-items/create.php", Object.fromEntries(fd));
    e.target.reset();
    document.getElementById("new-item-panel").classList.add("hidden");
    renderPriorityModule();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove("hidden");
  }
});

document.addEventListener("DOMContentLoaded", renderPriorityModule);
