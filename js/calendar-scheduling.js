let calendarView = "list";
let showPast = false;
let showDeletedSessions = false; // admin only
let allSessions = [];
let availableAgendaItems = [];
const SESSION_TYPES = ["Regular Session", "Special Session", "Committee Hearing", "Public Hearing"];

function sessionLabel(s) {
  // "58th Regular Session" when a sequence number was recorded (matches
  // how the SP's own agenda documents number regular sessions, e.g.
  // "Ika-58 Pangkaraniwang Pulong"); plain type name otherwise.
  if (!s.sequence_number) return s.session_type;
  const n = s.sequence_number;
  const suffix = (n % 10 === 1 && n % 100 !== 11) ? "st"
    : (n % 10 === 2 && n % 100 !== 12) ? "nd"
    : (n % 10 === 3 && n % 100 !== 13) ? "rd" : "th";
  return `${n}${suffix} ${s.session_type}`;
}

async function renderCalendarModule() {
  document.getElementById("view-toggle").innerHTML = ["list", "month"].map(v => `
    <button data-view="${v}" class="px-3 py-1.5 rounded-md text-xs font-semibold ${calendarView === v ? "bg-ink-800 text-white" : "bg-white border border-[--line-200] text-slate-600"}">
      ${v === "list" ? "List" : "Month grid"}
    </button>
  `).join("");
  document.querySelectorAll("[data-view]").forEach(b => b.addEventListener("click", () => { calendarView = b.dataset.view; renderCalendarModule(); }));

  const container = document.getElementById("calendar-body");

  try {
    const query = isAdminUser() && showDeletedSessions ? "?include_deleted=1" : "";
    [allSessions, availableAgendaItems] = await Promise.all([
      window.API.get(`api/sessions/list.php${query}`),
      window.API.get("api/agenda-items/list.php"),
    ]);
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load calendar data: ${err.message}</p>`;
    return;
  }

  container.innerHTML = calendarView === "list" ? renderList() : renderMonthGrid();

  if (calendarView === "month") {
    document.getElementById("month-prev")?.addEventListener("click", () => { monthOffset--; renderCalendarModule(); });
    document.getElementById("month-next")?.addEventListener("click", () => { monthOffset++; renderCalendarModule(); });
  }

  if (calendarView === "list") {
    document.querySelectorAll("[data-complete]").forEach(btn => {
      btn.addEventListener("click", () => openCompleteModal(btn.dataset.complete));
    });
    document.querySelectorAll("[data-delete-session]").forEach(btn => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.deleteSession;
        openReasonModal({
          title: `Delete session ${id}?`,
          description: "This archives it — an admin can restore it later from \"Show deleted.\" Completed sessions can't be removed; nothing is permanently destroyed.",
          confirmLabel: "Delete",
          onConfirm: async (reason) => {
            await window.API.post("api/sessions/delete.php", { id, reason });
            renderCalendarModule();
          },
        });
      });
    });
    document.querySelectorAll("[data-restore-session]").forEach(btn => {
      btn.addEventListener("click", async () => {
        try {
          await window.API.post("api/sessions/restore.php", { id: btn.dataset.restoreSession });
          renderCalendarModule();
        } catch (err) {
          alert(`Could not restore: ${err.message}`);
        }
      });
    });
  }
  document.querySelectorAll("[data-view-details]").forEach(btn => {
    btn.addEventListener("click", () => openDetailModal(btn.dataset.viewDetails));
  });

  const checksEl = document.getElementById("agenda-item-checks");
  if (checksEl) {
    const priorityRank = { High: 0, Medium: 1, Low: 2 };
    // ARCHITECTURE FIX: this used to show every item that wasn't yet
    // transmitted to the Mayor — meaning nothing actually determined which
    // items belonged in a session's agenda. Now it only shows items the
    // (simulated) Agenda Preparation Module has sent us — see
    // api/integration/hub-push-agenda.php and the Integration Hub page.
    const ready = availableAgendaItems
      .filter(i => i.ready_for_scheduling && !i.transmitted_to_mayor_date)
      .sort((a, b) => (priorityRank[a.confirmed_priority] ?? 3) - (priorityRank[b.confirmed_priority] ?? 3));
    checksEl.innerHTML = ready.map(i => `
      <label class="flex items-center gap-2 text-xs text-slate-600 py-1">
        <input type="checkbox" value="${i.id}" class="rounded border-[--line-200]" />
        <span class="font-mono">${i.id}</span> — ${i.title}
        <span class="ml-auto shrink-0">${priorityPill(i.confirmed_priority)}</span>
      </label>
    `).join("") || `<p class="text-xs text-slate-400">No items received from Agenda Preparation yet. <a href="integration-hub.php" class="underline">Push one from the Integration Hub</a>.</p>`;
  }
}

function isPastOrDone(s) {
  return s.status === "Completed" || s.status === "Cancelled" || daysBetween(todayIso(), s.session_date) < 0;
}

function renderList() {
  const visible = showPast ? allSessions : allSessions.filter(s => !isPastOrDone(s) || s.is_deleted);
  if (!visible.length) {
    return `<p class="text-sm text-slate-400 py-10 text-center">${showPast ? "No sessions found." : "Nothing upcoming. Check \u201cShow past/completed\u201d to see history."}</p>`;
  }
  const sorted = [...visible].sort((a, b) => new Date(a.session_date) - new Date(b.session_date));
  return `<div class="space-y-3">${sorted.map(s => {
    const needsStatusUpdate = s.status === "Scheduled" && daysBetween(todayIso(), s.session_date) < 0;
    const canComplete = (s.status === "Scheduled" || s.status === "Rescheduled") && !s.is_deleted;
    return `
      <div class="dossier-card p-5 ${isPastOrDone(s) || s.is_deleted ? "opacity-70" : ""}">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div class="flex items-center gap-2 flex-wrap mb-1">
              <span class="font-mono text-xs text-slate-500">${fmtDate(s.session_date)} &middot; ${s.session_time}</span>
              ${sessionStatusPill(s.status)}
              ${needsStatusUpdate ? `<span class="pill pill-brass"><i class="fa-solid fa-triangle-exclamation"></i>Past — needs status update</span>` : ""}
              ${s.is_deleted ? `<span class="pill pill-maroon"><i class="fa-solid fa-trash mr-0.5"></i>Deleted</span>` : ""}
            </div>
            <h3 class="font-display text-base text-ink-900">${sessionLabel(s)}</h3>
            <p class="text-xs text-slate-500 mt-0.5"><i class="fa-solid fa-location-dot mr-1"></i>${s.venue} &middot; Presiding: ${s.presiding_officer}</p>
            ${s.is_deleted ? `<p class="text-[11px] text-maroon-700 mt-1"><i class="fa-solid fa-trash mr-1"></i>Removed by ${s.deleted_by} on ${new Date(s.deleted_at).toLocaleDateString()}: "${s.delete_reason}"</p>` : ""}
          </div>
          ${s.meeting ? `<div class="text-[11px] text-slate-500 text-right">
            <div><i class="fa-solid fa-user-check mr-1"></i>${s.meeting.attendance_confirmed_text}</div>
            <div><i class="fa-solid fa-file-lines mr-1"></i>Minutes: ${s.meeting.minutes_status}</div>
          </div>` : ""}
        </div>
        <div class="mt-3 flex flex-wrap gap-1.5">
          ${s.agenda_items.length ? s.agenda_items.map(item => `
            <span class="pill pill-slate font-mono" title="${item.title}">${item.id}</span>${priorityPill(item.confirmed_priority)}
          `).join("") : `<span class="text-xs text-slate-400">No agenda items attached yet</span>`}
        </div>
        ${s.agenda_items.length ? `
          <div class="mt-3 flex flex-wrap gap-2">
            <button data-view-details="${s.id}" class="btn-outline text-xs !py-1.5"><i class="fa-solid fa-circle-info mr-1"></i>View details</button>
            ${canComplete ? `<button data-complete="${s.id}" class="btn-outline text-xs !py-1.5"><i class="fa-solid fa-flag-checkered mr-1"></i>Mark as Completed &amp; record readings</button>` : ""}
          </div>
        ` : ""}
        ${isAdminUser() && s.status !== "Completed" && !s.is_deleted ? `
          <div class="mt-3">
            <button data-delete-session="${s.id}" class="text-[11px] text-maroon-700 hover:underline"><i class="fa-solid fa-trash mr-0.5"></i>Delete session</button>
          </div>
        ` : ""}
        ${isAdminUser() && s.is_deleted ? `
          <div class="mt-3">
            <button data-restore-session="${s.id}" class="text-[11px] text-forest-700 hover:underline"><i class="fa-solid fa-rotate-left mr-0.5"></i>Restore</button>
          </div>
        ` : ""}
      </div>
  `;
  }).join("")}</div>`;
}

function isAdminUser() { return isAdminOrAbove(); }

let monthOffset = 0; // 0 = current month, +1 = next month, -1 = previous month

function renderMonthGrid() {
  const base = new Date();
  const target = new Date(base.getFullYear(), base.getMonth() + monthOffset, 1);
  const year = target.getFullYear(), month = target.getMonth();
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const visible = showPast ? allSessions : allSessions.filter(s => !isPastOrDone(s));
  const sessionsByDay = {};
  visible.forEach(s => {
    const d = new Date(s.session_date + "T00:00:00");
    if (d.getFullYear() === year && d.getMonth() === month) {
      (sessionsByDay[d.getDate()] = sessionsByDay[d.getDate()] || []).push(s);
    }
  });

  let cells = "";
  for (let i = 0; i < firstDay; i++) cells += `<div class="border border-[--line-200] bg-paper-100/40 min-h-[92px]"></div>`;
  for (let day = 1; day <= daysInMonth; day++) {
    const list = sessionsByDay[day] || [];
    cells += `
      <div class="border border-[--line-200] bg-[#FBFAF5] min-h-[92px] p-1.5">
        <div class="text-[11px] font-mono text-slate-500">${day}</div>
        <div class="space-y-1 mt-1">
          ${list.map(s => `<div data-view-details="${s.id}" class="cursor-pointer hover:opacity-75 text-[10px] leading-tight px-1.5 py-1 rounded bg-info-100 text-info-700 truncate" title="${sessionLabel(s)} — ${s.venue} (click for details)">${s.session_time.replace(" ", "")} ${s.session_type}</div>`).join("")}
        </div>
      </div>
    `;
  }
  return `
    <div class="flex items-center justify-between mb-3">
      <button id="month-prev" class="btn-outline text-xs !py-1.5 !px-2"><i class="fa-solid fa-chevron-left"></i></button>
      <div class="font-display text-lg text-ink-900">${target.toLocaleDateString("en-US", { month: "long", year: "numeric" })}</div>
      <button id="month-next" class="btn-outline text-xs !py-1.5 !px-2"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
    <div class="grid grid-cols-7 text-center text-[11px] font-semibold text-slate-500 mb-1">
      ${["Sun","Mon","Tue","Wed","Thu","Fri","Sat"].map(d => `<div class="py-1">${d}</div>`).join("")}
    </div>
    <div class="grid grid-cols-7">${cells}</div>
  `;
}

document.getElementById("new-session-form")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);
  const checked = [...form.querySelectorAll("input[type=checkbox]:checked")].map(c => c.value);
  const warningEl = document.getElementById("conflict-warning");
  warningEl.classList.add("hidden");

  const payload = Object.fromEntries(fd);
  payload.agenda_item_ids = checked;

  try {
    const result = await window.API.post("api/sessions/create.php", payload);
    form.reset();
    document.getElementById("new-session-panel").classList.add("hidden");
    await renderCalendarModule();
    if (result.integration_events?.length) revealIntegrationSequence(result.integration_events);
  } catch (err) {
    if (err.status === 409 && err.data?.conflicts) {
      const { conflicts, alternatives } = err.data;
      warningEl.innerHTML = `
        <p class="font-semibold mb-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Scheduling conflict detected:</p>
        <ul class="list-disc list-inside mb-2">${conflicts.map(c => `<li>${c}</li>`).join("")}</ul>
        ${alternatives.length ? `<p>Available times at this venue: <strong>${alternatives.join(", ")}</strong></p>` : `<p>No open slots found that day at this venue between 8 AM–5 PM.</p>`}
        <label class="flex items-center gap-2 mt-2">
          <input type="checkbox" id="override-conflict-check" class="rounded" /> Replace the conflicting schedule(s) (they will be marked Cancelled and kept in history)
        </label>
      `;
      warningEl.classList.remove("hidden");

      document.getElementById("override-conflict-check").addEventListener("change", async (ev) => {
        if (!ev.target.checked) return;
        payload.override_conflicts = true;
        try {
          const result2 = await window.API.post("api/sessions/create.php", payload);
          form.reset();
          document.getElementById("new-session-panel").classList.add("hidden");
          await renderCalendarModule();
          if (result2.sessions_replaced?.length) {
            showHubToast(`Replaced ${result2.sessions_replaced.length} conflicting schedule(s); prior schedule(s) remain in history.`);
          }
          if (result2.integration_events?.length) revealIntegrationSequence(result2.integration_events);
        } catch (err2) {
          alert(`Could not save: ${err2.message}`);
        }
      });
    } else {
      alert(`Could not save: ${err.message}`);
    }
  }
});

const READING_STAGES = ["1st Reading", "2nd Reading", "3rd Reading — Passed", "Committee Report Submitted"];

function openCompleteModal(sessionId, pendingAttachment = null, draft = {}) {
  const session = allSessions.find(s => s.id === sessionId);
  const items = session.agenda_items
    .map(item => availableAgendaItems.find(i => i.id === item.id))
    .filter(Boolean);
  const existingStagesByItem = {}; // item_id -> Set of stages already recorded
  items.forEach(i => { existingStagesByItem[i.id] = new Set((i.readings || []).map(r => r.stage)); });
  const notificationsSent = Number(session.meeting?.notifications_sent) === 1;

  document.getElementById("complete-modal-root").innerHTML = `
    <div id="complete-backdrop" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(22,36,61,0.55)">
      <div class="dossier-card w-full max-w-lg p-6" style="background:#FBFAF5" onclick="event.stopPropagation()">
        <h3 class="font-display text-base text-ink-900 mb-1">Mark ${sessionLabel(session)} as Completed</h3>
        <p class="text-xs text-slate-500 mb-4">For each item, record the reading stage reached in this session — leave as "No action" for items that weren't voted on. This is how the system knows an item passed 3rd reading.</p>
        <p class="dossier-card ${notificationsSent ? "accent-forest text-forest-700" : "accent-maroon text-maroon-700"} p-3 text-xs mb-3">
          <i class="fa-solid ${notificationsSent ? "fa-circle-check" : "fa-triangle-exclamation"} mr-1"></i>
          ${notificationsSent
            ? "Meeting Coordination notification has been sent."
            : "Send the meeting notification in Meeting Coordination before completing this session."}
        </p>
        <form id="complete-form" class="space-y-3">
          ${items.length ? items.map(item => `
            <div class="border border-[--line-200] rounded-lg p-3">
              <p class="text-xs font-mono text-slate-500">${item.id}</p>
              <p class="text-sm text-ink-900 mb-2">${item.title}</p>
              <select name="stage_${item.id}" class="w-full border border-[--line-200] rounded-lg px-2 py-1.5 text-xs">
                <option value="">No action this session</option>
                ${READING_STAGES.map(stage => `
                  <option value="${stage}" ${existingStagesByItem[item.id].has(stage) ? "disabled" : ""}>
                    ${stage}${existingStagesByItem[item.id].has(stage) ? " (already recorded)" : ""}
                  </option>
                `).join("")}
              </select>
            </div>
          `).join("") : `<p class="text-xs text-slate-400">No agenda items attached to this session.</p>`}

          <div class="border-t border-[--line-200] pt-3">
            <label class="block text-xs font-semibold text-slate-600 mb-1">
              What happened at this session? <span class="text-maroon-700 font-semibold">— required</span>
            </label>
            <textarea name="completion_notes" rows="2" placeholder="e.g. Quorum met (10/12); RES-2026-022 passed 2nd reading."
              class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ink-700/20"></textarea>

            <label class="block text-xs font-semibold text-slate-600 mb-1 mt-3">
              Attach supporting document <span class="text-maroon-700 font-semibold">— required</span>
            </label>
            ${pendingAttachment
              ? `<p class="text-xs text-forest-700"><i class="fa-solid fa-circle-check mr-1"></i>Attached: ${pendingAttachment.original_filename}</p>`
              : `<input id="complete-session-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" class="w-full text-xs" required />`}
            <p id="complete-session-upload-status" class="text-[11px] text-slate-400 mt-1"></p>
          </div>

          <p id="complete-error" class="hidden text-xs text-maroon-700"></p>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" id="complete-cancel" class="btn-outline text-xs">Cancel</button>
            <button type="submit" class="btn-primary text-xs">Mark Completed</button>
          </div>
        </form>
      </div>
    </div>
  `;

  const notesInput = document.querySelector('#complete-form textarea[name="completion_notes"]');
  if (notesInput) notesInput.value = draft.completion_notes || "";
  items.forEach(item => {
    const stageInput = document.querySelector(`#complete-form select[name="stage_${item.id}"]`);
    if (stageInput && draft[`stage_${item.id}`]) stageInput.value = draft[`stage_${item.id}`];
  });

  document.getElementById("complete-backdrop").addEventListener("click", closeCompleteModal);
  document.getElementById("complete-cancel").addEventListener("click", closeCompleteModal);

  document.getElementById("complete-session-file")?.addEventListener("change", async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const currentDraft = Object.fromEntries(new FormData(document.getElementById("complete-form")));
    document.getElementById("complete-session-upload-status").textContent = "Uploading…";
    try {
      const attachment = await uploadEvidenceFile("session", sessionId, file);
      openCompleteModal(sessionId, attachment, currentDraft);
    } catch (err) {
      document.getElementById("complete-session-upload-status").textContent = "";
      alert(`Could not attach file: ${err.message}`);
    }
  });

  document.getElementById("complete-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const fd = Object.fromEntries(new FormData(e.target));
    const errorEl = document.getElementById("complete-error");
    errorEl.classList.add("hidden");

    const completionNotes = (fd.completion_notes || "").trim();
    if (!completionNotes) {
      errorEl.textContent = "Please describe what happened at this session before marking it Completed.";
      errorEl.classList.remove("hidden");
      return;
    }
    if (!pendingAttachment) {
      errorEl.textContent = "Please attach a supporting document before marking this session Completed.";
      errorEl.classList.remove("hidden");
      return;
    }
    if (!notificationsSent) {
      errorEl.textContent = "Send the meeting notification in Meeting Coordination before marking this session Completed.";
      errorEl.classList.remove("hidden");
      return;
    }

    try {
      const readingStages = Object.fromEntries(
        items.map(item => [item.id, fd[`stage_${item.id}`] || ""])
      );
      await window.API.post("api/sessions/update.php", {
        id: sessionId,
        status: "Completed",
        completion_notes: completionNotes,
        reading_stages: readingStages,
      });

      closeCompleteModal();
      renderCalendarModule();
    } catch (err) {
      errorEl.textContent = err.message;
      errorEl.classList.remove("hidden");
    }
  });
}

