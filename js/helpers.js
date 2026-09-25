/* ==========================================================================
   Shared display helpers: date formatting + the small recurring badges
   (priority pill, session status pill, countdown chip).

   NOTE: the old "route slip" stamp stepper and mayor veto/override status
   rendering lived here because they were used by executive-sync.js, which
   no longer exists (see js/nav.js for why). Deadline Tracking still shows
   the Mayor's-action-window countdown, but the underlying days-remaining
   and status computation now happens server-side (api/deadlines/list.php),
   so the client just displays what the API already computed.
   ========================================================================== */

function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso + "T00:00:00");
  return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}

function isAdminOrAbove() {
  return ["admin", "superadmin"].includes(window.CURRENT_USER?.role);
}

function priorityPill(priority) {
  if (!priority) return `<span class="pill pill-slate"><i class="fa-regular fa-circle-question"></i>Unset</span>`;
  const map = {
    High:   `<span class="pill pill-maroon"><i class="fa-solid fa-arrow-up"></i>High</span>`,
    Medium: `<span class="pill pill-brass"><i class="fa-solid fa-minus"></i>Medium</span>`,
    Low:    `<span class="pill pill-forest"><i class="fa-solid fa-arrow-down"></i>Low</span>`,
  };
  return map[priority] || `<span class="pill pill-slate">${priority}</span>`;
}

function sessionStatusPill(status) {
  const map = {
    "Scheduled":   `<span class="pill pill-info"><i class="fa-regular fa-calendar-check"></i>Scheduled</span>`,
    "Rescheduled": `<span class="pill pill-brass"><i class="fa-solid fa-rotate"></i>Rescheduled</span>`,
    "Completed":   `<span class="pill pill-forest"><i class="fa-solid fa-check"></i>Completed</span>`,
    "Cancelled":   `<span class="pill pill-maroon"><i class="fa-solid fa-xmark"></i>Cancelled</span>`,
  };
  return map[status] || `<span class="pill pill-slate">${status}</span>`;
}

/** Countdown chip for a deadline row. `daysLeft` may be negative (overdue). */
function countdownChip(daysLeft, isCompleted, reminderLeadDays) {
  if (isCompleted) return `<span class="countdown-chip is-ok"><i class="fa-solid fa-check mr-1"></i>Completed</span>`;
  if (daysLeft < 0) return `<span class="countdown-chip is-urgent"><i class="fa-solid fa-triangle-exclamation mr-1"></i>${Math.abs(daysLeft)}d overdue</span>`;
  if (daysLeft <= reminderLeadDays) return `<span class="countdown-chip is-urgent"><i class="fa-regular fa-bell mr-1"></i>Due in ${daysLeft}d</span>`;
  return `<span class="countdown-chip"><i class="fa-regular fa-clock mr-1"></i>Due in ${daysLeft}d</span>`;
}

function daysBetween(a, b) {
  const A = new Date(a + "T00:00:00");
  const B = new Date(b + "T00:00:00");
  return Math.round((B - A) / 86400000);
}

/** Small dismissible toast, bottom-right, linking to the Integration Hub —
 * used to make "this data now flows elsewhere" visible right when it happens. */
function showHubToast(message) {
  let container = document.getElementById("hub-toast-container");
  if (!container) {
    container = document.createElement("div");
    container.id = "hub-toast-container";
    container.className = "fixed bottom-4 right-4 z-50 space-y-2";
    document.body.appendChild(container);
  }
  const toast = document.createElement("div");
  toast.className = "dossier-card accent-forest p-3 text-xs max-w-xs shadow-lg";
  toast.style.background = "#FBFAF5";
  toast.innerHTML = `
    <p class="text-ink-800">${message}</p>
    <a href="integration-hub.php" class="text-forest-700 font-semibold hover:underline"><i class="fa-solid fa-diagram-project mr-1"></i>View in Integration Hub</a>
  `;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 8000);
}

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

/**
 * Compact reading-progress display — this is the shared answer to "how do
 * other modules know if an item passed 1st/2nd/3rd reading": every module
 * that has access to an item's `readings` array (from
 * api/agenda-items/list.php) can render this same stepper, instead of that
 * information being visible only inside Calendar Scheduling's detail modal.
 */
const READING_ORDER = ["Filed", "1st Reading", "2nd Reading", "3rd Reading — Passed"];

function renderReadingProgress(readings) {
  const reached = new Set((readings || []).map(r => r.stage));
  const latest = [...(readings || [])].sort((a, b) => new Date(b.date) - new Date(a.date))[0];

  const steps = READING_ORDER.map(stage => {
    const done = reached.has(stage);
    const entry = (readings || []).find(r => r.stage === stage);
    return `<span class="inline-flex items-center gap-1 ${done ? "text-forest-700" : "text-slate-300"}" title="${done ? stage + ' — ' + fmtDate(entry.date) : stage + ' — not yet reached'}">
      <i class="fa-solid ${done ? "fa-circle-check" : "fa-circle"} text-[10px]"></i>
    </span>`;
  }).join(`<span class="text-slate-200 text-[10px]">—</span>`);

  return `
    <div class="flex items-center gap-1 text-[10px]">
      ${steps}
      ${latest ? `<span class="ml-1.5 text-slate-500">${latest.stage} (${fmtDate(latest.date)})</span>` : `<span class="ml-1.5 text-slate-400">No readings yet</span>`}
    </div>
  `;
}

