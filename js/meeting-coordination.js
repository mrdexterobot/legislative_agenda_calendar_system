let meetingFilter = "to-send"; // to-send | sent | completed
let meetingSort = "session_date"; // session_date | readiness | status

function renderMeetingFilterTabs() {
  const tabs = [
    { key: "to-send", label: "To be sent" },
    { key: "sent", label: "Sent" },
    { key: "completed", label: "Completed" },
  ];
  document.getElementById("meeting-filter-tabs").innerHTML = tabs.map(t => `
    <button data-meeting-filter="${t.key}" class="px-3 py-1.5 rounded-md text-xs font-semibold ${meetingFilter === t.key ? "bg-ink-800 text-white" : "bg-white border border-[--line-200] text-slate-600"}">
      ${t.label}
    </button>
  `).join("");
  document.querySelectorAll("[data-meeting-filter]").forEach(btn => {
    btn.addEventListener("click", () => { meetingFilter = btn.dataset.meetingFilter; renderMeetingModule(); });
  });
}

async function renderMeetingModule() {
  const container = document.getElementById("meeting-list");
  let meetings;
  try {
    meetings = await window.API.get("api/meetings/list.php");
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load meetings: ${err.message}</p>`;
    return;
  }

  renderMeetingFilterTabs();

  let visible = [...meetings];
  if (meetingFilter === "to-send") {
    visible = visible.filter(m => !["Completed", "Cancelled"].includes(m.status) && !m.notifications_sent);
  } else if (meetingFilter === "sent") {
    visible = visible.filter(m => !["Completed", "Cancelled"].includes(m.status) && m.notifications_sent);
  } else if (meetingFilter === "completed") {
    visible = visible.filter(m => m.status === "Completed");
  }

  const readinessScore = m => {
    const checks = [m.venue_booked, m.documents_distributed, m.minutes_status === "Finalized", m.attendees_confirmed, m.agenda_confirmed];
    return checks.filter(Boolean).length;
  };
  const sorters = {
    session_date: (a, b) => new Date(a.session_date) - new Date(b.session_date),
    readiness:    (a, b) => readinessScore(b) - readinessScore(a),
    status:       (a, b) => Number(!!a.notifications_sent) - Number(!!b.notifications_sent),
  };
  const sorted = visible.sort(sorters[meetingSort] || sorters.session_date);

  const isAdmin = isAdminOrAbove();

  container.innerHTML = sorted.map(m => {
    // Manual items — staff toggles these directly.
    const manualChecklist = [
      { key: "venue_booked", label: "Venue booked", val: !!m.venue_booked },
      { key: "documents_distributed", label: "Documents distributed to members", val: !!m.documents_distributed },
      { key: "minutes_status", label: "Minutes finalized", val: m.minutes_status === "Finalized" },
    ];
    // Auto items — sourced from the (mocked) peer system's confirmation.
    const autoChecklist = [
      { label: "Councilors (attendees) confirmed", val: !!m.attendees_confirmed },
      { label: "Agenda materials ready", val: !!m.agenda_confirmed },
    ];
    const allItems = [...manualChecklist, ...autoChecklist];
    const doneCount = allItems.filter(c => c.val).length;
    const readyToSend = allItems.every(c => c.val);
    const missing = allItems.filter(c => !c.val).map(c => c.label);

    const confirmationBadge = (m.attendees_confirmed && m.agenda_confirmed)
      ? `<span class="pill pill-forest"><i class="fa-solid fa-circle-check mr-1"></i>Schedule confirmed by Session Mgmt System</span>`
      : `<span class="pill pill-brass"><i class="fa-regular fa-hourglass-half mr-1"></i>Awaiting external confirmation (${[!m.attendees_confirmed && "councilors", !m.agenda_confirmed && "agenda"].filter(Boolean).join(", ")})</span>`;

    let sendButton;
    if (m.notifications_sent) {
      sendButton = `<p class="text-xs text-forest-700"><i class="fa-solid fa-check-double mr-1"></i>Sent by ${m.notifications_sent_by || "—"} on ${new Date(m.notifications_sent_at).toLocaleString()}</p>`
        + (isAdmin ? `<button data-undo-notifications="${m.session_id}" class="text-[11px] text-maroon-700 hover:underline mt-1"><i class="fa-solid fa-rotate-left mr-0.5"></i>Undo (mistaken send)</button>` : "");
    } else if (readyToSend && isAdmin) {
      sendButton = `<button data-send="${m.session_id}" class="btn-primary text-xs !py-1.5">
           <i class="fa-solid fa-paper-plane mr-1"></i>Send notifications
         </button>`;
    } else if (readyToSend) {
      sendButton = `<p class="text-[11px] text-forest-700"><i class="fa-solid fa-circle-check mr-1"></i>Checklist complete — waiting for an admin to send notifications.</p>`;
    } else {
      sendButton = `<p class="text-[11px] text-slate-400"><i class="fa-regular fa-circle-question mr-1"></i>Complete the checklist to notify councilors: still needs ${missing.join(", ")}.</p>`;
    }

    // Once notifications are sent, the checklist state that justified that
    // action shouldn't be silently changeable afterward.
    const locked = m.notifications_sent;
    const sessionOver = m.status === "Completed" || m.status === "Cancelled";

    return `
      <div class="dossier-card p-5">
        <div class="flex items-start justify-between flex-wrap gap-3">
          <div>
            <div class="flex items-center gap-2 flex-wrap mb-1">
              <span class="font-mono text-xs text-slate-500">${fmtDate(m.session_date)} &middot; ${m.session_time}</span>
              <span class="pill pill-slate">${m.status}</span>
            </div>
            <h3 class="font-display text-base text-ink-900">${m.session_type}</h3>
            <p class="text-xs text-slate-500 mt-0.5"><i class="fa-solid fa-location-dot mr-1"></i>${m.venue}</p>
          </div>
          <span class="pill ${doneCount === allItems.length ? "pill-forest" : "pill-brass"}">${doneCount} of ${allItems.length} ready</span>
        </div>

        ${(m.agenda_items || []).length ? `
          <div class="mt-3 space-y-1.5">
            ${m.agenda_items.map(item => `
              <div class="flex items-center justify-between gap-2 text-xs bg-paper-100/50 rounded px-2 py-1.5">
                <span><span class="font-mono text-slate-500">${item.id}</span> ${item.title}</span>
                <div class="flex items-center gap-2 shrink-0">${priorityPill(item.confirmed_priority)}${renderReadingProgress(item.readings)}</div>
              </div>
            `).join("")}
          </div>
        ` : `<p class="text-xs text-slate-400 mt-2">No agenda items attached to this session.</p>`}

        <div class="mt-3 flex items-center gap-3 flex-wrap">
          ${confirmationBadge}
          <button data-show-events="${m.session_id}" class="text-xs text-slate-500 hover:text-ink-800 underline"><i class="fa-solid fa-list mr-1"></i>View integration exchange</button>
        </div>
        <div id="events-${m.session_id}" class="hidden mt-2 dossier-card p-3 space-y-1.5"></div>

        <div class="grid sm:grid-cols-2 gap-4 mt-4">
          <div>
            <h4 class="text-xs font-semibold text-slate-600 mb-2">Logistics checklist <span class="font-normal text-slate-400">(staff-managed${locked ? " — locked, notifications already sent" : ""})</span></h4>
            <div class="space-y-1.5">
              ${manualChecklist.map(c => `
                <label class="flex items-center gap-2 text-sm ${locked ? "text-slate-400" : "text-ink-800"}">
                  <input type="checkbox" data-session="${m.session_id}" data-key="${c.key}" ${c.val ? "checked" : ""} ${locked ? "disabled" : ""} class="rounded border-[--line-200]" />
                  ${c.label}
                </label>
              `).join("")}
            </div>
            <h4 class="text-xs font-semibold text-slate-600 mb-2 mt-4">From Session Mgmt System <span class="font-normal text-slate-400">(auto, read-only)</span></h4>
            <div class="space-y-1.5">
              ${autoChecklist.map(c => `
                <div class="flex items-center gap-2 text-sm text-ink-800">
                  <i class="fa-solid ${c.val ? "fa-square-check text-forest-600" : "fa-square text-slate-300"}"></i>
                  ${c.label}
                </div>
              `).join("")}
              <div class="text-xs text-slate-500 flex items-center gap-2 mt-1">
                <i class="fa-solid fa-users w-4 text-center text-slate-400"></i>
                ${m.attendance_confirmed_text || "Not tracked"}
              </div>
            </div>
          </div>
          <div>
            <h4 class="text-xs font-semibold text-slate-600 mb-2">Who gets notified</h4>
            <div class="space-y-1.5 mb-2">
              ${(m.stakeholders || []).length ? m.stakeholders.map(sh => `
                <div class="flex items-center justify-between gap-2 text-xs bg-paper-100/50 rounded px-2 py-1.5">
                  <span class="min-w-0 truncate">
                    ${sh.user_id ? `<i class="fa-solid fa-user-check text-[10px] text-forest-700 mr-1" title="Linked to a registered account"></i>` : `<i class="fa-regular fa-envelope text-[10px] text-slate-400 mr-1" title="External contact"></i>`}
                    ${sh.name} <span class="text-slate-400">${sh.email || "(no email)"}</span>
                  </span>
                  <span class="flex items-center gap-2 shrink-0">
                    ${isAdmin && sh.email && !sessionOver ? `<button data-notify-stakeholder="${sh.id}" class="text-ink-700 hover:underline" title="Email this person the session details now"><i class="fa-solid fa-paper-plane"></i></button>` : ""}
                    ${isAdmin ? `<button data-remove-stakeholder="${sh.id}" class="text-slate-400 hover:text-maroon-700" title="Remove from this meeting's list"><i class="fa-solid fa-xmark"></i></button>` : ""}
                  </span>
                </div>
              `).join("") : `<span class="text-xs text-slate-400">Nobody on the list yet — add someone below.</span>`}
            </div>
            ${isAdmin ? `<button data-add-stakeholder="${m.meeting_id}" class="text-[11px] text-ink-700 hover:underline mb-3 inline-block"><i class="fa-solid fa-plus mr-0.5"></i>Add someone</button>` : ""}
            <div id="add-stakeholder-panel-${m.meeting_id}" class="hidden dossier-card p-3 mb-3"></div>
            ${sendButton}
          </div>
        </div>
      </div>
    `;
  }).join("") || `<p class="text-sm text-slate-400 text-center py-10">No meetings yet.</p>`;

  document.querySelectorAll("[data-notify-stakeholder]").forEach(btn => {
    btn.addEventListener("click", async (e) => {
      e.stopPropagation();
      const original = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i>`;
      try {
        const result = await window.API.post("api/meetings/notify-stakeholder.php", { id: parseInt(btn.dataset.notifyStakeholder, 10) });
        alert(result.message);
      } catch (err) {
        alert(`Could not send: ${err.message}`);
      } finally {
        btn.disabled = false;
        btn.innerHTML = original;
      }
    });
  });

  document.querySelectorAll("[data-remove-stakeholder]").forEach(btn => {
    btn.addEventListener("click", async (e) => {
      e.stopPropagation();
      if (!confirm("Remove this person from the notification list for this meeting?")) return;
      try {
        await window.API.post("api/meetings/remove-stakeholder.php", { id: btn.dataset.removeStakeholder });
        renderMeetingModule();
      } catch (err) {
        alert(`Could not remove: ${err.message}`);
      }
    });
  });

  document.querySelectorAll("[data-add-stakeholder]").forEach(btn => {
    btn.addEventListener("click", () => openAddStakeholderPanel(btn.dataset.addStakeholder));
  });

  document.querySelectorAll("#meeting-list input[type=checkbox][data-session]").forEach(cb => {
    cb.addEventListener("change", async () => {
      const payload = { session_id: cb.dataset.session };
      payload[cb.dataset.key] = cb.dataset.key === "minutes_status" ? (cb.checked ? "Finalized" : "Draft") : cb.checked;
      try {
        await window.API.post("api/meetings/update-checklist.php", payload);
        renderMeetingModule();
      } catch (err) {
        alert(`Could not save: ${err.message}`);
        cb.checked = !cb.checked;
      }
    });
  });

  document.querySelectorAll("[data-undo-notifications]").forEach(btn => {
    btn.addEventListener("click", () => {
      const sessionId = btn.dataset.undoNotifications;
      openReasonModal({
        title: "Undo notifications for this meeting?",
        description: "This resets the system's record so the checklist unlocks and the Send button can reappear. It does not recall any email already delivered.",
        confirmLabel: "Undo",
        onConfirm: async (reason) => {
          await window.API.post("api/meetings/undo-notifications.php", { session_id: sessionId, reason });
          renderMeetingModule();
        },
      });
    });
  });

  document.querySelectorAll("[data-send]").forEach(btn => {
    btn.addEventListener("click", async () => {
      btn.disabled = true;
      btn.textContent = "Sending…";
      try {
        const result = await window.API.post("api/meetings/send-notifications.php", { session_id: btn.dataset.send });
        let msg = `Sent to: ${result.sent_to.join(", ") || "none"}.`;
        if (result.failed?.length) msg += `\n\nFailed: ${result.failed.join("; ")}`;
        if (result.skipped_no_email?.length) msg += `\n\nSkipped (no email on file): ${result.skipped_no_email.join(", ")}`;
        alert(msg);
        renderMeetingModule();
      } catch (err) {
        alert(`Could not send: ${err.message}`);
        renderMeetingModule();
      }
    });
  });

  document.querySelectorAll("[data-show-events]").forEach(btn => {
    btn.addEventListener("click", async () => {
      const sessionId = btn.dataset.showEvents;
      const panel = document.getElementById(`events-${sessionId}`);
      const opening = panel.classList.contains("hidden");
      panel.classList.toggle("hidden");
      if (!opening) return;

      panel.innerHTML = `<p class="text-xs text-slate-400">Loading…</p>`;
      try {
        const events = await window.API.get(`api/integration/events.php?session_id=${sessionId}`);
        panel.innerHTML = events.length
          ? events.map(renderEventRow).join("")
          : `<p class="text-xs text-slate-400">No integration events for this session yet.</p>`;
      } catch (err) {
        panel.innerHTML = `<p class="text-xs text-maroon-700">Could not load: ${err.message}</p>`;
      }
    });
  });
}