function closeCompleteModal() {
  document.getElementById("complete-modal-root").innerHTML = "";
}

async function openDetailModal(sessionId) {
  const session = allSessions.find(s => s.id === sessionId);
  const fullItems = session.agenda_items
    .map(item => availableAgendaItems.find(i => i.id === item.id))
    .filter(Boolean);
  const completionBlock = session.completed_by ? `
    <div class="dossier-card accent-forest p-3 text-xs mb-4">
      <p class="font-semibold text-forest-700 mb-1"><i class="fa-solid fa-check-double mr-1"></i>Completed by ${session.completed_by}${session.completed_at ? ` on ${new Date(session.completed_at).toLocaleDateString()}` : ""}</p>
      <p class="text-slate-600">${session.completion_notes || ""}</p>
      <div id="session-evidence-${sessionId}" class="mt-1"></div>
    </div>
  ` : "";

  document.getElementById("detail-modal-root").innerHTML = `
    <div id="detail-backdrop" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(22,36,61,0.55)">
      <div class="dossier-card w-full max-w-xl p-6 max-h-[85vh] overflow-y-auto" style="background:#FBFAF5" onclick="event.stopPropagation()">
        <div class="flex items-start justify-between mb-3">
          <h3 class="font-display text-base text-ink-900">${sessionLabel(session)} — ${fmtDate(session.session_date)}</h3>
          <button id="detail-close" class="text-slate-400 hover:text-ink-800"><i class="fa-solid fa-xmark"></i></button>
        </div>

        ${completionBlock}
        <h4 class="text-xs font-semibold text-slate-600 mb-2">Agenda items — full status</h4>
        <div class="space-y-3 mb-5">
          ${fullItems.map(item => `
            <div class="border border-[--line-200] rounded-lg p-3">
              <div class="flex items-center justify-between gap-2 mb-1">
                <span class="font-mono text-xs text-slate-500">${item.id}</span>
                ${priorityPill(item.confirmed_priority)}
              </div>
              <p class="text-sm text-ink-900 mb-2">${item.title}</p>
              <p class="text-[11px] font-semibold text-slate-500 mb-1">Reading progress:</p>
              <div class="flex flex-wrap gap-1.5 mb-2">
                ${(item.readings || []).map(r => `<span class="pill pill-info">${r.stage} — ${fmtDate(r.date)}</span>`).join("") || `<span class="text-xs text-slate-400">No readings recorded yet</span>`}
              </div>
              ${item.transmitted_to_mayor_date ? `
                <p class="text-[11px] font-semibold text-slate-500 mb-1">Mayor status:</p>
                <p class="text-xs text-ink-800">
                  Transmitted ${fmtDate(item.transmitted_to_mayor_date)}
                  ${item.mayor_action ? ` — <strong>${item.mayor_action}</strong>${item.mayor_action_date ? ` (${fmtDate(item.mayor_action_date)})` : ""}` : ` — <span class="text-brass-700">awaiting action</span>`}
                </p>
              ` : `<p class="text-[11px] text-slate-400">Not yet transmitted to the Mayor's office.</p>`}
            </div>
          `).join("") || `<p class="text-xs text-slate-400">No items attached.</p>`}
        </div>

        <h4 class="text-xs font-semibold text-slate-600 mb-2">Integration exchange for this session</h4>
        <div id="detail-integration-events" class="space-y-1.5">
          <p class="text-xs text-slate-400">Loading…</p>
        </div>
      </div>
    </div>
  `;

  document.getElementById("detail-backdrop").addEventListener("click", closeDetailModal);
  document.getElementById("detail-close").addEventListener("click", closeDetailModal);

  if (session.completed_by) {
    loadEvidenceList("session", sessionId).then(files => {
      const el = document.getElementById(`session-evidence-${sessionId}`);
      if (el && files.length) el.innerHTML = files.map(evidenceFileLink).join(" &middot; ");
    });
  }

  try {
    const events = await window.API.get(`api/integration/events.php?session_id=${sessionId}`);
    document.getElementById("detail-integration-events").innerHTML = events.length
      ? events.map(renderEventRow).join("")
      : `<p class="text-xs text-slate-400">No integration events recorded for this session.</p>`;
  } catch (err) {
    document.getElementById("detail-integration-events").innerHTML = `<p class="text-xs text-maroon-700">Could not load: ${err.message}</p>`;
  }
}