/** Uploads one evidence file for a deadline, session, or Mayor-action
 * confirmation via
 * multipart/form-data — deliberately NOT routed through window.API, since
 * that helper always sends JSON. CSRF is still enforced the same way
 * (custom header), just built manually here. */
async function uploadEvidenceFile(entityType, entityId, file) {
  const prefix = window.API_PREFIX || '';
  const fd = new FormData();
  fd.append('entity_type', entityType);
  fd.append('entity_id', entityId);
  fd.append('file', file);

  const res = await fetch(prefix + 'api/evidence/upload.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' },
    body: fd,
  });

  if (res.status === 401) {
    const base = window.APP_BASE_PATH || '';
    window.location.href = base + '/index.php?reason=session_expired';
    return null;
  }

  const json = await res.json().catch(() => null);
  if (!res.ok || !json || json.success !== true) {
    throw new Error((json && json.error) || `Upload failed (HTTP ${res.status}).`);
  }
  return json.data;
}

async function loadEvidenceList(entityType, entityId) {
  try {
    return await window.API.get(`api/evidence/list.php?entity_type=${entityType}&entity_id=${encodeURIComponent(entityId)}`);
  } catch (err) {
    return [];
  }
}

function evidenceFileLink(att) {
  const prefix = window.API_PREFIX || '';
  const safeName = String(att.original_filename || "evidence download").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
  }[character]));
  const safeId = encodeURIComponent(String(att.id || ""));
  return `<a href="${prefix}api/evidence/download.php?id=${safeId}" target="_blank" rel="noopener" class="text-info-700 hover:underline"><i class="fa-solid fa-paperclip mr-1"></i>${safeName}</a>`;
}

/** Small reusable "type a reason, then confirm" modal for admin
 * corrective actions (e.g. undoing a mistaken notification send). Avoids
 * a bare browser prompt() for anything that needs real validation. */
function openReasonModal({ title, description, confirmLabel = 'Confirm', onConfirm }) {
  const rootId = 'reason-modal-root-' + Math.random().toString(36).slice(2, 8);
  const root = document.createElement('div');
  root.id = rootId;
  document.body.appendChild(root);

  function close() { root.remove(); }

  function render(showError) {
    root.innerHTML = `
      <div class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(22,36,61,0.55)" id="${rootId}-backdrop">
        <div class="dossier-card w-full max-w-md p-6" style="background:#FBFAF5" onclick="event.stopPropagation()">
          <h3 class="font-display text-base text-ink-900 mb-1">${title}</h3>
          ${description ? `<p class="text-xs text-slate-500 mb-3">${description}</p>` : ''}
          <textarea id="${rootId}-reason" rows="3" placeholder="Explain why…"
            class="w-full border ${showError ? 'border-maroon-700' : 'border-[--line-200]'} rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ink-700/20"></textarea>
          ${showError ? `<p class="text-xs text-maroon-700 mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>A reason is required.</p>` : ''}
          <p id="${rootId}-error" class="hidden text-xs text-maroon-700 mt-2"></p>
          <div class="mt-4 flex justify-end gap-2">
            <button type="button" id="${rootId}-cancel" class="btn-outline text-xs">Cancel</button>
            <button type="button" id="${rootId}-submit" class="btn-primary text-xs">${confirmLabel}</button>
          </div>
        </div>
      </div>
    `;
    document.getElementById(`${rootId}-backdrop`).addEventListener('click', close);
    document.getElementById(`${rootId}-cancel`).addEventListener('click', close);
    document.getElementById(`${rootId}-submit`).addEventListener('click', async () => {
      const reason = document.getElementById(`${rootId}-reason`).value.trim();
      if (!reason) { render(true); return; }
      const btn = document.getElementById(`${rootId}-submit`);
      btn.disabled = true;
      btn.textContent = 'Saving…';
      try {
        await onConfirm(reason);
        close();
      } catch (err) {
        btn.disabled = false;
        btn.textContent = confirmLabel;
        const errEl = document.getElementById(`${rootId}-error`);
        errEl.textContent = err.message;
        errEl.classList.remove('hidden');
      }
    });
  }

  render(false);
}
function renderEventRow(ev) {
  const icon = ev.direction === "outbound" ? "fa-arrow-right" : "fa-arrow-left";
  const color = ev.direction === "outbound" ? "text-info-700" : "text-forest-700";
  return `
    <div class="flex items-start gap-2 text-xs">
      <i class="fa-solid ${icon} ${color} mt-0.5"></i>
      <div class="flex-1 min-w-0">
        <p class="text-ink-800">${ev.summary}</p>
        <p class="text-[10px] text-slate-400">${new Date(ev.created_at).toLocaleString()}</p>
      </div>
    </div>
  `;
}