/** Inline panel offering two ways to add a recipient: pick a registered user
 * (name and email pulled live from their account, nothing hand-typed), or add
 * an external contact who isn't a system user. */
async function openAddStakeholderPanel(meetingId) {
  const panel = document.getElementById(`add-stakeholder-panel-${meetingId}`);
  const opening = panel.classList.contains("hidden");
  panel.classList.toggle("hidden");
  if (!opening) return;

  panel.innerHTML = `<p class="text-xs text-slate-400">Loading…</p>`;
  let users = [];
  try {
    users = await window.API.get("api/users/list-active.php");
  } catch (err) {
    // Non-fatal — the external-contact path still works without this.
  }

  panel.innerHTML = `
    <div class="space-y-3">
      <div>
        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Add a registered user</label>
        <div class="flex gap-2">
          <select id="stakeholder-user-select-${meetingId}" class="flex-1 border border-[--line-200] rounded-lg px-2 py-1.5 text-xs">
            <option value="">Select a staff/admin account…</option>
            ${users.map(u => `<option value="${u.id}">${u.full_name}</option>`).join("")}
          </select>
          <button data-add-user-stakeholder="${meetingId}" class="btn-primary text-xs !py-1.5">Add</button>
        </div>
      </div>
      <div class="border-t border-[--line-200] pt-3">
        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Or add an external contact <span class="font-normal text-slate-400">(not a system user — a councilor's own email, the Mayor's office)</span></label>
        <div class="grid grid-cols-2 gap-2">
          <input id="stakeholder-ext-name-${meetingId}" placeholder="Name" class="border border-[--line-200] rounded-lg px-2 py-1.5 text-xs" />
          <input id="stakeholder-ext-email-${meetingId}" type="email" placeholder="Email" class="border border-[--line-200] rounded-lg px-2 py-1.5 text-xs" />
        </div>
        <button data-add-external-stakeholder="${meetingId}" class="btn-outline text-xs !py-1.5 mt-2">Add external contact</button>
      </div>
      <p class="text-[11px] text-slate-400">If this meeting's notifications already went out, whoever you add here is emailed the session details straight away.</p>
      <p id="add-stakeholder-error-${meetingId}" class="hidden text-xs text-maroon-700"></p>
    </div>
  `;

  document.querySelector(`[data-add-user-stakeholder="${meetingId}"]`).addEventListener("click", async () => {
    const userId = document.getElementById(`stakeholder-user-select-${meetingId}`).value;
    if (!userId) return;
    await submitAddStakeholder(meetingId, { user_id: userId });
  });

  document.querySelector(`[data-add-external-stakeholder="${meetingId}"]`).addEventListener("click", async () => {
    const name = document.getElementById(`stakeholder-ext-name-${meetingId}`).value.trim();
    const email = document.getElementById(`stakeholder-ext-email-${meetingId}`).value.trim();
    await submitAddStakeholder(meetingId, { name, email });
  });
}

async function submitAddStakeholder(meetingId, payload) {
  const errorEl = document.getElementById(`add-stakeholder-error-${meetingId}`);
  errorEl.classList.add("hidden");
  try {
    const result = await window.API.post("api/meetings/add-stakeholder.php", { meeting_id: meetingId, ...payload });
    if (result.message) alert(result.message);
    renderMeetingModule();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove("hidden");
  }
}

document.addEventListener("DOMContentLoaded", () => {
  renderMeetingModule();
  document.getElementById("meeting-sort-select")?.addEventListener("change", e => {
    meetingSort = e.target.value;
    renderMeetingModule();
  });
});