function closeDetailModal() {
  document.getElementById("detail-modal-root").innerHTML = "";
}

document.getElementById("toggle-activity-log")?.addEventListener("click", async () => {
  const panel = document.getElementById("activity-log");
  const chevron = document.getElementById("activity-chevron");
  const opening = panel.classList.contains("hidden");
  panel.classList.toggle("hidden");
  chevron.classList.toggle("fa-chevron-right", !opening);
  chevron.classList.toggle("fa-chevron-down", opening);
  if (!opening) return;

  panel.innerHTML = `<p class="text-xs text-slate-400">Loading…</p>`;
  try {
    const events = await window.API.get("api/integration/events.php");
    panel.innerHTML = events.length
      ? `<div class="dossier-card p-4 space-y-2">${events.map(renderEventRow).join("")}</div>`
      : `<p class="text-xs text-slate-400">No integration activity recorded yet — schedule a session to generate some.</p>`;
  } catch (err) {
    panel.innerHTML = `<p class="text-xs text-maroon-700">Could not load: ${err.message}</p>`;
  }
});

/** Reveals the just-happened send/receive sequence right after scheduling,
 * one row at a time with a short delay — so it's watched happening live,
 * not just trusted to have happened. */
async function revealIntegrationSequence(events) {
  const panel = document.getElementById("integration-live");
  panel.classList.remove("hidden");
  panel.innerHTML = `<p class="text-xs font-semibold text-forest-700 mb-2"><i class="fa-solid fa-tower-broadcast mr-1"></i>Integration exchange in progress…</p><div id="integration-live-rows" class="space-y-1.5"></div>`;
  const rows = document.getElementById("integration-live-rows");

  for (const ev of events) {
    await new Promise(r => setTimeout(r, 600));
    const row = document.createElement("div");
    row.innerHTML = renderEventRow(ev);
    row.style.opacity = "0";
    rows.appendChild(row.firstElementChild);
    requestAnimationFrame(() => { rows.lastElementChild.style.transition = "opacity 0.4s"; rows.lastElementChild.style.opacity = "1"; });
  }

  setTimeout(() => panel.classList.add("hidden"), 6000);
}

document.getElementById("show-past")?.addEventListener("change", (e) => {
  showPast = e.target.checked;
  renderCalendarModule();
});

document.getElementById("show-deleted-sessions")?.addEventListener("change", (e) => {
  showDeletedSessions = e.target.checked;
  renderCalendarModule();
});

document.addEventListener("DOMContentLoaded", renderCalendarModule);

document.getElementById("ai-schedule-btn")?.addEventListener("click", async () => {
  const form = document.getElementById("new-session-form");
  const button = document.getElementById("ai-schedule-btn");
  const output = document.getElementById("schedule-suggestions");
  const agendaItemIds = [...form.querySelectorAll("#agenda-item-checks input[type=checkbox]:checked")].map(input => input.value);
  const venue = form.elements.venue.value.trim();
  const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);

  if (!agendaItemIds.length) {
    alert("Select at least one agenda item before asking for date suggestions.");
    return;
  }
  if (!venue) {
    alert("Enter a venue before asking for date suggestions.");
    form.elements.venue.focus();
    return;
  }

  const original = button.innerHTML;
  button.disabled = true;
  button.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Thinking…';
  output.classList.remove("hidden", "accent-maroon");
  output.textContent = "Checking availability and asking the AI to rank the available dates…";

  try {
    const result = await window.API.post("api/ai/suggest-schedule.php", {
      agenda_item_ids: agendaItemIds,
      date_from: form.elements.date.value || todayIso(),
      time: form.elements.time.value,
      type: form.elements.type.value,
      venue,
      committee: form.elements.committee.value,
      presiding_officer: form.elements.presiding_officer.value,
    });

    output.innerHTML = `
      <p class="font-semibold text-forest-700 mb-2"><i class="fa-solid fa-wand-magic-sparkles mr-1"></i>Suggested dates</p>
      <div class="space-y-2">
        ${result.suggestions.map(s => `
          <div class="flex items-start justify-between gap-3 border border-[--line-200] rounded-lg bg-white p-2">
            <p><strong>${escapeHtml(fmtDate(s.date))} at ${escapeHtml(s.time)}</strong><br><span class="text-slate-600">${escapeHtml(s.reasoning)}</span></p>
            <button type="button" data-use-schedule-date="${escapeHtml(s.date)}" data-use-schedule-time="${escapeHtml(s.time)}" class="btn-outline text-[11px] !py-1 !px-2 whitespace-nowrap">Use date</button>
          </div>
        `).join("")}
      </div>
      <p class="text-[11px] text-slate-400 mt-2">These are suggestions only. Review the agenda and save the session explicitly.</p>
    `;
    output.querySelectorAll("[data-use-schedule-date]").forEach(useButton => {
      useButton.addEventListener("click", () => {
        form.elements.date.value = useButton.dataset.useScheduleDate;
        form.elements.time.value = useButton.dataset.useScheduleTime;
        output.querySelectorAll("[data-use-schedule-date]").forEach(other => other.classList.remove("btn-primary"));
        useButton.classList.add("btn-primary");
      });
    });
  } catch (err) {
    output.textContent = `Could not generate date suggestions: ${err.message}`;
    output.classList.add("accent-maroon");
  } finally {
    button.disabled = false;
    button.innerHTML = original;
  }
});
